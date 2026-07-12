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

namespace OCA\Memories\Controller;

use OCA\Memories\ClustersBackend\EmbeddedTagsBackend;
use OCA\Memories\Db\EmbeddedTagsQuery;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class EmbeddedTagsController extends GenericApiController
{
    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserConfig $userConfig,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager,
        LoggerInterface $logger,
        TimelineQuery $tq,
        FsManager $fs,
        protected EmbeddedTagsQuery $etq,
        protected EmbeddedTagsBackend $backend,
        protected TimelineWrite $tw,
    ) {
        parent::__construct($request, $config, $userConfig, $userSession, $connection, $rootFolder, $appManager, $logger, $tq, $fs);
    }

    /**
     * Get direct child tags of a path with preview photos, for the
     * folder-style embedded tags browsing view. Tags with no matching
     * photos are included too (with an empty previews array) so they
     * can be seen and, if desired, deleted.
     */
    #[NoAdminRequired]
    public function sub(string $path = ''): Http\Response
    {
        return Util::guardEx(function () use ($path) {
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            $path = trim($path, '/');

            $list = [];
            foreach ($this->etq->getChildTags($path) as $child) {
                $previews = $this->backend->getPhotos($child['path'], 4);

                $list[] = [
                    'name' => $child['tag'],
                    'path' => $child['path'],
                    'previews' => array_map(static fn ($p) => [
                        'fileid' => (int) $p['fileid'],
                        'etag' => $p['etag'],
                    ], $previews),
                ];
            }

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * Create a new (empty) child tag under the given path. Only touches
     * the tag dictionary -- no photos are affected.
     */
    #[NoAdminRequired]
    public function create(string $path, string $name): Http\Response
    {
        return Util::guardEx(function () use ($path, $name) {
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            $name = trim($name);
            if ('' === $name || str_contains($name, '/')) {
                throw Exceptions::BadRequest('tag name must be non-empty and cannot contain "/"');
            }

            $path = trim($path, '/');
            $fullPath = '' === $path ? $name : "{$path}/{$name}";

            $this->tw->createTag(Util::getUID(), $fullPath);

            return new JSONResponse(['path' => $fullPath], Http::STATUS_OK);
        });
    }

    /**
     * Delete a tag. Only allowed if the tag and its whole subtree have
     * no photos currently associated with them -- this never touches
     * photo EXIF data, only the tag dictionary. If the tag (or any
     * descendant) still has photos, the caller must remove the tag from
     * those photos first (via the per-photo tag editor).
     */
    #[NoAdminRequired]
    public function delete(string $path): Http\Response
    {
        return Util::guardEx(function () use ($path) {
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            $path = trim($path, '/');
            if ('' === $path) {
                throw Exceptions::BadRequest('cannot delete the tag root');
            }

            if ($this->backend->hasAnyPhotos($path)) {
                throw Exceptions::BadRequest('tag (or a sub-tag) still has photos -- remove the tag from all photos first');
            }

            $this->tw->deleteTagByPath(Util::getUID(), $path, true);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /**
     * Get tags in flat manner with optional filtering and pagination.
     */
    #[NoAdminRequired]
    public function flat(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $limit = $this->request->getParam('limit');
            $offset = $this->request->getParam('offset');

            // Validate and sanitize parameters
            $limit = null !== $limit ? max(1, min(1000, (int) $limit)) : null;
            $offset = null !== $offset ? max(0, (int) $offset) : null;
            $pattern = null !== $pattern ? (string) $pattern : null;

            // Get tags
            $tags = $this->etq->getTagsFlat($pattern, $limit, $offset);

            // Get total count for pagination
            $totalCount = null;
            if (null !== $limit || null !== $offset) {
                $totalCount = $this->etq->getTagsCount($pattern);
            }

            // Prepare response
            $response = [
                'tags' => $tags,
            ];

            if (null !== $totalCount) {
                $response['pagination'] = [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset ?? 0,
                ];
            }

            return new JSONResponse($response, Http::STATUS_OK);
        });
    }

    /**
     * Get tags in hierarchical structure.
     */
    #[NoAdminRequired]
    public function hierarchical(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $pattern = null !== $pattern ? (string) $pattern : null;

            // Get tags in hierarchical structure
            $tags = $this->etq->getTagsHierarchical($pattern);

            return new JSONResponse([
                'tags' => $tags,
                'structure' => 'hierarchical',
            ], Http::STATUS_OK);
        });
    }

    /**
     * Get tags count (useful for pagination info).
     */
    #[NoAdminRequired]
    public function count(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $pattern = null !== $pattern ? (string) $pattern : null;

            // Get count
            $count = $this->etq->getTagsCount($pattern);

            return new JSONResponse([
                'count' => $count,
                'pattern' => $pattern,
            ], Http::STATUS_OK);
        });
    }
}
