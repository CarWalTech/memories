import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import * as helpers from '@services/utils/helpers';
import staticConfig from '@services/static-config';

import { ROUTE_NAMES, ALL_ROUTE_IDS, type RouteId } from '../route-ids';

export type { RouteId };
export type ImageDisplayShape = 'dynamic' | 'square' | 'list';

const DEFAULT_SHAPE: ImageDisplayShape = 'dynamic';

interface ViewModeScopeData {
  /** Per-view-type base, e.g. "every Folders page defaults to List". */
  view: Partial<Record<RouteId, ImageDisplayShape>>;
  /** Per-specific-item override; key is `${routeId}:${scopeKey}`. Presence of a key IS the toggle-on state. */
  item: Record<string, ImageDisplayShape>;
}

const emptyData = (): ViewModeScopeData => ({ view: {}, item: {} });

/** route.name -> RouteId, built once from the dependency-free route-ids table (see its file comment for why). */
const NAME_TO_ROUTE_ID: Record<string, RouteId> = Object.fromEntries(
  Object.entries(ROUTE_NAMES).map(([id, name]) => [name, id as RouteId]),
);

export function routeIdForName(name: string | null | undefined): RouteId | null {
  if (!name) return null;
  return NAME_TO_ROUTE_ID[name] ?? null;
}

/**
 * Scope key for the per-item View Mode toggle. Only defined for routes with
 * a real sub-identity (a specific folder, album, or tag) -- every other
 * route only ever has a view-type base, no item-level override.
 *
 * `params`/`query`/`foldersPath` must be passed in explicitly by callers
 * inside a Vue computed property (see the reactivity note on
 * `getFolderRoutePath`) rather than read from the non-reactive `_m.route`
 * global.
 */
export function scopeKeyFor(
  routeId: RouteId | null,
  params: Record<string, any>,
  query: Record<string, any>,
  foldersPath: string,
): string | null {
  const pathParts = (params.path ?? []) as string[] | string;
  const joinedPath = (typeof pathParts === 'string' ? pathParts.split('/') : pathParts).filter(Boolean).join('/');

  switch (routeId) {
    case 'Folders':
      return helpers.getFolderRoutePath(foldersPath, params.path, !!query.abs);
    case 'FolderShare':
      return `${params.token}:${joinedPath}`;
    case 'EmbeddedTags':
      // Tag-path hierarchy, unrelated to the configured Folders roots -- just the raw path.
      return joinedPath;
    case 'Albums':
      return params.name ? `${params.user}/${params.name}` : null;
    case 'AlbumShare':
      return params.token ?? null;
    case 'Tags':
      return params.name ?? null;
    default:
      return null;
  }
}

class ViewModeScope {
  private data = Vue.observable<ViewModeScopeData>(emptyData());
  private initialized = false;

  public async init() {
    if (this.initialized) return;
    this.initialized = true;

    const raw = await staticConfig.get('view_mode_scope');
    try {
      const parsed = JSON.parse(raw || '{}');
      this.data.view = parsed.view ?? {};
      this.data.item = parsed.item ?? {};
    } catch {
      // ignore malformed blob, start fresh
    }

    this.migrateLegacyShape();
  }

  /**
   * One-time migration from the old global `square_thumbs`/`list_view`
   * browser-local booleans. Only runs while `view_mode_scope` is still
   * empty (i.e. never migrated before), and seeds every known RouteId with
   * the derived value so behavior is unchanged immediately post-upgrade
   * (the old value applied everywhere).
   */
  private migrateLegacyShape() {
    if (Object.keys(this.data.view).length > 0 || Object.keys(this.data.item).length > 0) {
      return; // already migrated (or user has already customized something)
    }

    const listView = staticConfig.getRawItem('list_view');
    const squareThumbs = staticConfig.getRawItem('square_thumbs');
    if (listView == null && squareThumbs == null) return; // nothing to migrate

    const shape: ImageDisplayShape = listView === 'true' ? 'list' : squareThumbs === 'true' ? 'square' : 'dynamic';

    for (const id of ALL_ROUTE_IDS) {
      this.data.view[id] = shape;
    }

    staticConfig.removeRawItem('list_view');
    staticConfig.removeRawItem('square_thumbs');

    this.persist();
  }

  public isEnabled(routeId: RouteId | null, scopeKey: string | null): boolean {
    if (routeId == null || scopeKey == null) return false;
    return this.data.item[`${routeId}:${scopeKey}`] != null;
  }

  public resolve(routeId: RouteId | null, scopeKey: string | null): ImageDisplayShape {
    if (routeId == null) return DEFAULT_SHAPE;

    if (scopeKey != null) {
      const itemValue = this.data.item[`${routeId}:${scopeKey}`];
      if (itemValue != null) return itemValue;
    }

    return this.data.view[routeId] ?? DEFAULT_SHAPE;
  }

  public setView(routeId: RouteId, value: ImageDisplayShape) {
    Vue.set(this.data.view, routeId, value);
    this.persist();
  }

  public enable(routeId: RouteId, scopeKey: string, initialValue: ImageDisplayShape) {
    Vue.set(this.data.item, `${routeId}:${scopeKey}`, initialValue);
    this.persist();
  }

  public disable(routeId: RouteId, scopeKey: string) {
    Vue.delete(this.data.item, `${routeId}:${scopeKey}`);
    this.persist();
  }

  public setItem(routeId: RouteId, scopeKey: string, value: ImageDisplayShape) {
    Vue.set(this.data.item, `${routeId}:${scopeKey}`, value);
    this.persist();
  }

  private persist() {
    const value = JSON.stringify(this.data);
    staticConfig.setLs('view_mode_scope', value);
    axios.put(API.CONFIG('viewModeScope'), { value }).catch(() => {
      // best-effort; local reactive state already reflects the change
    });
    bus.emit('memories:user-config-changed', { setting: 'view_mode_scope', value });
  }
}

export default new ViewModeScope();
