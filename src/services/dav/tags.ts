import { getLanguage } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import client from './client';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import type { ICluster } from '@typings';

export interface ITag {
  id: number;
  displayName: string;
  userVisible: boolean;
  userAssignable: boolean;
  canAssign: boolean;
}

/**
 * Get list of tags.
 */
export async function getTags() {
  const tags = (await axios.get<ICluster[]>(API.TAG_LIST())).data;

  // Translate tag names
  tags.forEach((tag) => (tag.display_name = t('recognize', tag.name)));

  // Sort tags by display name (locale aware)
  tags.sort((a, b) => a.display_name!.localeCompare(b.display_name!, getLanguage(), { numeric: true }));

  return tags;
}

/**
 * Get list of embedded (hierarchical EXIF/XMP) tags, as flat leaf-path clusters.
 */
export async function getEmbeddedTagClusters() {
  const tags = (await axios.get<ICluster[]>(API.EMBEDDED_TAG_LIST())).data;

  tags.forEach((tag) => (tag.display_name = tag.name));
  tags.sort((a, b) => b.count - a.count);

  return tags;
}

/**
 * Prompt the user for a name and create a new (empty) embedded tag under
 * the given path. Only touches the tag dictionary -- no photos are affected.
 *
 * @param path Path of the parent tag, or '' for a new root-level tag
 * @returns The name of the created tag, or null if cancelled/invalid/failed
 */
export async function createEmbeddedTagWithPrompt(path: string): Promise<string | null> {
  const name = (
    await utils.prompt({
      title: t('memories', 'New tag'),
      message: t('memories', 'Enter a name for the new tag'),
      name: t('memories', 'Name'),
    })
  )?.trim();

  if (!name) return null;

  if (name.includes('/')) {
    showError(t('memories', 'Tag name cannot contain "/"'));
    return null;
  }

  try {
    await axios.post(API.EMBEDDED_TAGS_CREATE(), { path, name });
  } catch (e: any) {
    showError(t('memories', 'Failed to create tag'));
    return null;
  }

  return name;
}

/**
 * Confirm and delete an embedded tag. Only succeeds if the tag and any
 * sub-tags have no photos -- the server enforces this and this never
 * touches photo EXIF data, only the tag dictionary.
 *
 * @param path Full path of the tag to delete
 * @param displayName Name shown in the confirmation dialog
 * @returns true if deleted, false if cancelled or failed
 */
export async function deleteEmbeddedTagWithConfirm(path: string, displayName: string): Promise<boolean> {
  const confirmed = await utils.confirmDestructive({
    title: t('memories', 'Delete tag'),
    message: t('memories', 'Are you sure you want to delete "{name}"? This is only possible if it has no photos.', {
      name: displayName,
    }),
  });

  if (!confirmed) return false;

  try {
    await axios.delete(API.Q(API.EMBEDDED_TAGS_DELETE(), { path }));
  } catch (e: any) {
    showError(
      400 === e?.response?.status
        ? t('memories', 'Cannot delete: tag still has photos. Remove the tag from all photos first.')
        : t('memories', 'Failed to delete tag'),
    );
    return false;
  }

  return true;
}

/**
 * Create a new tag.
 *
 * https://github.com/nextcloud/server/blob/9a7e2b15580578b4de3eb36808dc466a9fd6b976/apps/systemtags/src/services/api.ts#L101-L127
 */
export async function createTag(tag: ITag): Promise<ITag> {
  const path = '/systemtags';
  const postData = {
    ...tag,
    name: tag.displayName, // weird
    id: undefined,
  };

  try {
    const { headers } = await client.customRequest(path, {
      method: 'POST',
      data: postData,
    });

    const contentLocation = headers.get('content-location');
    if (contentLocation) {
      return {
        ...tag,
        id: parseIdFromLocation(contentLocation),
      };
    }

    throw new Error(t('memories', 'No content-location header found'));
  } catch (error) {
    if (error?.status === 409) {
      // Tag already exists. Now this may happen e.g. if the tag isn't
      // assignable or visible to the user and cause problems later.
      // But that's not our problem here.
      return tag;
    }

    throw new Error(
      t('memories', 'Failed to create tag {name}: {error}', {
        name: tag.displayName,
        error: error.message,
      }),
    );
  }
}

/**
 * Parse id from `Content-Location` header
 * https://github.com/nextcloud/server/blob/9a7e2b15580578b4de3eb36808dc466a9fd6b976/apps/systemtags/src/utils.ts#L36C1-L55C2
 */
function parseIdFromLocation(url: string): number {
  const queryPos = url.indexOf('?');
  if (queryPos > 0) {
    url = url.substring(0, queryPos);
  }

  const parts: string[] = url.split('/');
  let result: string | undefined;
  do {
    result = parts[parts.length - 1];
    parts.pop();
    // note: first result can be empty when there is a trailing slash,
    // so we take the part before that
  } while (!result && parts.length > 0);

  return Number(result);
}
