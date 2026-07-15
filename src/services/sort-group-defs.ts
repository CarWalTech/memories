import { translate as t } from '@services/l10n';

import type { RouteId } from '../route-ids';

export type SortKey = 'name' | 'size' | 'updated' | 'created' | 'count';
export type GroupKey = 'none' | 'day' | 'week' | 'month' | 'year' | 'name';
export type SortDir = 'asc' | 'desc';

export interface SortSetting {
  key: SortKey;
  dir: SortDir;
}

export interface GroupSetting {
  key: GroupKey;
  dir: SortDir;
}

/**
 * Some routes host more than one independently-sortable collection:
 * - Albums/Tags both use the *same* RouteId for both "the list of X" (no
 *   item open) and "the photos inside one X" (an item open) -- these need
 *   separate Sort/Group settings, so the list variant gets its own
 *   pseudo-target here (see `listScopeTargetFor`). Recognize/FaceRecognition/
 *   Places don't have a list-variant target -- their cluster lists have no
 *   sortable/groupable backend fields, so Sort by/Group by isn't offered on
 *   those list pages at all (see `ViewOptionsAction.vue`'s `hasSortGroup`);
 *   opened-person/place photo browsing still uses the plain `Recognize`/
 *   `FaceRecognition`/`Places` RouteId key below like any other photo grid.
 * - Folders and EmbeddedTags each show a tile row (subfolders / sub-tags)
 *   *and* the photo timeline together on one page -- `FoldersTiles` /
 *   `EmbeddedTagsTiles` are a second, independent target for that tile row,
 *   used directly by whatever renders it (not derived from route state the
 *   way the List variants above are).
 */
export type ListScopeTarget = RouteId | 'FoldersTiles' | 'EmbeddedTagsTiles' | 'AlbumsList' | 'TagsList';

export interface SortKeyDef {
  key: SortKey;
  label: string;
}

export interface ListScopeDef {
  sortKeys: SortKeyDef[];
  /** 'none' is always offered first and is implicit -- omit it here. */
  groupKeys: Exclude<GroupKey, 'none'>[];
  defaultSort: SortSetting;
  defaultGroup: GroupSetting;
}

const filenameSort = (): SortKeyDef[] => [
  { key: 'name', label: t('memories', 'Filename') },
  { key: 'updated', label: t('memories', 'Last updated') },
  { key: 'created', label: t('memories', 'Creation date') },
];

/** Every "grid of photos" view: Timeline, opened Folders/Albums/Tags, Map, opened person/place, etc. */
const TIMELINE_FAMILY_DEF: ListScopeDef = {
  sortKeys: filenameSort(),
  groupKeys: ['day', 'week', 'month', 'year'],
  defaultSort: { key: 'created', dir: 'desc' },
  defaultGroup: { key: 'day', dir: 'desc' },
};

/** Same as the Timeline family, but the current default groups by month (opened Albums). */
const TIMELINE_FAMILY_MONTH_DEF: ListScopeDef = {
  ...TIMELINE_FAMILY_DEF,
  defaultGroup: { key: 'month', dir: 'desc' },
};

const FOLDERS_TILES_DEF: ListScopeDef = {
  sortKeys: [
    { key: 'name', label: t('memories', 'Folder Name') },
    { key: 'size', label: t('memories', 'File size') },
    { key: 'updated', label: t('memories', 'Last updated') },
  ],
  groupKeys: ['name'],
  defaultSort: { key: 'name', dir: 'asc' },
  defaultGroup: { key: 'none', dir: 'asc' },
};

/** Sub-tag tiles shown alongside an EmbeddedTags page's photo timeline. Unlike FoldersTiles, the backend only returns name+count per child tag -- no size/mtime. */
const EMBEDDED_TAGS_TILES_DEF: ListScopeDef = {
  sortKeys: [
    { key: 'name', label: t('memories', 'Tag Name') },
    { key: 'count', label: t('memories', 'Photo count') },
  ],
  groupKeys: ['name'],
  defaultSort: { key: 'name', dir: 'asc' },
  defaultGroup: { key: 'none', dir: 'asc' },
};

