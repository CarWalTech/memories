<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\ITags;

trait TimelineQueryFilters
{
    public function transformMinRatingFilter(IQueryBuilder &$query, bool $aggregate, int $minRating): void
    {
        if ($minRating <= 0) {
            return;
        }

        // Check if we should filter by SQL based on flag and database provider
        if (!$this->shouldFilterExifBySQL()) {
            return;
        }

        // Dialect-aware JSON extraction (MySQL JSON_EXTRACT / Postgres jsonb cast)
        $ratingExpr = SQL::jsonExifNumeric($query, 'Rating');
        $query->andWhere("{$ratingExpr} >= :minRating");
        $query->setParameter('minRating', $minRating, IQueryBuilder::PARAM_INT);
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
}
