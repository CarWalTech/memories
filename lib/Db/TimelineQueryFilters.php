<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\ITags;

trait TimelineQueryFilters
{
    /**
     * @param int $maxRating 0 means unbounded (no maximum)
     */
    public function transformRatingRangeFilter(IQueryBuilder &$query, bool $aggregate, int $minRating, int $maxRating = 0): void
    {
        if ($minRating <= 0 && $maxRating <= 0) {
            return;
        }

        // Check if we should filter by SQL based on flag and database provider
        if (!$this->shouldFilterExifBySQL()) {
            return;
        }

        // Dialect-aware JSON extraction (MySQL JSON_EXTRACT / Postgres jsonb cast)
        $ratingExpr = SQL::jsonExifNumeric($query, 'Rating');

        if ($minRating > 0) {
            $query->andWhere("{$ratingExpr} >= :minRating");
            $query->setParameter('minRating', $minRating, IQueryBuilder::PARAM_INT);
        }

        if ($maxRating > 0) {
            $query->andWhere("{$ratingExpr} <= :maxRating");
            $query->setParameter('maxRating', $maxRating, IQueryBuilder::PARAM_INT);
        }
    }

    /**
     * Filter to photos whose filename (basename) matches $pattern -- see
     * SQL::patternMatchColumn() for LIKE/regex semantics. Uses a correlated
     * EXISTS on filecache rather than a direct `f.name` reference because the
     * aggregate days-list query (TimelineQueryDays::getDays()) does not join
     * filecache as `f` (only the per-day query does), so this must work
     * without relying on any particular join being present.
     */
    public function transformFilenameFilter(IQueryBuilder &$query, bool $aggregate, string $pattern): void
    {
        $pattern = trim($pattern);
        if ('' === $pattern) {
            return;
        }

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', 'fnf_f')
            ->where($sub->expr()->eq('fnf_f.fileid', 'm.fileid'))
            ->andWhere(SQL::patternMatchColumn($query, 'fnf_f.name', $pattern))
        ;

        $query->andWhere("EXISTS ({$sub->getSQL()})");
    }

    /**
     * Filter to photos whose filecache path matches $pattern. Note
     * `filecache.path` is storage-relative, not necessarily the exact
     * human-visible Nextcloud path -- acceptable for a substring/regex
     * search, but callers should present this as "matches folder path"
     * rather than promising an exact path match.
     */
    public function transformFilepathFilter(IQueryBuilder &$query, bool $aggregate, string $pattern): void
    {
        $pattern = trim($pattern);
        if ('' === $pattern) {
            return;
        }

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', 'fpf_f')
            ->where($sub->expr()->eq('fpf_f.fileid', 'm.fileid'))
            ->andWhere(SQL::patternMatchColumn($query, 'fpf_f.path', $pattern))
        ;

        $query->andWhere("EXISTS ({$sub->getSQL()})");
    }

    /**
     * Filter to photos taken on a day within [$startDayId, $endDayId]
     * (inclusive on both ends; either bound may be null for unbounded).
     * Callers are responsible for converting a human-facing date range into
     * dayIds (see DaysController::getDateRangeFilter()) -- this only needs
     * the already-resolved integers, same as the bucket-id range conversion
     * TimelineQueryDays::getDay() already does for week/month/year grouping.
     */
    public function transformDateRangeFilter(IQueryBuilder &$query, bool $aggregate, ?int $startDayId, ?int $endDayId): void
    {
        if (null !== $startDayId) {
            $query->andWhere($query->expr()->gte('m.dayid', $query->createNamedParameter($startDayId, IQueryBuilder::PARAM_INT)));
        }

        if (null !== $endDayId) {
            $query->andWhere($query->expr()->lte('m.dayid', $query->createNamedParameter($endDayId, IQueryBuilder::PARAM_INT)));
        }
    }

    /**
     * Filter to photos whose mimetype matches any of $mimePatterns (each a
     * plain value or a LIKE pattern, e.g. "image/%" for every image type, or
     * an exact "image/x-canon-cr2" for a specific RAW format). Correlated
     * EXISTS for the same reason as transformFilenameFilter() -- `mimetypes`
     * is only joined in the per-day query, not the aggregate days-list one.
     *
     * @param string[] $mimePatterns
     */
    public function transformFileTypeFilter(IQueryBuilder &$query, bool $aggregate, array $mimePatterns): void
    {
        if (empty($mimePatterns)) {
            return;
        }

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', 'ftf_f')
            ->innerJoin('ftf_f', 'mimetypes', 'ftf_mt', $sub->expr()->eq('ftf_f.mimetype', 'ftf_mt.id'))
            ->where($sub->expr()->eq('ftf_f.fileid', 'm.fileid'))
        ;

        $or = $sub->expr()->orX();
        foreach ($mimePatterns as $pattern) {
            $or->add($sub->expr()->like('ftf_mt.mimetype', $query->createNamedParameter($pattern, IQueryBuilder::PARAM_STR)));
        }
        $sub->andWhere($or);

        $query->andWhere("EXISTS ({$sub->getSQL()})");
    }

    /**
     * Filter to photos with an exact (case-insensitive) camera Make/Model/
     * LensModel. Each parameter is independent -- pass null/empty to leave
     * that field unconstrained. Exact equality (not substring) is
     * intentional: these values come from a fixed, known vocabulary per
     * account (a "pick from the cameras used in your library" UI), not a
     * free-text search.
     */
    public function transformCameraFilter(IQueryBuilder &$query, bool $aggregate, ?string $make, ?string $model, ?string $lens): void
    {
        if (!$this->shouldFilterExifBySQL()) {
            return;
        }

        if (null !== $make && '' !== $make) {
            $this->addExifTextEquals($query, 'Make', $make, 'camMake');
        }

        if (null !== $model && '' !== $model) {
            $this->addExifTextEquals($query, 'Model', $model, 'camModel');
        }

        if (null !== $lens && '' !== $lens) {
            $this->addExifTextEquals($query, 'LensModel', $lens, 'camLens');
        }
    }

