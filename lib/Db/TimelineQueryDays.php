<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\ClustersBackend;
use OCA\Memories\Exif;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryDays
{
    use TimelineQueryCTE;

    protected IDBConnection $connection;

    /**
     * Get the days response from the database for the timeline.
     *
     * @param bool   $recursive       Whether to get the days recursively
     * @param bool   $archive         Whether to get the days only from the archive folder
     * @param string $groupMode       Bucket granularity: 'day' | 'week' | 'month' | 'year'
     * @param bool   $reverse         Whether the bucket order should be reversed (oldest-first)
     * @param array  $queryTransforms An array of query transforms to apply to the query
     *
     * @return array The days response
     */
    public function getDays(
        bool $recursive,
        bool $archive,
        string $groupMode,
        bool $reverse,
        array $queryTransforms = [],
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $query->select('m.dayid')
            ->selectAlias($query->func()->count(SQL::distinct($query, 'm.fileid')), 'count')
            ->from('memories', 'm')
        ;

        // Group and sort by dayid
        $query->addGroupBy('m.dayid')
            ->addOrderBy('m.dayid', 'DESC')
        ;

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, true);

        // FILTER with filecache for timeline path
        $query = $this->filterFilecache($query, null, $recursive, $archive);

        // FETCH all days
        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        // Apply post-filtering if SQL filtering is disabled
        if (!$this->shouldFilterExifBySQL()) {
            $rows = $this->postProcessDaysWithFilters($rows, $groupMode);
        } else {
            // Post process the days normally
            $rows = $this->postProcessDays($rows, $groupMode);
        }

        // Reverse order if needed
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Get the day response from the database for the timeline.
     *
     * @param int[]  $dayIds          The day ids to fetch (bucket ids when $groupMode !== 'day')
     * @param bool   $recursive       If the query should be recursive
     * @param bool   $archive         If the query should include only the archive folder
     * @param bool   $hidden          If the query should include hidden files
     * @param string $groupMode       Bucket granularity: 'day' | 'week' | 'month' | 'year'
     * @param string $sortKey         Sort column: 'created' | 'updated' | 'name'
     * @param bool   $reverse         If the photo order within each bucket should be reversed (ascending)
     * @param int    $minRating       The minimum rating to include
     * @param array  $queryTransforms The query transformations to apply
     *
     * @return array An array of day responses
     */
    public function getDay(
        array $dayIds,
        bool $recursive,
        bool $archive,
        bool $hidden,
        string $groupMode,
        string $sortKey,
        bool $reverse,
        int $minRating = 0,
        array $embeddedTags = [],
        array $queryTransforms = [],
    ): array {
        // Check if we have any dayIds
        if (empty($dayIds)) {
            return [];
        }

        // Make new query
        $query = $this->connection->getQueryBuilder();

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select(SQL::distinct($query, 'm.fileid'), ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
        ;

        // Add hidden field
        if ($hidden) {
            // we join with filecache anyway in this case, so just use the parent there
            // this means this will work directly in trigger compatibility mode
            $hSq = $this->connection->getQueryBuilder();
            $hSq->select($hSq->expr()->literal(1))
                ->from('cte_folders', 'cte_f')
                ->andWhere($hSq->expr()->eq('cte_f.fileid', 'f.parent'))
                ->andWhere($hSq->expr()->eq('cte_f.hidden', $hSq->expr()->literal(1)))
            ;
            $query->selectAlias(SQL::subquery($query, $hSq), 'hidden');
        }

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        if ('day' !== $groupMode) {
            // Convert bucket ids (week/month/year) to dayid ranges
            $query->andWhere($query->expr()->orX(...array_map(fn ($groupId) => $query->expr()->andX(
                $query->expr()->gte('m.dayid', $query->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)),
                $query->expr()->lte('m.dayid', $query->createNamedParameter($this->groupIdEnd($groupId, $groupMode), IQueryBuilder::PARAM_INT)),
            ), $dayIds)));
        } else {
            // Filter by list of dayIds
            $query->andWhere($query->expr()->in('m.dayid', $query->createNamedParameter($dayIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        // Add favorite field
        $this->addFavoriteTag($query);

        // Sort by the requested key, always DESC in SQL; ascending order is
        // achieved by reversing the (fully deterministic) result in PHP below,
        // so every sort key only needs a single query shape.
        $primaryCol = match ($sortKey) {
            'name' => 'basename',
            'updated' => 'f.mtime',
            default => 'm.datetaken', // 'created'
        };
        $query->addOrderBy($primaryCol, 'DESC');
        if ('basename' !== $primaryCol) {
            $query->addOrderBy('basename', 'DESC'); // https://github.com/pulsejet/memories/issues/985
        }
        $query->addOrderBy('m.fileid', 'DESC'); // unique tie-breaker

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, false);

        // JOIN with filecache to get the basename etc
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // Filter for files in the timeline path
        $query = $this->filterFilecache($query, null, $recursive, $archive, $hidden);

        // SELECT storage ID to check if this photo is shared
        // Do not expose storage to anonymous users (link shares)
        if (\OCA\Memories\Util::isLoggedIn()) {
            $query->leftJoin('f', 'storages', 's', $query->expr()->eq('f.storage', 's.numeric_id'));
            $query->selectAlias('s.id', 'storage_id');
        }

        // FETCH all photos in this day
        $day = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post process the day in-place
        foreach ($day as &$photo) {
            $this->postProcessDayPhoto($photo, $groupMode);
        }

        // Reverse order if needed
        if ($reverse) {
            $day = array_reverse($day);
        }

        // Filter by embedded tags (if not already filtered in SQL)
        if ($embeddedTags && \count($embeddedTags) > 0) {
            $day = array_filter($day, static fn ($photo) => \count(array_intersect($embeddedTags, $photo['embedded_tags'] ?? [])) > 0);
        }

        // Filter by embedded tag path (folder-style browsing view). Applied
        // unconditionally here (not just in postProcessDaysWithFilters()) since
        // this is the only place both the days-list preload and the actual
        // per-day photo fetch (DaysController::day()/dayGet()) both go through;
        // EmbeddedTagsBackend::transformDayQuery() only optimizes this at the
        // SQL level on MySQL/MariaDB, so every other DB relies solely on this.
        if (null !== $this->request->getParam('embeddedtag')) {
            $embeddedTagPath = trim((string) $this->request->getParam('embeddedtagpath'));
            $embeddedTagRecursive = null !== $this->request->getParam('recursive');

            $day = array_filter($day, static function ($photo) use ($embeddedTagPath, $embeddedTagRecursive) {
                if ('' === $embeddedTagPath) {
                    return false;
                }

                foreach ($photo['embedded_tags'] ?? [] as $tagPath) {
                    if (Exif::embeddedTagPathMatches($tagPath, $embeddedTagPath, $embeddedTagRecursive)) {
                        return true;
                    }
                }

                return false;
            });
        }

        // array_filter() preserves keys, so any filter above that drops a
        // non-trailing photo leaves gaps in the numeric keys. json_encode()
        // then serializes a gappy array as a JSON object instead of an array
        // (e.g. {"3":{...}} instead of [{...}]), which the frontend can't
        // iterate ("data is not iterable") -- reindex so this is always a
        // proper JSON array regardless of what got filtered out.
        return array_values($day);
    }

    public function executeQueryWithCTEs(IQueryBuilder $query, string $psql = ''): \OCP\DB\IResult
    {
        $sql = empty($psql) ? $query->getSQL() : $psql;
        $params = $query->getParameters();
        $types = $query->getParameterTypes();

        // Get SQL
        $CTE_SQL = \array_key_exists('cteFoldersArchive', $params)
            ? $this->CTE_FOLDERS_ARCHIVE()
            : $this->CTE_FOLDERS(\array_key_exists('cteIncludeHidden', $params), \array_key_exists('cteExcludeFolders', $params));

        // Add WITH clause if needed
        if (str_contains($sql, 'cte_folders')) {
            $sql = $CTE_SQL.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * Inner join with oc_filecache.
     *
     * @param IQueryBuilder $query     Query builder
     * @param TimelineRoot  $root      Either the top folder or null for all
     * @param bool          $recursive Whether to get the days recursively
     * @param bool          $archive   Whether to get the days only from the archive folder
     * @param bool          $hidden    Whether to include hidden files
     */
    public function filterFilecache(
        IQueryBuilder $query,
        ?TimelineRoot $root = null,
        bool $recursive = true,
        bool $archive = false,
        bool $hidden = false,
    ): IQueryBuilder {
        // Get the timeline root object
        if (null === $root) {
            // Cache the root object. This is fast when there are
            // multiple queries such as days-day preloading BUT that
            // means that any subsequent requests that don't match the
            // same root MUST specify a separate root this function
            if (null === $this->_root) {
                $this->_root = new TimelineRoot();

                // Populate the root using parameters from the request
                $fs = \OC::$server->get(FsManager::class);
                $fs->populateRoot($this->_root, $recursive);
            }

            // Use the cached / newly populated root
            $root = $this->_root;
        }

        if ($root->isEmpty()) {
            // This is illegal in most cases except albums,
            // which don't have a folder associated.
            if (!$this->_rootEmptyAllowed) {
                throw new \Exception('No valid root folder found (.nomedia?)');
            }

            // Nothing to do here
            return $query;
        }

        // Which field is the parent field for the record
        $parent = 'm.parent';

        // Check if triggers are properly set up
        if (!SystemConfig::get('memories.db.triggers.fcu')) {
            // Compatibility mode - JOIN filecache and use the parent from there (this is slow)
            $query->innerJoin('m', 'filecache', 'ff_f', $query->expr()->eq('m.fileid', 'ff_f.fileid'));
            $parent = 'ff_f.parent';
        }

        // Filter by folder (recursive or otherwise)
        if ($recursive) {
            // This are used later by the execution function
            $this->addSubfolderJoinParams($query, $root, $archive, $hidden);

            // Subquery to test parent folder
            $sq = $query->getConnection()->getQueryBuilder();
            $sq->select($sq->expr()->literal(1))
                ->from('cte_folders', 'cte_f')
                ->where($sq->expr()->eq($parent, 'cte_f.fileid'))
            ;

            // Filter files in one of the timeline folders
            $query->andWhere(SQL::exists($query, $sq));
        } else {
            // If getting non-recursively folder only check for parent
            $query->andWhere($query->expr()->eq($parent, $query->createNamedParameter($root->getOneId(), IQueryBuilder::PARAM_INT)));
        }

        return $query;
    }

    /**
     * Process the days response with EXIF filtering applied after SQL query.
     *
     * @param array  $rows      the days response from SQL
     * @param string $groupMode Bucket granularity: 'day' | 'week' | 'month' | 'year'
     */
    private function postProcessDaysWithFilters(array $rows, string $groupMode): array
    {
        if (empty($rows)) {
            return $rows;
        }

        // Get filter parameters from request
        $minRating = (int) $this->request->getParam('minRating') ?: 0;
        $embeddedTags = $this->getEmbeddedTagsFromRequest();
        $embeddedTagActive = null !== $this->request->getParam('embeddedtag');
        $embeddedTagPath = trim((string) $this->request->getParam('embeddedtagpath'));
        $embeddedTagRecursive = null !== $this->request->getParam('recursive');

        // Get day IDs for filtering
        $dayIds = array_map(static fn ($row) => (int) $row['dayid'], $rows);

        // Fetch all photos for these days (without EXIF filtering). $dayIds
        // here are real per-day ids (from the raw, ungrouped SQL rows above),
        // not bucket ids -- must fetch with groupMode 'day' so getDay() both
        // filters by the exact ids given (not a bucket range starting at each
        // one) and returns each photo's true raw dayid, matching $rows below.
        // Bucket folding happens once, afterward, in this same function.
        $allPhotos = $this->getDay(
            $dayIds,
            true,
            false,
            false,
            'day',
            'created', // sort key is irrelevant for a count-only re-fetch
            false,
            0, // minRating - don't apply in SQL, do in PHP
            [], // embeddedTags - don't apply in SQL, do in PHP
            [], // transforms
        );

        // Group photos by day
        $photosByDay = [];
        foreach ($allPhotos as $photo) {
            $dayId = (int) $photo['dayid'];
            if (!isset($photosByDay[$dayId])) {
                $photosByDay[$dayId] = [];
            }
            $photosByDay[$dayId][] = $photo;
        }

        // Apply PHP filtering and recount
        $filteredRows = [];
        foreach ($rows as $row) {
            $dayId = (int) $row['dayid'];
            $dayPhotos = $photosByDay[$dayId] ?? [];

            // Apply EXIF filters
            if ($minRating > 0) {
                $dayPhotos = array_filter($dayPhotos, static fn ($photo) => ($photo['rating'] ?? 0) >= $minRating);
            }

            if (!empty($embeddedTags)) {
                $dayPhotos = array_filter(
                    $dayPhotos,
                    static fn ($photo) => \count(array_intersect($embeddedTags, $photo['embedded_tags'] ?? [])) === \count($embeddedTags),
                );
            }

            if ($embeddedTagActive) {
                $dayPhotos = array_filter($dayPhotos, static function ($photo) use ($embeddedTagPath, $embeddedTagRecursive) {
                    // Root has no directly-associated photos -- only child tags do.
                    if ('' === $embeddedTagPath) {
                        return false;
                    }

                    foreach ($photo['embedded_tags'] ?? [] as $tagPath) {
                        if (Exif::embeddedTagPathMatches($tagPath, $embeddedTagPath, $embeddedTagRecursive)) {
                            return true;
                        }
                    }

                    return false;
                });
            }

            // Only include days with qualifying photos
            $filteredCount = \count($dayPhotos);
            if ($filteredCount > 0) {
                $filteredRows[] = [
                    'dayid' => $dayId,
                    'count' => $filteredCount,
                    // array_map() preserves the (possibly gappy, see getDay()) keys
                    // of $dayPhotos, which would make 'fileIds' serialize as a JSON
                    // object instead of an array if any filter above dropped a
                    // non-trailing photo -- reindex first so this is always an array.
                    'fileIds' => array_values(array_map(static fn ($photo) => $photo['fileid'], $dayPhotos)),
                ];
            }
        }

        // Convert to buckets if needed
        if ('day' !== $groupMode) {
            $filteredRows = array_values(array_reduce($filteredRows, function ($carry, $item) use ($groupMode) {
                $groupId = $this->dayIdToGroupId($item['dayid'], $groupMode);

                if (!\array_key_exists($groupId, $carry)) {
                    $carry[$groupId] = ['dayid' => $groupId, 'count' => 0];
                }

                $carry[$groupId]['count'] += $item['count'];

                return $carry;
            }, []));
        }

        return $filteredRows;
    }

    /**
     * Extract embedded tags from request parameter.
     */
    private function getEmbeddedTagsFromRequest(): array
    {
        $embeddedTagsParam = $this->request->getParam('embeddedTags');
        if ($embeddedTagsParam) {
            // Decode URI-encoded string before splitting
            $decoded = urldecode($embeddedTagsParam);

            return explode(',', $decoded);
        }

        return [];
    }

    /**
     * Process the days response.
     *
     * @param array  $rows      the days response
     * @param string $groupMode Bucket granularity: 'day' | 'week' | 'month' | 'year'
     */
    private function postProcessDays(array $rows, string $groupMode): array
    {
        foreach ($rows as &$row) {
            $row['dayid'] = (int) $row['dayid'];
            $row['count'] = (int) $row['count'];
        }

        // Convert to buckets if needed
        if ('day' !== $groupMode) {
            return array_values(array_reduce($rows, function ($carry, $item) use ($groupMode) {
                $groupId = $this->dayIdToGroupId($item['dayid'], $groupMode);

                if (!\array_key_exists($groupId, $carry)) {
                    $carry[$groupId] = ['dayid' => $groupId, 'count' => 0];
                }

                $carry[$groupId]['count'] += $item['count'];

                return $carry;
            }, []));
        }

        return $rows;
    }

    /**
     * Process the single day response.
     *
     * @param array  $row       A photo in the day response
     * @param string $groupMode Bucket granularity: 'day' | 'week' | 'month' | 'year'
     */
    private function postProcessDayPhoto(array &$row, string $groupMode = 'day'): void
    {
        // Convert field types
        $row['fileid'] = (int) $row['fileid'];
        $row['isvideo'] = (int) $row['isvideo'];
        $row['video_duration'] = (int) $row['video_duration'];
        $row['dayid'] = (int) $row['dayid'];
        $row['w'] = (int) $row['w'];
        $row['h'] = (int) $row['h'];
        // parse json of exif if exif exists
        if ($row['exif'] ?? null) {
            $row['exif'] = json_decode($row['exif'], true);
            $row['rating'] = isset($row['exif']['Rating']) ? (int) $row['exif']['Rating'] : null;
            $row['embedded_tags'] = Exif::extractEmbeddedTags($row['exif'], true);
        }

        // Optional fields
        if (!$row['isvideo']) {
            unset($row['isvideo'], $row['video_duration']);
        }
        if (!$row['liveid']) {
            unset($row['liveid']);
        }

        // Favorite field, may not be present
        if ($row['categoryid'] ?? null) {
            $row['isfavorite'] = 1;
        }
        unset($row['categoryid']);

        // Get hidden field if present
        if ($row['hidden'] ?? null) {
            $row['ishidden'] = 1;
        }
        unset($row['hidden']);

        // All cluster transformations
        ClustersBackend\Manager::applyDayPostTransforms($this->request, $row);

        // Already fetched for the GROUP BY / ORDER BY clause; keep it, it's free
        $row['datetaken'] = Util::sqlUtcToTimestamp($row['datetaken']);

        // Calculate the AUID if we can
        if (($epoch = $row['epoch'] ?? null) && ($size = $row['size'] ?? null)) {
            // compute AUID and discard size
            // epoch is used for ordering, so we keep it
            $row['auid'] = Exif::getAUID((int) $epoch, (int) $size);
            unset($row['size']);
        }

        // Convert dayId to the bucket id if needed
        if ('day' !== $groupMode) {
            $row['dayid'] = $this->dayIdToGroupId($row['dayid'], $groupMode);
        }

        // Convert storage_id to user display name
        if ($storage = $row['storage_id'] ?? null) {
            unset($row['storage_id']);

            /** @var array<string,string> */
            static $userMemo = [];
            if ($user = $userMemo[$storage] ?? null) {
                $row['shared_by'] = $user;
            } elseif ($user = $this->storageIdToUserName($storage)) {
                $row['shared_by'] = $userMemo[$storage] = $user;
            }

            if ('' === ($row['shared_by'] ?? null)) {
                unset($row['shared_by']);
            }
        }
    }

    /**
     * Get all folders inside a top folder.
     */
    private function addSubfolderJoinParams(
        IQueryBuilder &$query,
        TimelineRoot &$root,
        bool $archive,
        bool $hidden,
    ): void {
        // Add query parameters
        $query->setParameter('topFolderIds', $root->getIds(), IQueryBuilder::PARAM_INT_ARRAY);

        if ($archive) {
            $query->setParameter('cteFoldersArchive', true, IQueryBuilder::PARAM_BOOL);
        }

        if ($hidden) {
            $query->setParameter('cteIncludeHidden', true, IQueryBuilder::PARAM_BOOL);
        }

        if (!empty($excludeFolderIds = $root->getExcludedFolderIds())) {
            $query->setParameter('excludeFolderIds', $excludeFolderIds, IQueryBuilder::PARAM_INT_ARRAY);
            $query->setParameter('cteExcludeFolders', true, IQueryBuilder::PARAM_BOOL);
        }
    }

    /**
     * Convert a dayId to the id of the bucket it belongs to for the given grouping mode.
     */
    private function dayIdToGroupId(int $dayId, string $groupMode): int
    {
        return match ($groupMode) {
            'week' => $this->dayIdToWeekId($dayId),
            'month' => $this->dayIdToMonthId($dayId),
            'year' => $this->dayIdToYearId($dayId),
            default => $dayId, // 'day'
        };
    }

    /**
     * Given a bucket id (as returned by dayIdToGroupId), the dayId of the last day in that bucket.
     */
    private function groupIdEnd(int $groupId, string $groupMode): int
    {
        return match ($groupMode) {
            'week' => $this->dayIdWeekEnd($groupId),
            'month' => $this->dayIdMonthEnd($groupId),
            'year' => $this->dayIdYearEnd($groupId),
            default => $groupId, // 'day'
        };
    }

    private function dayIdWeekEnd(int $weekId): int
    {
        return $weekId + 6; // Sunday, 6 days after the Monday weekId
    }

    private function dayIdToWeekId(int $dayId): int
    {
        static $memoize = [];
        if ($cache = $memoize[$dayId] ?? null) {
            return $cache;
        }

        // ISO-8601 week starts Monday; date('N', ...) is 1 (Mon) .. 7 (Sun)
        $dow = (int) date('N', $dayId * 86400);

        return $memoize[$dayId] = $dayId - ($dow - 1);
    }

    private function dayIdYearEnd(int $yearId): int
    {
        return (int) ((strtotime(date('Y', $yearId * 86400).'1231') ?: 0) / 86400);
    }

    private function dayIdToYearId(int $dayId): int
    {
        static $memoize = [];
        if ($cache = $memoize[$dayId] ?? null) {
            return $cache;
        }

        return $memoize[$dayId] = (int) ((strtotime(date('Y', $dayId * 86400).'0101') ?: 0) / 86400);
    }

    private function dayIdMonthEnd(int $monthId): int
    {
        return (int) ((strtotime(date('Ymt', $monthId * 86400)) ?: 0) / 86400);
    }

    private function dayIdToMonthId(int $dayId): int
    {
        static $memoize = [];
        if ($cache = $memoize[$dayId] ?? null) {
            return $cache;
        }

        return $memoize[$dayId] = (strtotime(date('Ym', $dayId * 86400).'01') ?: 0) / 86400;
    }

    private function storageIdToUserName(string $storage): string
    {
        // Storage ID looks like "home::{uid}" or "local::{/path}" etc
        $pos = strpos($storage, '::');
        if (false === $pos) {
            return '';
        }
        $uid = substr($storage, $pos + 2);

        // If there are slashes, it's not a user.
        // Could be external storage or a group folder.
        // We should handle these cases in the future.
        // https://github.com/pulsejet/memories/issues/1402
        if (str_contains($uid, '/')) {
            return '';
        }

        // Check if self
        if (Util::isLoggedIn() && $uid === Util::getUID()) {
            return '';
        }

        // Otherwise it *may* be a user
        $user = $this->userManager->get($uid);

        return $user ? $user->getDisplayName() : '';
    }
}
