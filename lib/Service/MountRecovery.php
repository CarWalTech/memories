<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\Files\StorageNotAvailableException;
use Psr\Log\LoggerInterface;

/**
 * Recovers from a transiently-unavailable external storage mount.
 *
 * Nextcloud's own Availability wrapper (\OC\Files\Storage\Wrapper\Availability)
 * only rechecks a mount marked unavailable once every 10 minutes (30 for auth
 * failures). Opening Settings > External Storage shortcuts that wait, because
 * that page forces a fresh connection test immediately
 * (\OCA\Files_External\MountConfig::getBackendStatus()). This reproduces that
 * same forced recheck in-process, so a single request or indexing pass
 * doesn't have to wait out the TTL once the mount is actually back -- and
 * doesn't need a visit to the admin settings page to notice.
 */
final class MountRecovery
{
    /**
     * Run $op. If it fails because its storage is unavailable, force a fresh
     * availability check on the external storage mount backing $path (if any)
     * and retry $op once.
     *
     * @template T
     *
     * @param \Closure(): T $op
     *
     * @return T
     *
     * @throws \Throwable the original failure, if it's unrelated to storage
     *                     availability, or if recovery+retry also fails
     */
    public static function retry(string $path, \Closure $op, LoggerInterface $logger): mixed
    {
        try {
            return $op();
        } catch (StorageNotAvailableException $e) {
            if (!self::forceRecheck($path, $logger)) {
                throw $e;
            }

            return $op(); // one retry with a freshly-rechecked mount
        }
    }

    /**
     * Force an immediate availability recheck of the external storage mount
     * backing $path, bypassing the Availability wrapper's recheck TTL.
     *
     * @return bool true if a recheck was actually performed (i.e. $path is
     *              backed by an external storage mount)
     */
    private static function forceRecheck(string $path, LoggerInterface $logger): bool
    {
        // files_external may not be installed/enabled; this integration is
        // best-effort and must not hard-depend on it.
        if (!class_exists(\OCA\Files_External\Config\ExternalMountPoint::class)) {
            return false;
        }

        $mount = \OC\Files\Filesystem::getMountManager()->find($path);
        if (!$mount instanceof \OCA\Files_External\Config\ExternalMountPoint) {
            return false;
        }

        try {
            $config = $mount->getStorageConfig();
            $backend = $config->getBackend();

            // Same call occ files_external:verify makes: connects right now
            // and persists the result, instead of waiting for the TTL.
            \OCA\Files_External\MountConfig::getBackendStatus(
                $backend->getStorageClass(),
                $config->getBackendOptions(),
            );
        } catch (\Throwable $e) {
            // getBackendStatus() rethrows on a failed test -- expected here,
            // it still recorded the (still-unavailable) status for us.
            $logger->info("Recheck confirmed mount for {$path} is still unavailable: {$e->getMessage()}", ['app' => 'memories']);
        }

        // The availability row we just wrote is cached in-process by
        // \OC\Files\Cache\StorageGlobal for the rest of this request. Without
        // clearing it, the retry above would still see the pre-recheck value
        // even though the database row is already fresh.
        \OC\Files\Cache\Storage::getGlobalCache()->clearCache();

        return true;
    }
}
