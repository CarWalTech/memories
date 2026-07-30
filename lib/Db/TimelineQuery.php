<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

final class TimelineQuery
{
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryFolders;
    use TimelineQueryLivePhoto;
    use TimelineQueryMap;
    use TimelineQueryNativeX;
    use TimelineQuerySingleItem;

    public const TIMELINE_SELECT = [
        'm.datetaken', 'm.dayid',
        'm.w', 'm.h', 'm.liveid',
        'm.isvideo', 'm.video_duration',
        'm.exif',
        'f.etag', 'f.name AS basename', 'f.path',
        'f.size', 'm.epoch', // auid
        'f.mtime',
        'mimetypes.mimetype',
    ];

    // Flag to control whether EXIF filtering happens at SQL level or PHP level
    // SQL level is faster but only available on MySQL/MariaDB
    // Set to false to force PHP-level filtering for debugging or compatibility
    protected bool $filterExifBySQL = true;

    protected ?TimelineRoot $_root = null; // cache
    protected bool $_rootEmptyAllowed = false;

    public function __construct(
        protected IDBConnection $connection,
        protected IRequest $request,
        protected IUserManager $userManager,
    ) {}

    /**
     * Set whether EXIF filtering should happen at SQL level.
     */
    public function setFilterExifBySQL(bool $value): void
    {
        $this->filterExifBySQL = $value;
    }

    /**
     * Check if we should filter EXIF at SQL level based on flag and database provider.
     */
    public function shouldFilterExifBySQL(): bool
    {
        if (!$this->filterExifBySQL) {
            return false;
        }

        // EXIF is stored as JSON text in the `memories.exif` column. It can be
        // queried in SQL on MySQL/MariaDB (native JSON functions) and PostgreSQL
        // (jsonb cast); the actual SQL is emitted dialect-aware via SQL::jsonExif*().
        // SQLite/Oracle fall back to PHP post-filtering (see postProcessDaysWithFilters).
        // Note: getDatabaseProvider() returns PLATFORM_MYSQL for MariaDB in the
        // default (non-strict) mode, and PLATFORM_POSTGRES (not 'pgsql') for Postgres.
        return \in_array($this->connection->getDatabaseProvider(), [
            IDBConnection::PLATFORM_MYSQL,
            IDBConnection::PLATFORM_POSTGRES,
        ], true);
    }

    public function allowEmptyRoot(bool $value = true): void
    {
        $this->_rootEmptyAllowed = $value;
    }

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }
}
