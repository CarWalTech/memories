import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as utils from '@services/utils';
import staticConfig from '../services/static-config';

import type { IConfig } from '@typings';

const eventName: keyof utils.BusEvent = 'memories:user-config-changed';

const localSettings: (keyof IConfig)[] = [
  'high_res_cond',
  'show_face_rect',
  'mobile_nav_items',
  'grid_zoom',
  'blur_thumbnails',
];

export default defineComponent({
  name: 'UserConfig',

  data: () => ({
    config: { ...staticConfig.getDefault() } as IConfig,
  }),

  created() {
    utils.bus.on(eventName, this.updateLocalSetting);
    this.refreshFromConfig();
  },

  beforeDestroy() {
    utils.bus.off(eventName, this.updateLocalSetting);
  },

  methods: {
    async refreshFromConfig() {
      const config = await staticConfig.getAll();
      const changed = Object.keys(config).filter(<K extends keyof IConfig>(key: K) => config[key] !== this.config[key]);
      if (changed.length === 0) return;

      console.warn('[MEM-DEBUG-PROPS] refreshFromConfig() applying server values for changed keys:', changed, {
        component: (this as any).$options.name,
      });

      changed.forEach(<K extends keyof IConfig>(key: K) => (this.config[key] = config[key]));
      utils.bus.emit(eventName, null);
    },

    updateLocalSetting(val: { setting: keyof IConfig; value: IConfig[keyof IConfig] }) {
      if (val?.setting) {
        console.warn('[MEM-DEBUG-PROPS] updateLocalSetting() bus event received:', val, {
          component: (this as any).$options.name,
        });
        (this.config as any)[val.setting] = val.value;
      }
    },

    async updateSetting<K extends keyof IConfig>(setting: K, remote?: string) {
      const value = this.config[setting];

      console.warn('[MEM-DEBUG-PROPS] updateSetting() writing', { setting, value, component: (this as any).$options.name });

      if (!localSettings.includes(setting)) {
        await axios.put(API.CONFIG(remote ?? setting), {
          value: value?.toString() ?? '',
        });
      }

      staticConfig.setLs(setting, value);

      utils.bus.emit(eventName, { setting, value });
    },
  },
});
