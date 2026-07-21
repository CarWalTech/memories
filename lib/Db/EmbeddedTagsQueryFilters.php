<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait EmbeddedTagsQueryFilters
{
    protected IDBConnection $connection;

    /**
     * Transform query to filter by pattern using LIKE or REGEXP.
     *
     * @param string $pattern Pattern to search for
     */
    public function transformPatternFilter(IQueryBuilder &$query, string $pattern): void
    {
        // Sanitize pattern input
        $pattern = trim($pattern);
        if (empty($pattern)) {
            return;
        }

        $or = $query->expr()->orX();
        $or->add(SQL::patternMatchColumn($query, 'et.tag', $pattern));
        $or->add(SQL::patternMatchColumn($query, 'et.path', $pattern));
        $query->andWhere($or);
    }

    /**
     * Apply limit transformation for pagination.
     *
     * @param int $limit Maximum number of results
     */
    public function transformLimit(IQueryBuilder &$query, int $limit): void
    {
        if ($limit >= 1 && $limit <= 1000) { // Allow larger limits for tags
            $query->setMaxResults($limit);
        }
    }

    /**
     * Apply offset transformation for pagination.
     *
     * @param int $offset Number of results to skip
     */
    public function transformOffset(IQueryBuilder &$query, int $offset): void
    {
        if ($offset >= 0) {
            $query->setFirstResult($offset);
        }
    }
}
