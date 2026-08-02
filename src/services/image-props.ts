import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import staticConfig from '@services/static-config';

import type { IConfig } from '@typings';
import { isRemembered, type ImageDisplayShape, type RouteId } from './view-mode-scope';

export type ImagePropsBundle = Pick<
  IConfig,
  | 'show_owner_name_timeline'
  | 'enable_exif_photo_rating_in_gallery'
  | 'metadata_in_gallery'
  | 'show_filename_in_gallery'
  | 'show_datetime_in_gallery'
  | 'show_extension_in_gallery'
  | 'show_dimensions_in_gallery'
  | 'show_collab_tags_in_gallery'
>;

const PROP_KEYS: (keyof ImagePropsBundle)[] = [
  'show_owner_name_timeline',
  'enable_exif_photo_rating_in_gallery',
  'metadata_in_gallery',
  'show_filename_in_gallery',
  'show_datetime_in_gallery',
  'show_extension_in_gallery',
  'show_dimensions_in_gallery',
  'show_collab_tags_in_gallery',
];

/** The Dynamic-mode profile is just today's global config -- only Square/Grid/List get their own stored overrides. */
type OverridableMode = 'square' | 'grid' | 'list';

interface ImagePropsModesData {
  square: Partial<ImagePropsBundle>;
  grid: Partial<ImagePropsBundle>;
  list: Partial<ImagePropsBundle>;
  /**
   * Per-remembered-view override; key is `${routeId}:${scopeKey}`. Deliberately
   * *not* split by shape -- "remembered for this folder" means the properties
   * follow the folder, not whichever shape it happens to be showing.
   */
  item: Record<string, Partial<ImagePropsBundle>>;
}

const emptyData = (): ImagePropsModesData => ({ square: {}, grid: {}, list: {}, item: {} });

function pick(config: IConfig): ImagePropsBundle {
  const result = {} as ImagePropsBundle;
  for (const key of PROP_KEYS) {
    result[key] = config[key] as never;
  }
  return result;
}

class ImagePropsStore {
  private data = Vue.observable<ImagePropsModesData>(emptyData());
  private initialized = false;

  public async init() {
    if (this.initialized) return;
    this.initialized = true;

    const raw = await staticConfig.get('image_props_modes');
    try {
      const parsed = JSON.parse(raw || '{}');
      this.data.square = parsed.square ?? {};
      this.data.grid = parsed.grid ?? {};
      this.data.list = parsed.list ?? {};
      this.data.item = parsed.item ?? {};
    } catch {
      // ignore malformed blob, start fresh
    }
  }

  /**
   * Sparsely layers, in order: the Dynamic (global) baseline, the
   * Square/Grid/List mode's saved overrides, then -- only for a view that is
   * remembering its own preferences -- that view's own overrides.
   */
  public resolve(
    mode: ImageDisplayShape,
    config: IConfig,
    routeId: RouteId | null = null,
    scopeKey: string | null = null,
  ): ImagePropsBundle {
    let result = pick(config);
    if (mode !== 'dynamic') result = { ...result, ...this.data[mode] };
    if (isRemembered(routeId, scopeKey)) result = { ...result, ...this.data.item[`${routeId}:${scopeKey}`] };
    return result;
  }

  public setModeProp<K extends keyof ImagePropsBundle>(mode: OverridableMode, key: K, value: ImagePropsBundle[K]) {
    Vue.set(this.data[mode], key, value);
    this.persist();
  }

  public setItemProp<K extends keyof ImagePropsBundle>(
    routeId: RouteId,
    scopeKey: string,
    key: K,
    value: ImagePropsBundle[K],
  ) {
    const itemKey = `${routeId}:${scopeKey}`;
    if (this.data.item[itemKey] == null) Vue.set(this.data.item, itemKey, {});
    Vue.set(this.data.item[itemKey], key, value);
    this.persist();
  }

  /** Snapshot a whole bundle at once -- used when a view starts remembering. */
  public setItemBundle(routeId: RouteId, scopeKey: string, bundle: ImagePropsBundle) {
    Vue.set(this.data.item, `${routeId}:${scopeKey}`, { ...bundle });
    this.persist();
  }

  public clearItem(routeId: RouteId, scopeKey: string) {
    Vue.delete(this.data.item, `${routeId}:${scopeKey}`);
    this.persist();
  }

  private persist() {
    const value = JSON.stringify(this.data);
    staticConfig.setLs('image_props_modes', value);
    axios.put(API.CONFIG('imagePropsModes'), { value }).catch(() => {
      // best-effort; local reactive state already reflects the change
    });
    bus.emit('memories:user-config-changed', { setting: 'image_props_modes', value });
  }
}

export default new ImagePropsStore();
