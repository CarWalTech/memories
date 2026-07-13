<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Service;

use OC\Files\SetupManager;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\Files\Cache\IScanner;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class Index
{
    public ?OutputInterface $output = null;
    public ?ConsoleSectionOutput $section = null;
    public bool $verbose = false;

    /**
     * Callback to check if the process should continue.
     * This is called before every file is indexed.
     *
     * @var null|\Closure(): bool
     */
    public ?\Closure $continueCheck = null;

    /** @var string[] */
    private static ?array $mimeList = null;

    public function __construct(
        private IRootFolder $rootFolder,
        private TimelineWrite $tw,
        private IDBConnection $db,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
        private IAppManager $appManager,
        private SetupManager $setupManager,
        private IUserManager $userManager,
    ) {}

    /**
     * Index all files for a user.
     */
    public function indexUser(IUser $user, ?string $path = null): void
    {
        if (!$this->appManager->isEnabledForUser('memories', $user)) {
            return;
        }

        $uid = $user->getUID();

        $this->log("<info>Indexing user {$uid}</info>".PHP_EOL, true);

        $this->setupManager->tearDown();
        $this->setupManager->setupForUser($user);

        // Get the root folder of the user
        $root = $this->rootFolder->getUserFolder($uid);

        // Get paths of folders to index
        $paths = $this->resolveIndexPaths($uid, $path);

        // If a folder is specified, traverse only that folder
        foreach ($paths as $path) {
            try {
                $node = $root->get($path);
            } catch (\Exception $e) {
                // Only log this if we're on the CLI, do not put an error in the logs
                // https://github.com/pulsejet/memories/issues/1091
                $this->log("<error>The specified folder {$path} does not exist for {$uid}</error>".PHP_EOL);

                continue;
            }

            try {
                MountRecovery::retry($node->getPath(), function () use ($node, $uid): void {
                    if ($node instanceof Folder) {
                        $this->indexFolder($node, $uid);
                    } elseif ($node instanceof File) {
                        $this->indexFile($node, $uid);
                    } else {
                        throw new \Exception('Not a file or folder');
                    }
                }, $this->logger);
            } catch (ProcessClosedException $e) {
                throw $e; // time-budget signal, not an error -- must propagate
            } catch (\Throwable $e) {
                // This path can be on a mount that's temporarily unavailable
                // (offline external storage, transient hiccup, ...). Don't let
                // it abort indexing of this user's other timeline paths -- skip
                // it and retry on the next scheduled run instead.
                $this->error("Failed to index {$path} for {$uid}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Rescan the filesystem and index new/changed files under $path (or the
     * user's configured index root if $path is null), without forcing
     * already-indexed files to be reprocessed. Lightweight counterpart to
     * forceReindexUser() -- surfaces filecache drift (e.g. a stale external
     * storage mount) without paying the cost of re-reading metadata that
     * hasn't actually changed. Relies entirely on indexFolder()'s existing
     * mtime-skip logic; nothing is orphaned here.
     *
     * @param bool $recursive if false, only files directly in $path are
     *                        scanned/indexed -- subfolders are left untouched.
     *                        Has no effect when $path is null.
     */
    public function scanForUntrackedFiles(IUser $user, ?string $path = null, bool $recursive = true): void
    {
        if (!$this->appManager->isEnabledForUser('memories', $user)) {
            return;
        }

        $uid = $user->getUID();
        $this->setupManager->tearDown();
        $this->setupManager->setupForUser($user);
        $root = $this->rootFolder->getUserFolder($uid);

        foreach ($this->resolveIndexPaths($uid, $path) as $p) {
            try {
                $node = $root->get($p);
            } catch (\Exception) {
                continue; // path may not exist, e.g. deleted custom index path
            }

            if ($node instanceof Folder) {
                $this->indexFolder($node, $uid, $recursive, rescan: true);
            } elseif ($node instanceof File) {
                $this->indexFile($node, $uid);
            }
        }
    }

    /**
     * Force re-processing of already-indexed files for a user, scoped to $path
     * (or the user's configured index root if $path is null). Marks the
     * matching rows orphaned so TimelineWrite::processFile()'s mtime-based
     * early-exit is bypassed, then reprocesses them.
     *
     * @param bool $recursive if false, only files directly in $path are
     *                        reprocessed -- subfolders are left untouched.
     *                        Has no effect when $path is null (that always
     *                        indexes full configured roots, same as indexUser()).
     *
     * SAFETY: only ever orphans an explicit, pre-collected list of fileIds
     * scoped to this user's own tree -- never calls orphanAll() with no fileIds.
     */
    public function forceReindexUser(IUser $user, ?string $path = null, bool $recursive = true): void
    {
        if (!$this->appManager->isEnabledForUser('memories', $user)) {
            return;
        }

        $uid = $user->getUID();
        $this->setupManager->tearDown();
        $this->setupManager->setupForUser($user);
        $root = $this->rootFolder->getUserFolder($uid);

        $fileIds = [];
        $files = []; // fileid => File, only needed for the non-recursive reprocess path
        foreach ($this->resolveIndexPaths($uid, $path) as $p) {
            try {
                $node = $root->get($p);
            } catch (\Exception) {
                continue; // path may not exist, e.g. deleted custom index path
            }

            if ($node instanceof Folder) {
                $this->collectFileIds($node, $fileIds, $files, $recursive);
            } elseif ($node instanceof File && self::isSupported($node) && self::isPathAllowed($node->getPath())) {
                $fileIds[] = $node->getId();
                $files[$node->getId()] = $node;
            }
        }

        foreach (array_chunk($fileIds, 250) as $chunk) {
            $this->tw->orphanAll(true, $chunk);
        }

        if ($recursive) {
            // Re-run the normal indexer; orphaned rows are now picked up as "needs reprocessing"
            $this->indexUser($user, $path);
        } else {
            // Only reprocess the direct files we just orphaned -- do not recurse into subfolders
            foreach ($files as $file) {
                $this->ensureContinueOk();
                $this->indexFile($file, $uid);
            }
        }
    }

    /**
     * Index all files in a folder.
     *
     * @param Folder $folder    folder to index
     * @param string $userId    user being indexed (for embedded tags ownership)
     * @param bool   $recursive if false, subfolders are not walked -- only
     *                          the files directly in $folder are indexed
     * @param bool   $rescan    force a fresh filesystem scan of $folder before
     *                          trusting its cached directory listing (see
     *                          rescanFolder()) -- off by default since the
     *                          regular cron/CLI indexing pass should stay cheap
     */
    public function indexFolder(Folder $folder, ?string $userId = null, bool $recursive = true, bool $rescan = false): void
    {
        $path = $folder->getPath();
        $this->log("Indexing folder {$path}", true);

        // Check if path is blacklisted
        if (!$this->isPathAllowed($path.'/')) {
            $this->log("Skipping folder {$path} (path excluded)".PHP_EOL, true);

            return;
        }

        // Check if folder contains exclusion file
        if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
            $this->log("Skipping folder {$path} (.nomedia / .nomemories)".PHP_EOL, true);

            return;
        }

        if ($rescan) {
            $this->rescanFolder($folder);
        }

        // Get all files and folders in this folders
        $nodes = $folder->getDirectoryListing();

        // Filter files that are supported
        $mimes = self::getMimeList();
        $files = array_filter($nodes, static fn ($n): bool => $n instanceof File
            && \in_array($n->getMimeType(), $mimes, true)
            && self::isPathAllowed($n->getPath()));

        // Create an associative array with file ID as key
        $files = array_combine(array_map(static fn ($n) => $n->getId(), $files), $files);

        // Chunk array into some files each (DBs have limitations on IN clause)
        $chunks = array_chunk($files, 250, true);

        // Check files in each chunk
        foreach ($chunks as $chunk) {
            $fileIds = array_keys($chunk);

            // Select all files in filecache
            $query = $this->db->getQueryBuilder();
            $query->select('f.fileid')
                ->from('filecache', 'f')
                ->where($query->expr()->in('f.fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($query->expr()->gt('f.size', $query->expr()->literal(0)))
            ;

            // Filter out files that are already indexed
            $getFilter = function (string $table, bool $notOrpaned) use (&$query): IQueryFunction {
                // Make subquery to check if file exists in table
                $clause = $this->db->getQueryBuilder();
                $clause->select($clause->expr()->literal(1))
                    ->from($table, 'a')
                    ->andWhere($clause->expr()->eq('f.fileid', 'a.fileid'))
                    ->andWhere($clause->expr()->eq('f.mtime', 'a.mtime'))
                ;

                // Filter only non-orphaned files
                if ($notOrpaned) {
                    $clause->andWhere($clause->expr()->eq('a.orphan', $clause->expr()->literal(0)));
                }

                // Add the clause to the main query
                return SQL::notExists($query, $clause);
            };

            // Filter out files that are already indexed or failed
            $query->andWhere($getFilter('memories', true));
            $query->andWhere($getFilter('memories_livephoto', true));
            $query->andWhere($getFilter('memories_failures', false));

            // Get file IDs to actually index
            $fileIds = Util::transaction(static fn (): array => $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

            // Index files
            foreach ($fileIds as $fileId) {
                $this->ensureContinueOk();
                $this->indexFile($chunk[$fileId], $userId);
            }

            // Process embedded tags for already-indexed files (for the current user).
            // The memories table is file-scoped (one row per fileid), so once User A
            // indexes a file, the SQL filter above skips it for User B. But
            // memories_embedded_tags is user-scoped, so each user needs their own entries.
            if ($userId) {
                $alreadyIndexed = array_diff(array_keys($chunk), $fileIds);

                if (!empty($alreadyIndexed)) {
                    $exifQuery = $this->db->getQueryBuilder();
                    $exifQuery->select('fileid', 'exif')
                        ->from('memories')
                        ->where($exifQuery->expr()->in(
                            'fileid',
                            $exifQuery->createNamedParameter(
                                array_values($alreadyIndexed),
                                IQueryBuilder::PARAM_INT_ARRAY,
                            ),
                        ))
                    ;
                    $rows = $exifQuery->executeQuery()->fetchAll();

                    foreach ($rows as $row) {
                        $fileId = (int) $row['fileid'];
                        if (empty($row['exif'])) {
                            continue;
                        }

                        $exif = json_decode($row['exif'], true);
                        if (empty($exif)) {
                            continue;
                        }

                        if (!isset($chunk[$fileId])) {
                            continue;
                        }

                        try {
                            $this->tw->processEmbeddedTags($chunk[$fileId], $exif, $userId);
                        } catch (\Exception $e) {
                            $this->error("Failed to process embedded tags for file {$fileId}: {$e->getMessage()}");
                        }
                    }
                }
            }
        }

        // All folders
        if ($recursive) {
            $folders = array_filter($nodes, static fn ($n) => $n instanceof Folder);
            foreach ($folders as $subfolder) {
                $this->ensureContinueOk();

                try {
                    $this->indexFolder($subfolder, $userId, true, $rescan);
                } catch (ProcessClosedException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $this->error("Failed to index folder {$subfolder->getPath()}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Index a single file.
     *
     * @param File        $file   file to index
     * @param null|string $userId user being indexed (for embedded tags ownership)
     */
    public function indexFile(File $file, ?string $userId = null): void
    {
        $path = $file->getPath();

        try {
            $this->log("Indexing file {$path}", true);
            $this->tw->processFile($file, userId: $userId);
        } catch (\OCP\Lock\LockedException $e) {
            $this->log("Skipping file {$path} due to lock", true);
        } catch (\Exception $e) {
            $this->error("Failed to index file {$path}: {$e->getMessage()}");
            $this->tw->markFailed($file, $e->getMessage());
        } finally {
            $this->tempManager->clean();
        }
    }

    /**
     * Cleanup all stale entries (passthrough to timeline write).
     */
    public function cleanupStale(): void
    {
        $this->log('<info>Cleaning up stale index entries</info>');
        $this->tw->cleanupStale();
    }

    /**
     * Get total number of files that are indexed.
     */
    public function getIndexedCount(): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count(SQL::distinct($query, 'fileid')))
            ->from('memories')
        ;

        return (int) $query->executeQuery()->fetchOne();
    }

    /**
     * Get list of MIME types to process.
     */
    public static function getMimeList(): array
    {
        return self::$mimeList ??= array_merge(
            self::getPreviewMimes(Application::IMAGE_MIMES),
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Get list of MIME types that have a preview.
     */
    public static function getPreviewMimes(array $source): array
    {
        $preview = \OC::$server->get(IPreview::class);

        return array_filter($source, static fn ($m) => $preview->isMimeSupported($m));
    }

    /**
     * Get list of all supported MIME types.
     */
    public static function getAllMimes(): array
    {
        return array_merge(
            Application::IMAGE_MIMES,
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Check if a file is supported.
     *
     * @param Node $file file to check
     */
    public static function isSupported(Node $file): bool
    {
        return \in_array($file->getMimeType(), self::getMimeList(), true);
    }

    /**
     * Check if a file is a video.
     *
     * @param Node $file file to check
     */
    public static function isVideo(Node $file): bool
    {
        return \in_array($file->getMimeType(), Application::VIDEO_MIMES, true);
    }

    /**
     * Checks if the specified node's path is allowed to be indexed.
     */
    public static function isPathAllowed(string $path): bool
    {
        // Always exclude some predefined patterns
        //   .trashed-<file> (https://github.com/nextcloud/android/issues/10645)
        if (preg_match('/\/.trashed-[^\/]*$/', $path)) {
            return false;
        }

        /** @var ?string $pattern */
        static $pattern = null;

        if (null === $pattern) {
            $pattern = trim(SystemConfig::get('memories.index.path.blacklist') ?: '');
            if (!empty($pattern) && !\is_int(preg_match("/{$pattern}/", ''))) {
                throw new \Exception('Invalid regex pattern in memories.index.path.blacklist');
            }
        }

        return empty($pattern) || !preg_match("/{$pattern}/", $path);
    }

    /**
     * Resolve the list of top-level paths to index for a user, honoring
     * memories.index.mode, unless an explicit $path override is given.
     *
     * @return string[]
     */
    private function resolveIndexPaths(string $uid, ?string $path): array
    {
        if (null !== $path) {
            return [$path];
        }

        $mode = SystemConfig::get('memories.index.mode');
        if ('1' === $mode || '0' === $mode) { // everything (or nothing)
            return ['/'];
        }
        if ('2' === $mode) { // timeline
            return Util::getTimelinePaths($uid);
        }
        if ('3' === $mode) { // custom
            return [SystemConfig::get('memories.index.path')];
        }

        throw new \Exception('Invalid index mode');
    }

    /**
     * Collect fileIds (and File nodes) of supported, allowed files under $folder,
     * mirroring indexFolder()'s own filtering (mimetype + isPathAllowed +
     * .nomedia/.nomemories exclusion) so we only orphan files the real
     * indexing pass would actually consider. When $recursive is false, only
     * files directly in $folder are collected -- subfolders are not walked.
     *
     * @param int[]           $fileIds accumulator, passed by reference
     * @param array<int,File> $files   fileid => File node accumulator, passed by reference
     */
    private function collectFileIds(Folder $folder, array &$fileIds, array &$files, bool $recursive): void
    {
        $path = $folder->getPath();
        if (!self::isPathAllowed($path.'/')) {
            return;
        }

        try {
            if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
                return;
            }

            $this->rescanFolder($folder);

            $mimes = self::getMimeList();
            foreach ($folder->getDirectoryListing() as $n) {
                if ($n instanceof File) {
                    if (\in_array($n->getMimeType(), $mimes, true) && self::isPathAllowed($n->getPath())) {
                        $fileIds[] = $n->getId();
                        $files[$n->getId()] = $n;
                    }
                } elseif ($recursive && $n instanceof Folder) {
                    $this->ensureContinueOk();
                    $this->collectFileIds($n, $fileIds, $files, true);
                }
            }
        } catch (ProcessClosedException $e) {
            throw $e; // time-budget signal, not an error -- must propagate
        } catch (\Throwable $e) {
            // A folder can be unreadable for all sorts of reasons (permissions,
            // a read-only/unavailable mount, a transient storage hiccup, ...).
            // Mirrors indexFolder()'s own per-subfolder handling below -- skip
            // it and keep going rather than losing everything collected so far
            // over one bad folder.
            $this->logger->warning("Skipping unreadable folder {$path}: {$e->getMessage()}", ['app' => Application::APPNAME]);
        }
    }

    /**
     * Force a fresh (shallow, this-folder-only) filesystem scan before trusting
     * $folder's cached directory listing. Nextcloud's own filecache can drift
     * out of sync with what's actually on disk -- especially over a slow or
     * lazily-revalidated external storage mount -- leaving real files
     * completely invisible to both this and the real indexing pass until
     * something rescans them. This is exactly what `occ files:scan` does
     * under the hood, just scoped to one folder via public OCP interfaces
     * (Storage::getScanner()) instead of the CLI command's machinery.
     *
     * Deliberately shallow: subfolders get their own scan when collectFileIds()
     * recurses into them (each call already goes through ensureContinueOk()),
     * so a huge/slow tree still scans incrementally and resumes across
     * multiple background-job runs instead of blocking on one giant recursive
     * scan with no way to interrupt it partway through.
     */
    private function rescanFolder(Folder $folder): void
    {
        try {
            $folder->getStorage()->getScanner()->scan($folder->getInternalPath(), IScanner::SCAN_SHALLOW);
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to rescan {$folder->getPath()}: {$e->getMessage()}", ['app' => Application::APPNAME]);
        }
    }

    /**
     * Log error to console if CLI or logger.
     */
    private function error(string $message): void
    {
        $this->logger->error($message, ['app' => 'memories']);
        $this->output?->writeln("<error>{$message}</error>".PHP_EOL);
    }

    /**
     * Log to console if CLI.
     */
    private function log(string $message, bool $overwrite = false): void
    {
        if ($this->section) {
            if ($overwrite && !$this->verbose) {
                $this->section->clear(1);
            }
            $this->section->write($message);
        }
    }

    /**
     * Ensure that the process should go on.
     */
    private function ensureContinueOk(): void
    {
        if (null !== $this->continueCheck && !($this->continueCheck)()) {
            throw new ProcessClosedException();
        }
    }
}
