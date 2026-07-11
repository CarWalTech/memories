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
     * Queue a full, forced re-index of the current user's own library.
     * Runs as a background job (not synchronously) since a whole library
     * can be tens of thousands of files.
     */
    #[NoAdminRequired]
    public function refresh(IJobList $jobList): Http\Response
    {
        return Util::guardEx(function () use ($jobList) {
            $uid = Util::getUID();

            // Avoid piling up duplicate jobs if the user double-clicks.
            if (!$jobList->has(ForceReindexJob::class, ['uid' => $uid])) {
                $jobList->add(ForceReindexJob::class, ['uid' => $uid]);
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
     */
    #[NoAdminRequired]
    public function refreshFolder(string $path, bool $recursive, Service\Index $indexer, IJobList $jobList): Http\Response
    {
        return Util::guardEx(function () use ($path, $recursive, $indexer, $jobList) {
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
            $indexer->continueCheck = static fn (): bool => (microtime(true) - $startTime) < self::SYNC_BUDGET_SECONDS;

            Exif::ensureStaticExiftoolProc();
            $needsBackground = false;

            try {
                // Pass the validated, user-relative path -- forceReindexUser()/
                // resolveIndexPaths() resolve paths via $root->get($path) where
                // $root is this same user's root folder, same convention as the
                // CLI's --path option (relative, not the Node's absolute getPath()).
                $indexer->forceReindexUser($user, $sanitized, $recursive);
            } catch (Service\ProcessClosedException) {
                $needsBackground = true;
            }

            if (!$needsBackground) {
                Exif::closeStaticExiftoolProc();

                return new JSONResponse(['done' => true], Http::STATUS_OK);
            }

            // Queue the durable safety-net job regardless of whether we can
            // also continue immediately below.
            $argument = ['uid' => $uid, 'path' => $sanitized, 'recursive' => $recursive];
            if (!$jobList->has(ForceReindexJob::class, $argument)) {
                $jobList->add(ForceReindexJob::class, $argument);
            }

            if (!\function_exists('fastcgi_finish_request')) {
                // No FPM available (CLI-server/mod_php) -- the queued job
                // above is the only way the rest of this gets done.
                Exif::closeStaticExiftoolProc();

                return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
            }

            return Util::guardExDirect(function (Http\IOutput $out) use ($indexer, $user, $sanitized, $recursive) {
                $out->setHttpResponseCode(Http::STATUS_ACCEPTED);
                $out->setHeader('Content-Type: application/json');
                $out->setOutput(json_encode(['queued' => true, 'background' => true]));

                // Client is done waiting as of here -- everything past this
                // point runs regardless of whether they're still connected.
                set_time_limit(0);
                fastcgi_finish_request();

                $indexer->continueCheck = null;

                try {
                    $indexer->forceReindexUser($user, $sanitized, $recursive);
                } catch (\Exception $e) {
                    $this->logger->error('Background continuation of folder refresh failed: '.$e->getMessage(), ['app' => Application::APPNAME]);
                } finally {
                    Exif::closeStaticExiftoolProc();
                }
            });
        });
    }
}
