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

namespace OCA\Memories\Cron;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * One-off, user-triggered force reindex, optionally scoped to a single
 * folder. Enqueued on demand from IndexController::refresh()/refreshFolder()
 * -- never registered in appinfo/info.xml, since QueuedJobs must only be
 * added programmatically with a specific uid argument, not auto-enqueued
 * at install/upgrade.
 *
 * Argument shape: ['uid' => string, 'path' => ?string, 'recursive' => bool].
 * 'path'/'recursive' are optional -- omitting 'path' (or passing null) means
 * the full configured index root(s) for the user, matching
 * Service\Index::forceReindexUser()'s own defaults.
 */
final class ForceReindexJob extends QueuedJob
{
    /**
     * Seconds this job is allowed to run per invocation before it bails out
     * and re-queues itself to continue on the next background-job tick.
     * Deliberately shorter than IndexJob's own 300s budget -- this job can
     * run alongside other queued jobs in the same cron.php invocation (it's
     * not the only thing scheduled), so it shouldn't monopolize the whole run,
     * especially over a slow/remote-mounted storage where a single scope can
     * involve many thousands of per-file network round trips.
     */
    private const MAX_RUN_TIME = 240;

    public function __construct(
        ITimeFactory $time,
        private Service\Index $service,
        private IUserManager $userManager,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    #[\Override]
    protected function run($argument): void
    {
        $uid = \is_array($argument) ? ($argument['uid'] ?? null) : null;
        if (!\is_string($uid) || '' === $uid) {
            $this->logger->error('ForceReindexJob: missing uid argument', ['app' => Application::APPNAME]);

            return;
        }

        $path = \is_array($argument) ? ($argument['path'] ?? null) : null;
        $recursive = \is_array($argument) ? (bool) ($argument['recursive'] ?? true) : true;

        $user = $this->userManager->get($uid);
        if (null === $user) {
            // User was deleted between enqueue and run; nothing to do.
            $this->logger->info("ForceReindexJob: user {$uid} no longer exists, skipping", ['app' => Application::APPNAME]);

            return;
        }

        $startTime = microtime(true);
        $this->service->continueCheck = static function () use ($startTime): bool {
            return (microtime(true) - $startTime) < self::MAX_RUN_TIME;
        };

        try {
            \OCA\Memories\Exif::ensureStaticExiftoolProc();
            $this->service->forceReindexUser($user, $path, $recursive);
        } catch (Service\ProcessClosedException) {
            // Ran out of time before finishing a large scope. Already-
            // reprocessed files are no longer orphaned, so re-queueing the
            // exact same arguments naturally resumes from where this left
            // off rather than starting over -- same self-resuming pattern
            // IndexJob relies on for its own 15-minute recurring runs.
            $this->logger->info("ForceReindexJob: ran out of time for {$uid}, re-queueing to continue", ['app' => Application::APPNAME]);
            $this->jobList->add(self::class, $argument);
        } catch (\Exception $e) {
            $this->logger->error('ForceReindexJob failed for '.$uid.': '.$e->getMessage(), ['app' => Application::APPNAME]);
        } finally {
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }
    }
}
