<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\EmbeddedTagsQuery;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

/**
 * Cluster backend for embedded (EXIF/XMP) hierarchical tags.
 *
 * Unlike the other backends, clusters here are keyed by a full tag path
 * string (e.g. "Family/Vacation/Beach") rather than a numeric/object id,
 * since there's no per-photo join table -- membership is determined by
 * inspecting the photo's own EXIF Keywords/Subject/TagsList/HierarchicalSubject
 * fields. See EmbeddedTagsController::sub() for the hierarchical browsing
 * endpoint used by the folder-style "Tags" view.
 */
final class EmbeddedTagsBackend extends Backend
{
    public function __construct(
        protected TimelineQuery $tq,
        protected EmbeddedTagsQuery $etq,
        protected TimelineWrite $tw,
        protected IRequest $request,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Memories';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return 'embeddedtag';
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return true;
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        // Only a SQL-level *pre-filtering* optimization (MySQL/MariaDB and
        // PostgreSQL, per shouldFilterExifBySQL()) -- see applySQLPathMatch()
        // for why it's coarse, not exact. On every other DB this is a
        // deliberate no-op: matching instead happens entirely in PHP, in
        // TimelineQueryDays::getDay() (see the embeddedtagpath handling
        // there), which is the only place both the days-list and the actual
        // per-day photo fetch both go through -- and which also serves as the
        // authoritative recheck for the coarse SQL pre-filter here, so an
        // over-inclusive (never under-inclusive) SQL condition is safe.
        if (!$this->tq->shouldFilterExifBySQL()) {
            return;
        }

        // Manager only invokes this when the 'embeddedtag' request param is truthy,
        // i.e. this view is active. The path itself is carried in a separate param
        // so that an empty (root) path still counts as "active" here -- the root
        // has no directly-associated photos (only child tags do), so it must match
        // nothing rather than being treated as "no filter".
        $path = trim((string) $this->request->getParam('embeddedtagpath'));
        $this->applySQLPathMatch($query, $path);
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('EmbeddedTagsBackend: fileid filter not implemented');
        }

        $leaves = $this->etq->getLeafTagPaths();
        if (empty($leaves)) {
            return [];
        }

        // Lookup from lowercased path -> tag row, so every photo's embedded
        // tags can be matched against all leaves in one pass below instead
        // of running one getPhotos() scan of the whole account per leaf
        // (previously N+1 full table scans for N tags).
        $byPath = [];
        foreach ($leaves as $tag) {
            $byPath[strtolower($tag['path'])] = $tag;
        }

        $buckets = $this->countLeavesByPath($byPath, $this->wholeAccountRoot());

        // A tag whose only matches are all in an excluded folder isn't really
        // "empty" (deleting it would just have it silently reappear next time
        // those photos are reindexed, same as hasAnyPhotos() below) -- it's
        // just hidden by choice. Only tags with truly zero matches anywhere,
        // excluded folders included, should show up as the deletable/empty
        // placeholders the rest of this app expects; matches everywhere else
        // are simply left out of the list rather than shown as a fake 0.
        $trueBuckets = null;

        $list = [];
        foreach ($byPath as $key => $tag) {
            if (isset($buckets[$key])) {
                $list[] = [
                    'id' => (int) $tag['id'],
                    'name' => $tag['path'],
                    'count' => $buckets[$key]['count'],
                    'cover' => $buckets[$key]['cover'],
                    'cover_etag' => $buckets[$key]['cover_etag'],
                ];

                continue;
            }

            // Only computed once, and only if some tag actually needs it.
            $trueBuckets ??= $this->countLeavesByPath($byPath, $this->wholeAccountRoot(false));

            if (!isset($trueBuckets[$key])) {
                $list[] = [
                    'id' => (int) $tag['id'],
                    'name' => $tag['path'],
                    'count' => 0,
                    'cover' => null,
                    'cover_etag' => null,
                ];
            }
        }

