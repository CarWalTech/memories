<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;

final class SQL
{
    /**
     * @return never
     */
    public static function debugQuery(IQueryBuilder &$query, string $sql = '')
    {
        // Print the query and exit
        $sql = empty($sql) ? $query->getSQL() : $sql;
        $sql = str_replace('*PREFIX*', 'oc_', $sql);
        $sql = self::replaceQueryParams($query, $sql);
        echo "{$sql}";

        exit; // only for debugging, so this is okay
    }

    public static function replaceQueryParams(IQueryBuilder &$query, string $sql): string
    {
        $conn = $query->getConnection();

        foreach ($query->getParameters() as $key => $value) {
            if (\is_array($value)) {
                $value = implode(',', array_map(static fn ($v): string => $conn->quote($v), $value));
            } elseif (\is_bool($value)) {
                $value = $conn->quote($value ? '1' : '0');
            } elseif (null === $value) {
                $value = $conn->quote('NULL');
            } else {
                $value = $conn->quote((string) $value);
            }

            $sql = str_replace(':'.$key, $value, $sql);
        }

        return $sql;
    }

    /**
     * Materialize a query as a subquery and select everything from it.
     * This is very useful for optimization.
     *
     * @param IQueryBuilder $query The query to materialize
     * @param string        $alias The alias to use for the subquery
     */
    public static function materialize(IQueryBuilder $query, string $alias): IQueryBuilder
    {
        // Create new query and copy over parameters (and types)
        $outer = $query->getConnection()->getQueryBuilder();
        $outer->setParameters($query->getParameters(), $query->getParameterTypes());

        // Create the subquery function for selecting from it
        $outer->select("{$alias}.*")->from(self::subquery($outer, $query), $alias);

        return $outer;
    }

    /**
     * Create a subquery function.
     *
     * @param IQueryBuilder $query    The query to create the function on
     * @param IQueryBuilder $subquery The subquery to use
     */
    public static function subquery(IQueryBuilder &$query, IQueryBuilder &$subquery): IQueryFunction
    {
        return $query->createFunction("({$subquery->getSQL()})");
    }

    /**
     * Create an EXISTS expression.
     *
     * @param IQueryBuilder        $query  The query to create the function on
     * @param IQueryBuilder|string $clause The clause to check for existence
     */
    public static function exists(IQueryBuilder &$query, IQueryBuilder|string &$clause): IQueryFunction
    {
        if ($clause instanceof IQueryBuilder) {
            $clause = $clause->getSQL();
        }

        return $query->createFunction("EXISTS ({$clause})");
    }

    /**
     * Create a NOT EXISTS expression.
     *
     * @param IQueryBuilder        $query  The query to create the function on
     * @param IQueryBuilder|string $clause The clause to check for existence
     */
    public static function notExists(IQueryBuilder &$query, IQueryBuilder|string &$clause): IQueryFunction
    {
        if ($clause instanceof IQueryBuilder) {
            $clause = $clause->getSQL();
        }

        return $query->createFunction("NOT EXISTS ({$clause})");
    }

    /**
     * Create a DISTINCT expression.
     *
     * @param IQueryBuilder $query The query to create the function on
     * @param string        $field The field to select distinct values from
     */
    public static function distinct(IQueryBuilder &$query, string $field): IQueryFunction
    {
        return $query->createFunction("DISTINCT {$field}");
    }

    /**
     * Create a AVG expression.
     *
     * @param IQueryBuilder $query The query to create the function on
     * @param string        $field The field to average
     */
    public static function average(IQueryBuilder &$query, string $field): IQueryFunction
    {
        return $query->createFunction("AVG({$field})");
    }

    /**
     * Whether EXIF (stored as JSON text in the `memories.exif` column) can be
     * queried directly in SQL for the given connection. True for MySQL/MariaDB
     * (native JSON functions) and PostgreSQL (jsonb cast); false elsewhere
     * (SQLite/Oracle), where callers must fall back to PHP post-filtering.
     */
    public static function exifSqlSupported(IQueryBuilder $query): bool
    {
        $provider = $query->getConnection()->getDatabaseProvider();

        // Note: MariaDB reports as PLATFORM_MYSQL in the default (non-strict) mode.
        return \in_array($provider, [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_POSTGRES], true);
    }

    /**
     * Build a SQL expression that extracts a numeric scalar field from the
     * EXIF JSON (e.g. Rating), suitable for a numeric comparison.
     *
     * Returns dialect-specific SQL. On PostgreSQL the `text` column is cast to
     * jsonb via NULLIF(...,'') so null/empty rows yield SQL NULL instead of
     * raising a cast error (exif is always written by json_encode, so any
     * non-empty value is valid JSON). See exifSqlSupported().
     *
     * The extracted text is regex-validated before the numeric cast: EXIF/XMP
     * comes from third-party tools and is not guaranteed to have this field as
     * a clean number (e.g. a sidecar writer storing Rating as an array or a
     * non-numeric string) -- an uncaught `::numeric` cast failure on any one
     * row would fail the entire query, not just that row, so a non-numeric
     * value must degrade to SQL NULL (never-matches) instead of erroring.
     */
    public static function jsonExifNumeric(IQueryBuilder $query, string $field, string $exifCol = 'm.exif'): string
    {
        $field = self::sanitizeJsonField($field);
        $provider = $query->getConnection()->getDatabaseProvider();

        return match ($provider) {
            IDBConnection::PLATFORM_MYSQL => "JSON_EXTRACT({$exifCol}, '$.{$field}')",
            IDBConnection::PLATFORM_POSTGRES => "(CASE WHEN (NULLIF({$exifCol}, '')::jsonb->>'{$field}') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (NULLIF({$exifCol}, '')::jsonb->>'{$field}')::numeric ELSE NULL END)",
            default => 'NULL',
        };
    }

