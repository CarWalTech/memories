<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\EmbeddedTagsQuery;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exif;
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
        // Only a SQL-level optimization on MySQL/MariaDB. On every other DB
        // this is a deliberate no-op: matching instead happens entirely in
        // PHP, in TimelineQuery::getDay() (see the embeddedtagpath handling
        // there), which is the only place both the days-list and the actual
        // per-day photo fetch both go through.
        if (!$this->tq->shouldFilterExifBySQL()) {
            return;
        }

        // Manager only invokes this when the 'embeddedtag' request param is truthy,
        // i.e. this view is active. The path itself is carried in a separate param
        // so that an empty (root) path still counts as "active" here -- the root
        // has no directly-associated photos (only child tags do), so it must match
        // nothing rather than being treated as "no filter".
        $path = trim((string) $this->request->getParam('embeddedtagpath'));
        $recursive = null !== $this->request->getParam('recursive');
        $this->applySQLPathMatch($query, $path, $recursive);
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('EmbeddedTagsBackend: fileid filter not implemented');
        }

        $list = [];
        foreach ($this->etq->getLeafTagPaths() as $tag) {
            // Fetch once and derive both the count and the cover from it,
            // instead of two separate (potentially PHP-side) full scans.
            $photos = $this->getPhotos($tag['path']);
            if (empty($photos)) {
                continue;
            }

            $list[] = [
                'id' => (int) $tag['id'],
                'name' => $tag['path'],
                'count' => \count($photos),
                'cover' => $photos[0]['fileid'] ?? null,
                'cover_etag' => $photos[0]['etag'] ?? null,
            ];
        }

        return $list;
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

        if ($this->tq->shouldFilterExifBySQL()) {
            return $this->getPhotosSQL($name, $limit, $fileid);
        }

        return $this->getPhotosPHP($name, $limit, $fileid);
    }

    /**
     * MySQL/MariaDB fast path: match photos via JSON_CONTAINS/JSON_SEARCH
     * directly in SQL.
     */
    private function getPhotosSQL(string $name, ?int $limit, ?int $fileid): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all photos matching this tag path (always recursive: a tag's
        // previews/cover/download represent its whole subtree, same as a folder's)
        $query->select('m.fileid', 'f.etag')->from('memories', 'm');
        $this->applySQLPathMatch($query, $name, true);

        // JOIN with the filecache table
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    /**
     * Fallback for DBs without JSON_CONTAINS/JSON_SEARCH (Postgres, SQLite, ...):
     * fetch candidate photos (scoped the same way as the SQL path) and match
     * each one's embedded tags in PHP, same approach as
     * TimelineQueryDays::postProcessDaysWithFilters() uses for the timeline.
     */
    private function getPhotosPHP(string $name, ?int $limit, ?int $fileid): array
    {
        if ('' === $name) {
            // Root has no directly-associated photos -- only child tags do.
            return [];
        }

        $query = $this->tq->getBuilder();
        $query->select('m.fileid', 'm.exif', 'f.etag')->from('memories', 'm');
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));
        $query = $this->tq->filterFilecache($query);

        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        $rows = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        $matches = [];
        foreach ($rows as $row) {
            if (empty($row['exif'])) {
                continue;
            }

            $exif = json_decode((string) $row['exif'], true);
            if (!\is_array($exif)) {
                continue;
            }

            foreach (Exif::extractEmbeddedTags($exif, true) as $tagPath) {
                if (Exif::embeddedTagPathMatches($tagPath, $name, true)) {
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
     * Apply a WHERE clause matching photos whose embedded tags are at
     * exactly $path, or (if $recursive) anywhere under $path. Only called
     * on the SQL fast path (MySQL/MariaDB); see getPhotosPHP() for the
     * fallback used on every other database.
     */
    private function applySQLPathMatch(IQueryBuilder &$query, string $path, bool $recursive): void
    {
        if ('' === $path) {
            // Root has no directly-associated photos -- only child tags do.
            $query->andWhere('1 = 0');

            return;
        }

        $fields = ['Keywords', 'Subject', 'TagsList', 'HierarchicalSubject'];
        $or = $query->expr()->orX();

        foreach ($fields as $field) {
            $or->add("JSON_CONTAINS(JSON_EXTRACT(m.exif, '$.{$field}'), JSON_QUOTE(:etpath))");

            if ($recursive) {
                $or->add("JSON_SEARCH(JSON_EXTRACT(m.exif, '$.{$field}'), 'one', :etprefix) IS NOT NULL");
            }
        }

        $query->andWhere($or);
        $query->setParameter('etpath', $path, IQueryBuilder::PARAM_STR);

        if ($recursive) {
            $query->setParameter('etprefix', $path.'/%', IQueryBuilder::PARAM_STR);
        }
    }
}
