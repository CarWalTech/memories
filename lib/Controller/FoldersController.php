<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\MountRecovery;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Files\FileInfo;
use OCP\Files\Folder;

final class FoldersController extends GenericApiController
{
    #[NoAdminRequired]
    #[PublicPage]
    public function sub(string $folder): Http\Response
    {
        return Util::guardEx(function () use ($folder) {
            $folder = Util::sanitizePath($folder);
            if (null === $folder) {
                throw Exceptions::BadRequest('Invalid parameter folder');
            }

            // Get the root folder (share root or user root)
            $root = $this->fs->getShareNode() ?? Util::getUserFolder();
            if (!$root instanceof Folder) {
                throw Exceptions::BadRequest('Root is not a folder');
            }

            // Get the inner folder
            try {
                $node = $root->get($folder);
            } catch (\OCP\Files\NotFoundException) {
                throw Exceptions::NotFound("Folder not found: {$folder}");
            }

            // Make sure we have a folder
            if (!$node instanceof Folder) {
                throw Exceptions::BadRequest('Path is not a folder');
            }

            // Ugly: get the view of the folder with reflection
            // This is unfortunately the only way to get the contents of a folder
            // matching a MIME type without using SEARCH, which is deep.
            //
            // To top it off, all this is completely useless at the moment
            // because the MIME search is done PHP-side
            $rp = new \ReflectionProperty(\OC\Files\Node\Node::class, 'view');
            $rp->setAccessible(true);

            /** @var \OC\Files\View */
            $view = $rp->getValue($node);

            // Get the subfolders
            /** @psalm-suppress InternalMethod */
            $folders = $view->getDirectoryContent($node->getPath(), FileInfo::MIMETYPE_FOLDER, $node);

            // Sort by name
            usort($folders, static fn ($a, $b) => strnatcmp($a->getName(), $b->getName()));

            // Construct root for the base folder. This way we can reuse the
            // root by filtering out the subfolders we don't want.
            $root = new TimelineRoot();
            $this->fs->populateRoot($root);

            // Process to response type
            $list = [];
            foreach ($folders as $node) {
                try {
                    $list[] = MountRecovery::retry($node->getPath(), function () use ($root, $node) {
                        // Base changes permanently remove any mounts outside the
                        // target folder, so we need to use a clone for each subfolder
                        $subRoot = clone $root;

                        // Switch the cloned root to use only this folder
                        $subRoot->addFolder($node);
                        $subRoot->baseChange($node->getPath());

                        return [
                            'fileid' => $node->getId(),
                            'name' => $node->getName(),
                            'size' => $node->getSize(),
                            'mtime' => $node->getMTime(),
                            'previews' => $this->fs->getCachedRootPreviews($node, fn () => $this->tq->getRootPreviews($subRoot)),
                        ];
                    }, $this->logger);
                } catch (\Throwable $e) {
                    // A subfolder can be an unreachable mount (offline external
                    // storage, transient hiccup, ...). Skip just this one entry
                    // instead of failing the whole directory listing -- it
                    // starts showing up again on its own once available.
                    $this->logger->warning("Skipping unreachable subfolder {$node->getPath()}: {$e->getMessage()}", ['app' => 'memories']);
                }
            }

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * List the configured Folders-view root paths, for the top-level
     * root picker shown when more than one is configured.
     */
    #[NoAdminRequired]
    public function roots(): Http\Response
    {
        return Util::guardEx(function () {
            $uid = Util::getUID();
            $userFolder = Util::getUserFolder($uid);

            // Construct root for the base folder. This way we can reuse the
            // root by filtering out the roots we don't want.
            $baseRoot = new TimelineRoot();
            $this->fs->populateRoot($baseRoot);

            $list = [];
            foreach (Util::getFoldersPaths($uid) as $path) {
                try {
                    $node = $userFolder->get($path);
                } catch (\OCP\Files\NotFoundException) {
                    continue; // configured root no longer exists; skip
                }

                if (!$node instanceof Folder) {
                    continue;
                }

                try {
                    $list[] = MountRecovery::retry($node->getPath(), function () use ($baseRoot, $node, $path) {
                        // Base changes permanently remove any mounts outside the
                        // target folder, so we need to use a clone for each root
                        $root = clone $baseRoot;
                        $root->addFolder($node);
                        $root->baseChange($node->getPath());

                        return [
                            'fileid' => $node->getId(),
                            'name' => $node->getName(),
                            'path' => trim($path, '/'),
                            'previews' => $this->fs->getCachedRootPreviews($node, fn () => $this->tq->getRootPreviews($root)),
                        ];
                    }, $this->logger);
                } catch (\Throwable $e) {
                    // A root can be on a mount that's temporarily unreachable
                    // (offline external storage, transient hiccup, ...). Skip
                    // just this one root instead of failing the whole list --
                    // there's no state to fix up once the mount is back, so it
                    // starts showing up again on its own on the next request.
                    $this->logger->warning("Skipping unreachable root {$path}: {$e->getMessage()}", ['app' => 'memories']);
                }
            }

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * List the pinned favorite folder paths, for the tile grid shown
     * at the top of the Favorites view.
     */
    #[NoAdminRequired]
    public function favorites(): Http\Response
    {
        return Util::guardEx(function () {
            $uid = Util::getUID();
            $userFolder = Util::getUserFolder($uid);

            $list = [];
            foreach (Util::getFavoriteFoldersPaths($uid) as $path) {
                try {
                    $node = $userFolder->get($path);
                } catch (\OCP\Files\NotFoundException) {
                    continue; // pinned folder no longer exists; skip
                }

                if (!$node instanceof Folder) {
                    continue;
                }

                try {
                    $list[] = MountRecovery::retry($node->getPath(), function () use ($node, $path) {
                        // Unlike roots()/sub(), do NOT go through FsManager::populateRoot():
                        // with no `folder`/`all` request param it scopes to the *entire*
                        // timeline path and recursively touches every mount under it via
                        // addMountPoints(), including unrelated external storages -- and
                        // this endpoint is hit on every visit to the Favorites page, not
                        // just when browsing Folders. Build a root scoped to just this one
                        // favorited folder instead, so only mounts nested inside it (if
                        // any) are ever touched.
                        $root = new TimelineRoot();
                        $root->addFolder($node);
                        $root->addMountPoints();

                        return [
                            'fileid' => $node->getId(),
                            'name' => $node->getName(),
                            'path' => trim($path, '/'),
                            'previews' => $this->fs->getCachedRootPreviews($node, fn () => $this->tq->getRootPreviews($root)),
                        ];
                    }, $this->logger);
                } catch (\Throwable $e) {
                    // Same reasoning as roots() above: a favorited folder living
                    // on an unavailable mount shouldn't break the whole tile grid.
                    $this->logger->warning("Skipping unreachable favorite {$path}: {$e->getMessage()}", ['app' => 'memories']);
                }
            }

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }
}
