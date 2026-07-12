<template>
  <NcActions :aria-label="t('memories', 'View options')">
    <template #icon>
      <TuneVariantIcon :size="20" />
    </template>

    <!-- Global view options -->
    <ZoomRowAction
      :label="`${t('memories', 'Zoom')}: ${zoomLabel}`"
      :disable-minus="!canZoomOut"
      :disable-plus="!canZoomIn"
      @zoom-out="zoomOut"
      @zoom-in="zoomIn"
    />

    <NcActionSeparator />

    <!-- Photo view options -->
    <NcActionCheckbox :checked="config.square_thumbs" @update:checked="toggle('square_thumbs')">
      {{ t('memories', 'Square grid mode') }}
    </NcActionCheckbox>

    <NcActionCheckbox :checked="config.show_owner_name_timeline" @update:checked="toggle('show_owner_name_timeline')">
      {{ t('memories', 'Show owner name on timeline') }}
    </NcActionCheckbox>

    <NcActionCheckbox
      :checked="config.enable_exif_photo_rating_in_gallery"
      @update:checked="toggle('enable_exif_photo_rating_in_gallery')"
    >
      {{ t('memories', 'Enable photo rating in gallery') }}
    </NcActionCheckbox>

    <NcActionCheckbox :checked="config.metadata_in_gallery" @update:checked="toggle('metadata_in_gallery')">
      {{ t('memories', 'Show metadata in gallery') }}
    </NcActionCheckbox>

    <!-- View-specific extras -->
    <slot />
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import ZoomRowAction from '@components/ZoomRowAction.vue';

import UserConfig from '@mixins/UserConfig';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';

import TuneVariantIcon from 'vue-material-design-icons/TuneVariant.vue';

import type { IConfig } from '@typings';

export default defineComponent({
  name: 'ViewOptionsActions',

  components: {
    NcActions,
    NcActionCheckbox,
    NcActionSeparator,
    ZoomRowAction,
    TuneVariantIcon,
  },

  mixins: [UserConfig],

  computed: {
    zoomLabel(): string {
      return zoomLabel(this.config.grid_zoom);
    },

    canZoomOut(): boolean {
      return clampZoom(this.config.grid_zoom) > ZOOM_MIN;
    },

    canZoomIn(): boolean {
      return clampZoom(this.config.grid_zoom) < ZOOM_MAX;
    },
  },

  methods: {
    zoomOut(): void {
      this.setZoom(clampZoom(this.config.grid_zoom) - 1);
    },

    zoomIn(): void {
      this.setZoom(clampZoom(this.config.grid_zoom) + 1);
    },

    setZoom(level: number): void {
      this.config.grid_zoom = clampZoom(level);
      this.updateSetting('grid_zoom');
    },

    toggle<K extends keyof IConfig>(key: K): void {
      (this.config as any)[key] = !this.config[key];
      this.updateSetting(key);
    },
  },
});
</script>