    /**
     * Build a SQL expression that extracts a string scalar field from the EXIF
     * JSON (e.g. Make/Model/LensModel) as plain (unquoted) text, suitable for
     * an equality/LIKE/ILIKE comparison.
     */
    public static function jsonExifText(IQueryBuilder $query, string $field, string $exifCol = 'm.exif'): string
    {
        $field = self::sanitizeJsonField($field);
        $provider = $query->getConnection()->getDatabaseProvider();

        return match ($provider) {
            IDBConnection::PLATFORM_MYSQL => "JSON_UNQUOTE(JSON_EXTRACT({$exifCol}, '$.{$field}'))",
            IDBConnection::PLATFORM_POSTGRES => "NULLIF({$exifCol}, '')::jsonb->>'{$field}'",
            default => 'NULL',
        };
    }

    /**
     * Build a SQL boolean expression testing whether a *single* EXIF JSON
     * field's decoded text (e.g. Keywords/Subject/TagsList/HierarchicalSubject)
     * contains $needle anywhere, case-insensitively. Callers needing to check
     * several of these fields OR them together (see e.g.
     * TimelineQueryFilters::transformEmbeddedTagsFilter()).
     *
     * This is deliberately a coarse, superset-safe pre-filter, not an exact
     * tag match: the app's canonical tag parser (Exif::extractEmbeddedTags())
     * does real text processing before a stored EXIF value becomes a tag --
     * splitting comma-joined values, inferring hierarchical paths from '/' or
     * '|' separators inside a single scalar string -- so a stored value can
     * legitimately differ from the flattened tag string a caller searches
     * for (e.g. Keywords stored as the single scalar "A/B, C/D" yields two
     * tags "A/B" and "C/D", neither of which appears verbatim in the field).
     * A plain substring test can never produce a false *negative* here
     * (the searched text is always a substring of the stored value it came
     * from), only false positives -- which is exactly the property needed
     * for a pre-filter that narrows candidate rows before an authoritative
     * PHP recheck against Exif::extractEmbeddedTags()/embeddedTagPathMatches()
     * (see TimelineQueryDays::getDay()'s embedded_tags recheck, and
     * postProcessDaysWithFilters()) -- it must never exclude a true match.
     *
     * Deliberately checks the *decoded* field text (via jsonExifText(), same
     * as JSON_UNQUOTE/->>) rather than doing a substring search against the
     * raw stored `m.exif` JSON text directly: PHP's json_encode() escapes '/'
     * as '\/' by default, so a tag containing '/' (the normal case for any
     * hierarchical tag) would never appear verbatim in the raw column text
     * even though it's exactly what the decoded field value contains --
     * searching raw JSON text would silently produce false negatives on
     * exactly the tags most likely to be searched for.
     *
     * $namedParam must be a placeholder already created on the query, bound
     * to the raw (unwrapped, no wildcards) search text.
     */
    public static function jsonExifFieldContainsCI(IQueryBuilder $query, string $field, string $namedParam, string $exifCol = 'm.exif'): string
    {
        $textExpr = self::jsonExifText($query, $field, $exifCol);
        $provider = $query->getConnection()->getDatabaseProvider();

        return match ($provider) {
            IDBConnection::PLATFORM_POSTGRES => "({$textExpr}) ILIKE ('%' || {$namedParam} || '%')",
            // MySQL/MariaDB text columns default to a case-insensitive collation,
            // but don't assume it -- LOWER() both sides to be collation-agnostic.
            default => "LOWER({$textExpr}) LIKE LOWER(CONCAT('%', {$namedParam}, '%'))",
        };
    }

    /**
     * Restrict an EXIF JSON field name to a safe identifier before it is
     * interpolated into raw SQL. Field names come from fixed whitelists in the
     * callers, but this is defense-in-depth against SQL injection.
     */
    private static function sanitizeJsonField(string $field): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $field) ?? '';
    }

    /**
     * TRUNCATE a table (remove all rows and reset auto-increment).
     * This wrapper should be removed when support for Nextcloud <32 is dropped.
     *
     * @param IDBConnection $connection The database connection
     * @param string        $table      The table to truncate
     * @param bool          $cascade    Whether to cascade the truncate operation
     */
    public static function truncate(IDBConnection &$connection, string $table, bool $cascade): void
    {
        // getDatabasePlatform is deprecated on Nextcloud 32
        if (method_exists($connection, 'truncateTable')) {
            $connection->truncateTable($table, $cascade);
        } else {
            /** @psalm-suppress DeprecatedMethod */
            $sql = $connection->getDatabasePlatform()->getTruncateTableSQL('*PREFIX*'.$table, $cascade);
            $connection->executeStatement($sql);
        }
    }
}
