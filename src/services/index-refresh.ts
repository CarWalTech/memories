import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { bus } from '@services/utils';

/**
 * Queue a full, forced re-index of the current user's own library.
 * Runs as a background job server-side; this just enqueues it and toasts.
 *
 * @param mode 'all' (default, force re-read every file's metadata) or
 *             'untracked' (cheap: only scan for and index new/missing
 *             files, skip already-indexed ones)
 */
export async function requestFullIndexRefresh(mode: 'all' | 'untracked' = 'all'): Promise<void> {
  try {
    await axios.post(API.INDEX_REFRESH(), { mode });
    showSuccess(
      'untracked' === mode
        ? t('memories', 'Scanning for untracked files queued')
        : t('memories', 'Full index refresh queued'),
    );
  } catch (e) {
    showError(t('memories', 'Failed to queue index refresh'));
  }
}

/**
 * Folder paths that have already been auto-scanned for untracked files this
 * session, and when. Keeps navigating back and forth through folders from
 * re-hitting the server on every click -- only re-scans a given folder once
 * the entry goes stale.
 */
const autoScannedFolders = new Map<string, number>();

/** Re-scan a folder automatically at most this often. */
const AUTO_SCAN_TTL_MS = 5 * 60 * 1000;

/**
 * Best-effort, silent scan of a folder for files that exist on disk but
 * aren't indexed yet -- e.g. dropped onto external storage outside
 * Nextcloud's own upload path, where the usual write-event listener never
 * fires. Meant to be called on every Folders-view/Embedded-Tags-view
 * navigation so browsing the library opportunistically keeps it fresh,
 * without waiting on the periodic background index job (which, being a
 * single cron-driven process, is one stuck run away from silently not
 * catching up for arbitrarily long).
 *
 * Rate-limited per folder+recursive-flag pair: the cost of a cheap
 * "anything new here?" check is bounded by AUTO_SCAN_TTL_MS, while genuinely
 * new files still get indexed for real (synchronously, up to the server's
 * usual budget, with its existing background fallback for large folders)
 * via the same endpoint the manual "Refresh Folder" action uses.
 *
 * Always runs in 'untracked' mode -- cheap, only catches files Nextcloud
 * has never seen. It deliberately does NOT catch metadata edited on an
 * already-indexed file's backend (e.g. re-tagging in Lightroom directly on
 * a NAS share) -- detecting that requires an mtime-refreshing rescan, too
 * expensive to run opportunistically on every navigation for a large
 * external mount. That case still depends on the periodic index job or a
 * manual refresh.
 *
 * Errors are swallowed -- this runs on every navigation, so it must never
 * toast or interrupt browsing. The manual refresh actions remain available
 * for the user to retry by hand.
 *
 * @param path      folder to scan
 * @param recursive whether to scan subfolders too (Folders view only scans
 *                  the single browsed folder; Embedded Tags aren't tied to
 *                  one folder, so it scans the whole configured root instead)
 */
export async function autoScanFolderOnNavigate(path: string, recursive = false): Promise<void> {
  const key = `${recursive ? 'r:' : 'f:'}${path}`;
  const now = Date.now();
  const last = autoScannedFolders.get(key);
  if (last && now - last < AUTO_SCAN_TTL_MS) return;
  autoScannedFolders.set(key, now); // mark eagerly to dedupe concurrent triggers

  try {
    const res = await axios.post<{ done?: boolean }>(API.INDEX_REFRESH_FOLDER(), {
      path,
      recursive,
      mode: 'untracked',
      background: true,
    });

    if (res.data.done) {
      bus.emit('memories:timeline:soft-refresh', null);
    }
  } catch {
    autoScannedFolders.delete(key); // allow a retry on the next visit
  }
}
