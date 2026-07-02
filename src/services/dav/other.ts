import { getFiles } from './base';
import client from './client';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import type { ClusterTypes, IPhoto } from '@typings';

/**
 * Open the files app with the given photo
 * Opens a new window.
 */
export async function viewInFolder(photo: IPhoto) {
  if (!photo) return;
  const files = await getFiles([photo]);
  if (files.length === 0) return;

  const url = viewInFolderUrl(files[0]);
  window.open(url, '_blank');
}

/**
 * Gets the view in folder url for the given file.
 */
export function viewInFolderUrl({ filename, fileid }: { filename: string; fileid: number }) {
  // ensure dirPath starts with a slash
  let dirPath = filename.substring(0, filename.lastIndexOf('/'));
  if (!dirPath.startsWith('/')) {
    dirPath = `/${dirPath}`;
  }

  /** @todo Doesn't seem to work on Nextcloud 28 */
  return API.Q(generateUrl('/apps/files/'), {
    dir: dirPath,
    scrollto: fileid,
    openfile: fileid,
  });
}

/**
 * Create a new folder at the given path (relative to the user's root).
 *
 * @param path Path of the new folder, e.g. `/Photos/Vacation`
 */
export async function createFolder(path: string): Promise<void> {
  await client.createDirectory(`files/${utils.uid}${path}`);
}

/**
 * Prompt the user for a name and create a new folder under the given base path.
 * Shared by every "New folder" entry point (top bar, empty-state action, etc).
 *
 * @param basePath Path of the parent folder, relative to the user's root
 * @returns The name of the created folder, or null if cancelled/invalid/failed
 */
export async function createFolderWithPrompt(basePath: string): Promise<string | null> {
  const name = (
    await utils.prompt({
      title: t('memories', 'New folder'),
      message: t('memories', 'Enter a name for the new folder'),
      name: t('memories', 'Name'),
    })
  )?.trim();

  if (!name) return null;

  if (name.includes('/')) {
    showError(t('memories', 'Folder name cannot contain "/"'));
    return null;
  }

  try {
    await createFolder(`${basePath}/${name}`.replace(/\/+/g, '/'));
  } catch (e: any) {
    showError(
      e?.status === 405
        ? t('memories', 'A folder with this name already exists')
        : t('memories', 'Failed to create folder'),
    );
    return null;
  }

  return name;
}

/**
 * Rename (move) a folder, failing if the destination already exists.
 *
 * @param oldPath Current path of the folder, relative to the user's root
 * @param newPath New path of the folder, relative to the user's root
 */
export async function renameFolder(oldPath: string, newPath: string): Promise<void> {
  await client.moveFile(
    `files/${utils.uid}${oldPath}`,
    `files/${utils.uid}${newPath}`,
    // @ts-ignore - https://github.com/perry-mitchell/webdav-client/issues/329
    { headers: { Overwrite: 'F' } },
  );
}

/**
 * Delete a folder at the given path (relative to the user's root).
 * The folder is moved to the Nextcloud trash, same as deleting via the Files app.
 *
 * @param path Path of the folder to delete
 */
export async function deleteFolder(path: string): Promise<void> {
  await client.deleteFile(`files/${utils.uid}${path}`);
}

/**
 * Set the cluster cover image
 *
 * @param photo The photo to set as the cover
 */
export async function setClusterCover(photo: IPhoto): Promise<boolean> {
  if (!photo) return false;

  // Get cluster type from route name (fragile)
  const clusterType = _m.route.name as ClusterTypes;

  // Get other parameters
  const { fileid } = photo;
  let { user, name } = _m.route.params;

  if ([_m.routes.Recognize.name, _m.routes.Albums.name].includes(_m.route.name ?? String())) {
    name = `${user}/${name}`;
  }

  try {
    await axios.post(API.CLUSTER_SET_COVER(clusterType), { name, fileid });
    showSuccess(t('memories', 'Cover image set successfully'));
    return true;
  } catch (err) {
    showError(t('memories', 'Failed to set cover image'));
    console.error(err);
    return false;
  }
}