const ALBUMS_LIST_DEF: ListScopeDef = {
  sortKeys: [
    { key: 'name', label: t('memories', 'Album name') },
    { key: 'updated', label: t('memories', 'Last updated') },
    { key: 'created', label: t('memories', 'Creation date') },
  ],
  groupKeys: ['name'],
  defaultSort: { key: 'updated', dir: 'desc' },
  defaultGroup: { key: 'none', dir: 'asc' },
};

const TAGS_LIST_DEF: ListScopeDef = {
  sortKeys: [
    { key: 'name', label: t('memories', 'Tag name') },
    { key: 'size', label: t('memories', 'File size') },
    { key: 'updated', label: t('memories', 'Last updated') },
    { key: 'created', label: t('memories', 'Creation date') },
    { key: 'count', label: t('memories', 'Photo count') },
  ],
  groupKeys: ['name'],
  defaultSort: { key: 'name', dir: 'asc' },
  defaultGroup: { key: 'none', dir: 'asc' },
};

export const LIST_SCOPE_DEFS: Partial<Record<ListScopeTarget, ListScopeDef>> = {
  Base: TIMELINE_FAMILY_DEF,
  AllPhotos: TIMELINE_FAMILY_DEF,
  Favorites: TIMELINE_FAMILY_DEF,
  Videos: TIMELINE_FAMILY_DEF,
  Archive: TIMELINE_FAMILY_DEF,
  ThisDay: TIMELINE_FAMILY_DEF,
  Map: TIMELINE_FAMILY_DEF,
  Folders: TIMELINE_FAMILY_DEF,
  FolderShare: TIMELINE_FAMILY_DEF,
  EmbeddedTags: TIMELINE_FAMILY_DEF,
  AlbumShare: TIMELINE_FAMILY_MONTH_DEF,
  Albums: TIMELINE_FAMILY_MONTH_DEF,
  Tags: TIMELINE_FAMILY_DEF,
  Recognize: TIMELINE_FAMILY_DEF,
  FaceRecognition: TIMELINE_FAMILY_DEF,
  Places: TIMELINE_FAMILY_DEF,

  FoldersTiles: FOLDERS_TILES_DEF,
  EmbeddedTagsTiles: EMBEDDED_TAGS_TILES_DEF,
  AlbumsList: ALBUMS_LIST_DEF,
  TagsList: TAGS_LIST_DEF,
};

const FALLBACK_DEF: ListScopeDef = TIMELINE_FAMILY_DEF;

export function getListScopeDef(target: ListScopeTarget | null): ListScopeDef {
  if (target == null) return FALLBACK_DEF;
  return LIST_SCOPE_DEFS[target] ?? FALLBACK_DEF;
}

/** Routes where the same RouteId serves both "the list" (no item open) and "an opened item's photos". */
const LIST_VARIANT_FOR: Partial<Record<RouteId, ListScopeTarget>> = {
  Albums: 'AlbumsList',
  Tags: 'TagsList',
};

/**
 * Resolve which `ListScopeTarget` governs the *primary* content of a route
 * (i.e. what `ViewOptionsAction`'s Sort by / Group by panels should use).
 * Doesn't cover `FoldersTiles`, which is a second, independent target for
 * the subfolder-tile row shown alongside a Folders page's main content --
 * callers rendering that row use the `'FoldersTiles'` target directly.
 */
export function listScopeTargetFor(routeId: RouteId | null, route: { params: Record<string, any> }): ListScopeTarget | null {
  if (routeId == null) return null;

  const listVariant = LIST_VARIANT_FOR[routeId];
  if (listVariant && !route.params.name) {
    return listVariant;
  }

  return routeId;
}
