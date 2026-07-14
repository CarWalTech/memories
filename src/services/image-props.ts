import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import staticConfig from '@services/static-config';

import type { IConfig } from '@typings';
import type { ImageDisplayShape } from './view-mode-scope';

export type ImagePropsBundle = Pick<
  IConfig,
  | 'show_owner_name_timeline'
  | 'enable_exif_photo_rating_in_gallery'
  | 'metadata_in_gallery'
  | 'show_filename_in_gallery'
  | 'show_datetime_in_gallery'
  | 'show_extension_in_gallery'
  | 'show_dimensions_in_gallery'
>;

const PROP_KEYS: (keyof ImagePropsBundle)[] = [
  'show_owner_name_timeline',
  'enable_exif_photo_rating_in_gallery',
  'metadata_in_gallery',
  'show_filename_in_gallery',
  'show_datetime_in_gallery',
  'show_extension_in_gallery',
  'show_dimensions_in_gallery',
];

/** The Dynamic-mode profile is just today's global config -- only Square/List get their own stored overrides. */
type OverridableMode = 'square' | 'list';

interface ImagePropsModesData {
  square: Partial<ImagePropsBundle>;
  list: Partial<ImagePropsBundle>;
}

const emptyData = (): ImagePropsModesData => ({ square: {}, list: {} });

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
      this.data.list = parsed.list ?? {};
    } catch {
      // ignore malformed blob, start fresh
    }
  }

  /** Sparsely layers the Square/List mode's saved overrides over the Dynamic (global) baseline. */
  public resolve(mode: ImageDisplayShape, config: IConfig): ImagePropsBundle {
    const base = pick(config);
    if (mode === 'dynamic') return base;
    return { ...base, ...this.data[mode] };
  }

  public setModeProp<K extends keyof ImagePropsBundle>(mode: OverridableMode, key: K, value: ImagePropsBundle[K]) {
    Vue.set(this.data[mode], key, value);
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
