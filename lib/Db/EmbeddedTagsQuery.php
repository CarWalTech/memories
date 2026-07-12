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

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

class EmbeddedTagsQuery
{
    use EmbeddedTagsQueryFilters;

    public const TAGS_SELECT = [
        'id', 'user_id', 'tag', 'parent_tag_id',
        'path', 'level', 'created_at',
    ];

    public function __construct(
        protected IDBConnection $connection,
        protected IRequest $request,
        protected IUserManager $userManager,
    ) {}

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }

    /**
     * Get all tags for a user in flat manner with optional filtering and pagination.
     *
     * @param null|string $pattern Optional regex pattern to filter tags
     * @param null|int    $limit   Optional limit for pagination
     * @param null|int    $offset  Optional offset for pagination
     *
     * @return array List of tags
     */
    public function getTagsFlat(?string $pattern = null, ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->getBuilder();

        $query->select(self::TAGS_SELECT)
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())))
            ->orderBy('path', 'ASC')
        ;

        // Apply pattern filter if provided
        if (null !== $pattern) {
            $this->transformPatternFilter($query, $pattern);
        }

        // Apply pagination if provided
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }
        if (null !== $offset) {
            $query->setFirstResult($offset);
        }

        return $query->executeQuery()->fetchAll() ?: [];
    }

    /**
     * Get all tags for a user in hierarchical structure.
     *
     * @param null|string $pattern Optional regex pattern to filter tags
     *
     * @return array Hierarchical structure of tags
     */
    public function getTagsHierarchical(?string $pattern = null): array
    {
        // Get all tags first
        $query = $this->getBuilder();

        $query->select(self::TAGS_SELECT)
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())))
            ->orderBy('level', 'ASC')
            ->addOrderBy('tag', 'ASC')
        ;

        // Apply pattern filter if provided
        if (null !== $pattern) {
            $this->transformPatternFilter($query, $pattern);
        }

        $allTags = $query->executeQuery()->fetchAll() ?: [];

        // Build hierarchical structure
        return $this->buildHierarchy($allTags);
    }

    /**
     * Get count of tags matching pattern.
     *
     * @param null|string $pattern Optional regex pattern to filter tags
     *
     * @return int Number of tags
     */
    public function getTagsCount(?string $pattern = null): int
    {
        $query = $this->getBuilder();

        $query->select($query->func()->count('*', 'count'))
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())))
        ;

        // Apply pattern filter if provided
        if (null !== $pattern) {
            $this->transformPatternFilter($query, $pattern);
        }

        $result = $query->executeQuery()->fetch();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get the direct child tags of a given tag path for the current user.
     *
     * @param string $parentPath full path of the parent tag, or '' for root-level tags
     *
     * @return array List of {id, tag, path} rows
     */
    public function getChildTags(string $parentPath): array
    {
        $uid = Util::getUID();
        $parentId = null;

        if ('' !== $parentPath) {
            // Resolve the parent's id as a plain value first: a correlated
            // subquery built on a separate IQueryBuilder would generate its
            // own :dcValueN placeholders whose bound values never get copied
            // into the outer query's parameter bag (SQL::subquery() only
            // splices in the SQL text, not the parameters), so it never binds.
            $parentIdQuery = $this->getBuilder();
            $found = $parentIdQuery->select('id')
                ->from('memories_embedded_tags', 'pt')
                ->where($parentIdQuery->expr()->eq('pt.user_id', $parentIdQuery->createNamedParameter($uid)))
                ->andWhere($parentIdQuery->expr()->eq('pt.path', $parentIdQuery->createNamedParameter($parentPath)))
                ->executeQuery()
                ->fetchOne()
            ;

            if (false === $found) {
                return [];
            }

            $parentId = (int) $found;
        }

        $query = $this->getBuilder();
        $query->select('et.id', 'et.tag', 'et.path')
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('et.user_id', $query->createNamedParameter($uid)))
        ;

        if (null === $parentId) {
            $query->andWhere($query->expr()->isNull('et.parent_tag_id'));
        } else {
            $query->andWhere($query->expr()->eq('et.parent_tag_id', $query->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)));
        }

        $query->orderBy($query->func()->lower('et.tag'), 'ASC');

        return $query->executeQuery()->fetchAll() ?: [];
    }

    /**
     * Get every leaf tag path (a tag with no children) for the current user.
     * Used for the flat "all embedded tags" cluster listing.
     *
     * @return array List of {id, path} rows
     */
    public function getLeafTagPaths(): array
    {
        $query = $this->getBuilder();
        $uid = Util::getUID();

        $childSq = $this->getBuilder();
        $childSq->select($childSq->expr()->literal(1))
            ->from('memories_embedded_tags', 'child')
            ->where($childSq->expr()->eq('child.parent_tag_id', 'et.id'))
        ;

        $query->select('et.id', 'et.path')
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('et.user_id', $query->createNamedParameter($uid)))
            ->andWhere(SQL::notExists($query, $childSq))
            ->orderBy($query->func()->lower('et.path'), 'ASC')
        ;

        return $query->executeQuery()->fetchAll() ?: [];
    }

    /**
     * Build hierarchical structure from flat tags array.
     *
     * @param array $tags Flat array of tags
     *
     * @return array Hierarchical structure
     */
    private function buildHierarchy(array $tags): array
    {
        $hierarchy = [];
        $idMap = [];

        // First pass: create nodes and map by ID
        foreach ($tags as $tag) {
            $node = [
                'id' => $tag['id'],
                'tag' => $tag['tag'],
                'path' => $tag['path'],
                'level' => $tag['level'],
                'created_at' => $tag['created_at'],
                'children' => [],
            ];
            $idMap[$tag['id']] = &$node;
        }

        // Second pass: build hierarchy
        foreach ($tags as $tag) {
            if (null === $tag['parent_tag_id']) {
                // Root level tag
                $hierarchy[] = &$idMap[$tag['id']];
            } else {
                // Child tag
                if (isset($idMap[$tag['parent_tag_id']])) {
                    $idMap[$tag['parent_tag_id']]['children'][] = &$idMap[$tag['id']];
                }
            }
        }

        return $hierarchy;
    }
}