        return $list;
    }

    /**
     * Count matches and pick a cover for each of the given (lowercased path
     * => tag row) leaf entries, scoped to $root, in a single pass over the
     * account's indexed photos.
     *
     * @param array<string, array{id: int, path: string}> $byPath lowercased path => tag row
     *
     * @return array<string, array{count: int, cover: int, cover_etag: string}> lowercased path => bucket
     */
    private function countLeavesByPath(array $byPath, TimelineRoot $root): array
    {
        $buckets = [];

        foreach ($this->getAllExifRows($root) as $row) {
            if (empty($row['exif'])) {
                continue;
            }

            $exif = json_decode((string) $row['exif'], true);
            if (!\is_array($exif)) {
                continue;
            }

            foreach (Exif::extractEmbeddedTags($exif, true) as $tagPath) {
                $key = strtolower($tagPath);
                if (!isset($byPath[$key])) {
                    continue;
                }

                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'count' => 0,
                        'cover' => (int) $row['fileid'],
                        'cover_etag' => $row['etag'],
                    ];
                }

                ++$buckets[$key]['count'];
            }
        }

        return $buckets;
    }

    #[\Override]
    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['name'];
    }

    #[\Override]
    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        // Manual cover selection isn't meaningfully supported for embedded tags
        // (there's no natural single "cluster id" per photo to persist a cover
        // against, since a photo can match several tag paths at once). There is
        // therefore never a stored cover -- let ClustersController fall back to
        // a random preview instead of scanning for one that will never exist.
        if (-6 === $limit) {
            return [];
        }

        // Unlike Folders, a tag isn't tied to any one location: a photo
        // anywhere in the account can carry it, and the tag dictionary itself
        // is built from every indexed photo regardless of the user's
        // configured timeline path (see whole-account root note below). So
        // browsing a tag must search the whole account too, or photos (and
        // even the tag's own previews) outside the timeline path would look
        // like they don't exist even though the tag genuinely isn't empty.
        $root = $this->wholeAccountRoot();

        if ($this->tq->shouldFilterExifBySQL()) {
            return $this->getPhotosSQL($name, $limit, $fileid, false, $root);
        }

        return $this->getPhotosPHP($name, $limit, $fileid, false, $root);
    }

    /**
     * Compute the photo count and up to 4 cover previews for each of the
     * given tag paths in a single pass over the account's indexed photos,
     * instead of one whole-account getPhotos() scan per path (previously
     * N+1 -- see getClustersInternal() above for the same fix applied to
     * the flat cluster listing). Matching is recursive per path, same as
     * getPhotos().
     *
     * A path whose only matches are all in an excluded folder is left out of
     * the returned map entirely (not included as a fake 0) -- same reasoning
     * as getClustersInternal(): it isn't really empty, just hidden by choice,
     * so it shouldn't show up as the deletable/empty placeholder the rest of
     * this app reserves for tags with truly zero matches. Callers should skip
     * any requested path missing from the result rather than assume count 0.
     *
     * @param string[] $paths
     *
     * @return array<string, array{count: int, previews: array<array{fileid: int, etag: string}>}> keyed by (a subset of) $paths entries
     */
    public function getPhotoStatsForPaths(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        $stats = $this->countPathsMatches($paths, $this->wholeAccountRoot());

        // Only computed if some path came back with zero visible matches --
        // need to tell "truly empty" apart from "hidden by exclusion" for those.
        $trueStats = null;

        foreach ($paths as $path) {
            if (0 !== $stats[$path]['count']) {
                continue;
            }

            $trueStats ??= $this->countPathsMatches($paths, $this->wholeAccountRoot(false));

            if (0 !== $trueStats[$path]['count']) {
                unset($stats[$path]); // hidden by exclusion, not truly empty
            }
        }

        return $stats;
    }

    /**
     * Count matches and collect up to 4 previews for each of the given tag
     * paths, scoped to $root, in a single pass over the account's indexed
     * photos. Matching is recursive per path, same as getPhotos().
     *
     * @param string[] $paths
     *
     * @return array<string, array{count: int, previews: array<array{fileid: int, etag: string}>}> keyed by $paths entries
     */
    private function countPathsMatches(array $paths, TimelineRoot $root): array
    {
        $stats = [];
        foreach ($paths as $path) {
            $stats[$path] = ['count' => 0, 'previews' => []];
        }

        foreach ($this->getAllExifRows($root) as $row) {
            if (empty($row['exif'])) {
                continue;
            }

            $exif = json_decode((string) $row['exif'], true);
            if (!\is_array($exif)) {
                continue;
            }

            $tagPaths = Exif::extractEmbeddedTags($exif, true);

            foreach ($paths as $path) {
                foreach ($tagPaths as $tagPath) {
                    if (!Exif::embeddedTagPathMatches($tagPath, $path, true)) {
                        continue;
                    }

                    ++$stats[$path]['count'];
                    if (\count($stats[$path]['previews']) < 4) {
                        $stats[$path]['previews'][] = ['fileid' => (int) $row['fileid'], 'etag' => $row['etag']];
                    }

                    break; // don't double-count this photo for this path
                }
            }
        }

        return $stats;
    }

    /**
     * Whether any photo -- including archived/hidden and excluded-folder ones
     * -- is currently tagged with this path or any of its sub-tags. Used to
     * decide whether a tag is safe to permanently delete: if this returns
     * true, deleting the tag's dictionary entry would just have it silently
     * reappear the next time those photos get (re-)indexed, since deleting
     * never touches photo EXIF data itself. Deliberately ignores the user's
     * folder exclusions (ie. the TRUE scope, not what's displayed) -- a tag
     * that's merely hidden by an exclusion setting is not safe to delete.
     */
    public function hasAnyPhotos(string $path): bool
    {
        $root = $this->wholeAccountRoot(false);

        if ($this->tq->shouldFilterExifBySQL()) {
            return !empty($this->getPhotosSQL($path, 1, null, true, $root));
        }

        return !empty($this->getPhotosPHP($path, 1, null, true, $root));
    }

    /**
     * Delete every tag (and its whole subtree) that currently has no photos,
     * for the current user. Processes root-first so that once a subtree is
     * confirmed empty it's deleted (and its descendants skipped) in one go,
     * instead of re-checking each descendant individually.
     *
     * @return int number of tag dictionary entries deleted
     */
    public function pruneEmptyTags(): int
    {
        $uid = Util::getUID();
        $tags = $this->etq->getTagsFlat();

        // Order shallowest-first so an empty ancestor is deleted (recursively,
        // taking its descendants with it) before its descendants are visited
        usort($tags, static fn ($a, $b) => $a['level'] <=> $b['level']);

        $deleted = 0;
        $prunedPrefixes = [];

        foreach ($tags as $tag) {
            $path = $tag['path'];

            // Skip anything already removed as part of an ancestor's subtree
            foreach ($prunedPrefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    continue 2;
                }
            }

            if (!$this->hasAnyPhotos($path)) {
                $this->tw->deleteTagByPath($uid, $path, true);
                $prunedPrefixes[] = $path;
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Scan every indexed photo in the whole account (including archived/
     * hidden and excluded-folder ones) and ensure any embedded tag found in
     * its stored EXIF data exists in the current user's tag dictionary. This
     * never reads files from disk -- it only reuses the EXIF already cached
     * in the `memories` table -- so it's cheap to run on demand to repair a
     * dictionary that has drifted out of sync with the photos' actual tags
     * (e.g. tags added to a migration that ran before the dictionary table
     * existed). Deliberately ignores folder exclusions (the TRUE scope): the
     * dictionary should reflect what's actually indexed, not what's currently
     * displayed -- an excluded photo's tags are still real and should still
     * exist in the dictionary, just hidden from view like the photo itself.
     *
     * @return int number of new tag dictionary entries created
     */
    public function findMissingTags(): int
    {
        $uid = Util::getUID();
        $before = $this->etq->getTagsCount();

        $query = $this->tq->getBuilder();
        $query->select('m.exif')->from('memories', 'm');
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));
        $query = $this->tq->filterFilecache($query, $this->wholeAccountRoot(false), true, false, true);

        $rows = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        foreach ($rows as $row) {
            if (empty($row['exif'])) {
                continue;
            }

            $exif = json_decode((string) $row['exif'], true);
            if (!\is_array($exif)) {
                continue;
            }

            $this->tw->processEmbeddedTagsForUser($uid, $exif);
        }

        return $this->etq->getTagsCount() - $before;
    }

    /**
     * Fetch every indexed photo's fileid, etag and stored EXIF once for the
     * given root. Used to compute counts/covers for all leaf tags in a single
     * pass instead of one query per tag.
     */
    private function getAllExifRows(TimelineRoot $root): array
    {
        $query = $this->tq->getBuilder();
        $query->select('m.fileid', 'm.exif', 'f.etag')->from('memories', 'm');
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));
        $query = $this->tq->filterFilecache($query, $root, true, false, false);

        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    /**
     * Root at the user's whole file space (like "All Photos" mode), not just
     * their configured timeline path. The tag dictionary is built from every
     * photo the user has ever indexed, regardless of which folder it's in or
     * whether it's archived/hidden -- indexing doesn't care about those
     * view-level preferences -- so every embedded-tag query needs to search
     * the same scope, or it'll report tags as empty/unused that are still
     * very much in use just outside the configured timeline path.
     *
     * @param bool $applyExclusions Whether to apply the user's configured
     *                              folder exclusions (Settings > "All Photos:
     *                              Excluded Paths"), the same way
     *                              FsManager::populateRoot() does for every
     *                              other recursive view -- this root is built
     *                              by hand instead of going through
     *                              populateRoot(), so it doesn't get that for
     *                              free. Default true for what gets displayed
     *                              (counts/previews/browsing a tag) -- an
     *                              excluded photo shouldn't count or show up
     *                              as a preview. Pass false for the TRUE
     *                              scope: dictionary-integrity checks
     *                              (hasAnyPhotos()/findMissingTags()) must
     *                              ignore exclusions, since a tag that's
     *                              merely hidden by a view preference is not
     *                              actually unused and isn't safe to prune.
     */
    private function wholeAccountRoot(bool $applyExclusions = true): TimelineRoot
    {
        $root = new TimelineRoot();
        $root->addFolder(Util::getUserFolder());
        $root->addMountPoints();

        if ($applyExclusions) {
            $root->setExcludedFolderIds(Util::resolveExcludedFolderIds(Util::getUID(), Util::getUserFolder()));
        }

        return $root;
    }

    /**
     * SQL fast path (MySQL/MariaDB and PostgreSQL): use applySQLPathMatch()'s
     * coarse pre-filter to narrow the candidate set at the DB level, then
     * hand off to filterCandidatesByPath() for the authoritative PHP
     * recheck -- same verification getPhotosPHP() does over the whole
     * account, just over a much smaller pre-narrowed candidate set. Unlike
     * TimelineQueryDays::getDay(), nothing downstream of this method
     * re-verifies embedded tags, so this method must return exact results
     * itself; there is deliberately no SQL-level LIMIT here (see
     * filterCandidatesByPath()).
     *
     * @param bool          $hidden Whether to also include archived/hidden photos
     *                              (normal browsing excludes them; the tag-deletion
     *                              safety check must not, see hasAnyPhotos())
     * @param ?TimelineRoot $root   Explicit root to scope the search to, instead of
     *                              the request-driven default (the user's configured
     *                              timeline path); see hasAnyPhotos()
     */
    private function getPhotosSQL(string $name, ?int $limit, ?int $fileid, bool $hidden = false, ?TimelineRoot $root = null): array
    {
        if ('' === $name) {
            // Root has no directly-associated photos -- only child tags do.
            return [];
        }

        $query = $this->tq->getBuilder();

        // SELECT candidate photos coarsely matching this tag path (always
        // recursive: a tag's previews/cover/download represent its whole
        // subtree, same as a folder's) -- exact-vs-recursive matching of the
        // full path happens below in filterCandidatesByPath(), not in SQL.
        $query->select('m.fileid', 'm.exif', 'f.etag')->from('memories', 'm');
        $this->applySQLPathMatch($query, $name);

        // JOIN with the filecache table
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query, $root, true, false, $hidden);

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // No SQL-level LIMIT: applySQLPathMatch()'s pre-filter is coarse and
        // may include false positives that filterCandidatesByPath() below
        // will drop, so limiting the SQL result here could return fewer than
        // $limit true matches even when more exist among the candidates.
        $rows = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        return $this->filterCandidatesByPath($rows, $name, true, $limit);
    }

    /**
     * Fallback for DBs without SQL-level EXIF JSON support (SQLite, Oracle,
     * ...; see TimelineQuery::shouldFilterExifBySQL()): fetch every candidate
     * photo in scope (unfiltered -- there's no coarse SQL pre-filter to
     * narrow the set on these DBs) and hand off to the same authoritative
     * PHP recheck getPhotosSQL() uses over its smaller, pre-narrowed set.
     *
     * @param bool          $hidden Whether to also include archived/hidden photos
     *                              (normal browsing excludes them; the tag-deletion
     *                              safety check must not, see hasAnyPhotos())
     * @param ?TimelineRoot $root   Explicit root to scope the search to, instead of
     *                              the request-driven default (the user's configured
     *                              timeline path); see hasAnyPhotos()
     */
    private function getPhotosPHP(string $name, ?int $limit, ?int $fileid, bool $hidden = false, ?TimelineRoot $root = null): array
    {
        if ('' === $name) {
            // Root has no directly-associated photos -- only child tags do.
            return [];
        }

        $query = $this->tq->getBuilder();
        $query->select('m.fileid', 'm.exif', 'f.etag')->from('memories', 'm');
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));
        $query = $this->tq->filterFilecache($query, $root, true, false, $hidden);

        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        $rows = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        return $this->filterCandidatesByPath($rows, $name, true, $limit);
    }

    /**
     * Authoritative recheck: given a set of candidate rows (each with
     * fileid/exif/etag), keep only those whose real parsed embedded tags
     * (via Exif::extractEmbeddedTags()) match $path (via
     * Exif::embeddedTagPathMatches()), up to $limit matches. Shared by
     * getPhotosSQL() (candidates coarsely pre-filtered in SQL) and
     * getPhotosPHP() (candidates are the whole unfiltered scope).
     *
     * @param array<array{fileid:int|string, exif:?string, etag:string}> $rows
     *
     * @return array<array{fileid:int, etag:string}>
     */
    private function filterCandidatesByPath(array $rows, string $path, bool $recursive, ?int $limit): array
    {
        $matches = [];
        foreach ($rows as $row) {
            if (empty($row['exif'])) {
                continue;
            }

            $exif = json_decode($row['exif'], true);
            if (!\is_array($exif)) {
                continue;
            }

            foreach (Exif::extractEmbeddedTags($exif, true) as $tagPath) {
                if (Exif::embeddedTagPathMatches($tagPath, $path, $recursive)) {
                    $matches[] = ['fileid' => (int) $row['fileid'], 'etag' => $row['etag']];

                    break;
                }
            }

            if (null !== $limit && \count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Apply a coarse, superset-safe WHERE-clause pre-filter for photos whose
     * embedded tags might be at or under $path. Only called on the SQL fast
     * path (MySQL/MariaDB and PostgreSQL, per
     * TimelineQuery::shouldFilterExifBySQL()); see getPhotosPHP() for the
     * PHP-only fallback used on every other database.
     *
     * Deliberately searches only for $path's *last* segment as a raw
     * substring, not the full '/'-joined path, and applies no exact-vs-
     * recursive distinction here at all: Exif::extractEmbeddedTags() derives
     * the flattened '/'-joined tag path from a raw stored value that may use
     * a *different* separator (HierarchicalSubject uses '|', Keywords may
     * join several tags with ', '), so the full flattened path is not
     * guaranteed to appear verbatim in the stored EXIF text -- only a single,
     * unsplit path component is (path components are taken verbatim via
     * explode(), with no per-component transformation before being stored).
     * Exact-vs-recursive matching of the *complete* path is enforced
     * authoritatively afterward, against the real parsed tags: by
     * filterCandidatesByPath() for the direct getPhotos() call path (see
     * getPhotosSQL()), or by the existing embedded_tags/embeddedtag recheck
     * in TimelineQueryDays::getDay() / postProcessDaysWithFilters() for the
     * timeline transformDayQuery() call path.
     */
    private function applySQLPathMatch(IQueryBuilder &$query, string $path): void
    {
        if ('' === $path) {
            // Root has no directly-associated photos -- only child tags do.
            $query->andWhere('1 = 0');

            return;
        }

        $leaf = $path;
        if (false !== ($slashPos = strrpos($path, '/'))) {
            $leaf = substr($path, $slashPos + 1);
        }

        // Per-field decoded-text search (SQL::jsonExifFieldContainsCI()), not
        // a raw-blob substring search: PHP's json_encode() escapes '/' as
        // '\/' by default, so searching the raw `m.exif` JSON text directly
        // would miss a leaf that itself legitimately contains '/' (or any
        // other JSON-escaped character), even though it's exactly what the
        // decoded field value contains.
        $fields = ['Keywords', 'Subject', 'TagsList', 'HierarchicalSubject'];
        $or = $query->expr()->orX();

        foreach ($fields as $field) {
            $or->add(SQL::jsonExifFieldContainsCI($query, $field, ':etleaf'));
        }

        $query->andWhere($or);
        $query->setParameter('etleaf', $leaf, IQueryBuilder::PARAM_STR);
    }
}
