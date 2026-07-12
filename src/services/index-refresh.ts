import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';

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
