import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';

/**
 * Queue a full, forced re-index of the current user's own library.
 * Runs as a background job server-side; this just enqueues it and toasts.
 */
export async function requestFullIndexRefresh(): Promise<void> {
  try {
    await axios.post(API.INDEX_REFRESH());
    showSuccess(t('memories', 'Index refresh queued'));
  } catch (e) {
    showError(t('memories', 'Failed to queue index refresh'));
  }
}