    /**
     * WHERE-clause pre-filter for the flat, AND-of-tags embedded-tags filter.
     *
     * This is deliberately a coarse (superset-safe) substring pre-filter, not
     * an exact tag match -- see SQL::jsonExifFieldContainsCI() for why an
     * exact match on the stored EXIF value can produce false *negatives*
     * against the app's real tag semantics (comma-joined/scalar Keywords
     * values that Exif::extractEmbeddedTags() splits into several tags).
     * Callers MUST still authoritatively re-verify every row this lets
     * through against Exif::extractEmbeddedTags() before treating a match as
     * final -- see TimelineQueryDays::getDay()'s unconditional embedded_tags
     * recheck, and TimelineQueryDays::getDays()'s forced use of
     * postProcessDaysWithFilters() whenever this filter is active.
     */
    public function transformEmbeddedTagsFilter(IQueryBuilder &$query, bool $aggregate, array $embeddedTags): void
    {
        if (empty($embeddedTags)) {
            return;
        }

        // Check if we should filter by SQL based on flag and database provider
        if (!$this->shouldFilterExifBySQL()) {
            return;
        }

        $fields = ['Keywords', 'Subject', 'TagsList', 'HierarchicalSubject'];

        foreach ($embeddedTags as $index => $tag) {
            $tagParam = "tag_{$index}";
            $or = $query->expr()->orX();

            foreach ($fields as $field) {
                $or->add(SQL::jsonExifFieldContainsCI($query, $field, ":{$tagParam}"));
            }

            // AND condition across tags (each requested tag must be found
            // in at least one field); OR across fields for a single tag.
            $query->andWhere($or);
            $query->setParameter($tagParam, $tag, IQueryBuilder::PARAM_STR);
        }
    }

    /**
     * WHERE-clause pre-filter for the tag/embeddedTag/recognize/
     * facerecognition/place boolean expression builder (see FilterExpression
     * for the full design). This is a safe superset, not the final answer:
     * every embeddedTag leaf is uncertain in SQL (same reasoning as
     * transformEmbeddedTagsFilter() above), so any row this lets through
     * still needs FilterExpression::evaluate() run against its real data
     * before a match is treated as final -- see
     * TimelineQueryDays::hasFilterExprActive() and its callers.
     */
    public function transformFilterExprFilter(IQueryBuilder &$query, bool $aggregate, array $tree): void
    {
        $sql = FilterExpression::compileSqlSuperset($tree, $query);
        $query->andWhere($sql);
    }

    public function transformFavoriteFilter(IQueryBuilder &$query, bool $aggregate): void
    {
        if (Util::isLoggedIn()) {
            $query->innerJoin('m', 'vcategory_to_object', 'vcoi', $query->expr()->andX(
                $query->expr()->eq('vcoi.objid', 'm.fileid'),
                $query->expr()->in('vcoi.categoryid', $this->getFavoriteVCategoryFun($query)),
            ));
        }
    }

    public function addFavoriteTag(IQueryBuilder &$query): void
    {
        if (Util::isLoggedIn()) {
            $query->leftJoin('m', 'vcategory_to_object', 'vco', $query->expr()->andX(
                $query->expr()->eq('vco.objid', 'm.fileid'),
                $query->expr()->in('vco.categoryid', $this->getFavoriteVCategoryFun($query)),
            ));
            $query->addSelect('vco.categoryid');
        }
    }

    public function transformVideoFilter(IQueryBuilder &$query, bool $aggregate): void
    {
        $query->andWhere($query->expr()->eq('m.isvideo', $query->expr()->literal(1)));
    }

    public function transformLimit(IQueryBuilder &$query, bool $aggregate, int $limit): void
    {
        /** @psalm-suppress RedundantCondition */
        if ($limit >= 1 || $limit <= 100) {
            $query->setMaxResults($limit);
        }
    }

    private function applyAllTransforms(array $transforms, IQueryBuilder &$query, bool $aggregate): void
    {
        foreach ($transforms as &$transform) {
            $fun = \array_slice($transform, 0, 2);
            $params = \array_slice($transform, 2);
            array_unshift($params, $aggregate);
            array_unshift($params, $query);
            $fun(...$params);
        }
    }

    private function getFavoriteVCategoryFun(IQueryBuilder &$query): IQueryFunction
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select('id')
            ->from('vcategory', 'vc')
            ->where($sub->expr()->andX(
                $sub->expr()->eq('type', $sub->expr()->literal('files')),
                $sub->expr()->eq('uid', $query->createNamedParameter(Util::getUID())),
                $sub->expr()->eq('category', $sub->expr()->literal(ITags::TAG_FAVORITE)),
            ))
        ;

        return SQL::subquery($query, $sub);
    }

    /**
     * AND a case-insensitive equality condition on a decoded EXIF text field.
     * $paramName distinguishes multiple calls within the same query (e.g.
     * transformCameraFilter() calling this for Make, Model, and LensModel).
     */
    private function addExifTextEquals(IQueryBuilder &$query, string $field, string $value, string $paramName): void
    {
        $textExpr = SQL::jsonExifText($query, $field);
        $query->andWhere("LOWER({$textExpr}) = LOWER(:{$paramName})");
        $query->setParameter($paramName, $value, IQueryBuilder::PARAM_STR);
    }
}
