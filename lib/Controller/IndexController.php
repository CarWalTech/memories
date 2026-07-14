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

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Cron\ForceReindexJob;
use OCA\Memories\Exceptions;
use OCA\Memories\Exif;
use OCA\Memories\Service;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

final class IndexController extends GenericApiController
{
    /**
     * How long a folder refresh is allowed to run in the same HTTP request
     * before handing the rest off to a background job. Most folder refreshes
     * are small (a handful to a few hundred files) and should feel roughly as
     * fast as browsing the folder in the Files app -- only a folder with more
     * work than fits in this budget falls back to a background job.
     */
    private const SYNC_BUDGET_SECONDS = 15;

    /**
     * Sync budget for opportunistic scans triggered by folder navigation
     * (as opposed to the user explicitly clicking "Refresh Folder" and
     * waiting on it). These fire on every Folders-view navigation, so they
     * must yield the PHP-FPM worker quickly instead of competing with the
     * page's own concurrent thumbnail/day requests -- the existing
     * background-continuation fallback below picks up anything that doesn't
     * fit in this shorter budget.
     */
    private const SYNC_BUDGET_SECONDS_OPPORTUNISTIC = 3;

    /**
     * Queue a full, forced re-index of the current user's own library.
     * Runs as a background job (not synchronously) since a whole library
     * can be tens of thousands of files.
     *
     * @param string $mode 'all' (default, force re-read every file's metadata)
     *                     or 'untracked' (cheap: only scan for and index
     *                     new/missing files, skip already-indexed ones)
     */
    #[NoAdminRequired]
    public function refresh(IJobList $jobList, string $mode = 'all'): Http\Response
    {
        return Util::guardEx(static function () use ($jobList, $mode) {
            $mode = 'untracked' === $mode ? 'untracked' : 'all';
            $uid = Util::getUID();

            // Avoid piling up duplicate jobs if the user double-clicks.
            $argument = ['uid' => $uid, 'mode' => $mode];
            if (!$jobList->has(ForceReindexJob::class, $argument)) {
                $jobList->add(ForceReindexJob::class, $argument);
            }

            return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
        });
    }

    /**
     * Force a re-index of just the given folder for the current user,
     * optionally including subfolders. Tries to finish synchronously within
     * SYNC_BUDGET_SECONDS -- most folders are small enough that this
     * completes in well under a second, same as browsing the folder in the
     * Files app.
     *
     * A folder with more work than fits in that budget falls back to
     * responding immediately and continuing the rest of the work in this
     * same worker process via fastcgi_finish_request() -- this keeps running
     * even if the user navigates away, since the client connection is
     * already closed by that point, and avoids depending on Nextcloud's
     * cron.php cadence entirely. A resumable background job is also queued
     * as a durable safety net in case this worker gets killed mid-continuation
     * (e.g. a server restart) -- it'll simply find nothing left to do if the
     * continuation already finished (already-reprocessed files are no longer
     * orphaned).
     *
     * @param string $mode       'all' (default, force re-read every file's metadata)
     *                           or 'untracked' (cheap: only scan for and index
     *                           new/missing files, skip already-indexed ones)
     * @param bool   $background Whether this is an opportunistic scan fired by
     *                           folder navigation rather than a user explicitly
     *                           waiting on a "Refresh Folder" click -- uses the
     *                           shorter SYNC_BUDGET_SECONDS_OPPORTUNISTIC budget
     *                           so it can't hog a worker as long
     */
    #[NoAdminRequired]
    public function refreshFolder(string $path, bool $recursive, Service\Index $indexer, IJobList $jobList, string $mode = 'all', bool $background = false): Http\Response
    {
        return Util::guardEx(function () use ($path, $recursive, $indexer, $jobList, $mode, $background) {
            $mode = 'untracked' === $mode ? 'untracked' : 'all';
            $user = Util::getUser();
            $uid = $user->getUID();

            $sanitized = Util::sanitizePath($path);
            if (null === $sanitized) {
                throw Exceptions::BadRequest("Invalid path: {$path}");
            }

            $userFolder = $this->rootFolder->getUserFolder($uid);

            try {
                $node = $userFolder->get($sanitized);
            } catch (NotFoundException) {
                throw Exceptions::NotFound("Folder not found: {$path}");
            }

            if (!$node instanceof Folder) {
                throw Exceptions::BadRequest("Not a folder: {$path}");
            }

            $startTime = microtime(true);
            $budget = $background ? self::SYNC_BUDGET_SECONDS_OPPORTUNISTIC : self::SYNC_BUDGET_SECONDS;
            $indexer->continueCheck = static fn (): bool => (microtime(true) - $startTime) < $budget;

            Exif::ensureStaticExiftoolProc();
            $needsBackground = false;

            $doReindex = static function () use ($indexer, $user, $sanitized, $recursive, $mode): void {
                // Pass the validated, user-relative path -- forceReindexUser()/
                // scanForUntrackedFiles()/resolveIndexPaths() resolve paths via
                // $root->get($path) where $root is this same user's root folder,
                // same convention as the CLI's --path option (relative, not the
                // Node's absolute getPath()).
                if ('untracked' === $mode) {
                    $indexer->scanForUntrackedFiles($user, $sanitized, $recursive);
                } else {
                    $indexer->forceReindexUser($user, $sanitized, $recursive);
                }
            };

            try {
                $doReindex();
            } catch (Service\ProcessClosedException) {
                $needsBackground = true;
            }

            if (!$needsBackground) {
                Exif::closeStaticExiftoolProc();

                return new JSONResponse(['done' => true], Http::STATUS_OK);
            }

            // Queue the durable safety-net job regardless of whether we can
            // also continue immediately below.
            $argument = ['uid' => $uid, 'path' => $sanitized, 'recursive' => $recursive, 'mode' => $mode];
            if (!$jobList->has(ForceReindexJob::class, $argument)) {
                $jobList->add(ForceReindexJob::class, $argument);
            }

            if (!\function_exists('fastcgi_finish_request')) {
                // No FPM available (CLI-server/mod_php) -- the queued job
                // above is the only way the rest of this gets done.
                Exif::closeStaticExiftoolProc();

                return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
            }

            return Util::guardExDirect(function (Http\IOutput $out) use ($indexer, $doReindex): void {
                $out->setHttpResponseCode(Http::STATUS_ACCEPTED);
                $out->setHeader('Content-Type: application/json');
                $out->setOutput(json_encode(['queued' => true, 'background' => true]));

                // Client is done waiting as of here -- everything past this
                // point runs regardless of whether they're still connected.
                set_time_limit(0);
                fastcgi_finish_request();

                $indexer->continueCheck = null;

                try {
                    $doReindex();
                } catch (\Exception $e) {
                    $this->logger->error('Background continuation of folder refresh failed: '.$e->getMessage(), ['app' => Application::APPNAME]);
                } finally {
                    Exif::closeStaticExiftoolProc();
                }
            });
        });
    }
}
