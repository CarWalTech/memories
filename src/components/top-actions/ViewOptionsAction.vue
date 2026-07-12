<template>
  <NcActions :aria-label="t('memories', 'View options')" :menu-title="t('memories', 'View options')" :force-menu="true">
    <template #icon>
      <SortVariantIcon :size="20" />
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

    <NcActionCaption name="View mode"></NcActionCaption>

    <NcActionRadio
      name="image-display-shape"
      :aria-label="t('memories', 'Dynamic')"
      value="dynamic"
      :checked="imageDisplayShape"
      @change="changeImageDisplayShape('dynamic')"
      close-after-click
    >
      {{ t('memories', 'Dynamic') }}
    </NcActionRadio>

    <NcActionRadio
      name="image-display-shape"
      :aria-label="t('memories', 'Square')"
      value="square"
      :checked="imageDisplayShape"
      @change="changeImageDisplayShape('square')"
      close-after-click
    >
      {{ t('memories', 'Square') }}
    </NcActionRadio>

    <NcActionSeparator />

    <NcActionCaption name="Image properties"></NcActionCaption>

    <NcActionCheckbox :checked="config.show_owner_name_timeline" @update:checked="toggle('show_owner_name_timeline')">
      {{ t('memories', 'Owner Name') }}
    </NcActionCheckbox>

    <NcActionCheckbox
      :checked="config.enable_exif_photo_rating_in_gallery"
      @update:checked="toggle('enable_exif_photo_rating_in_gallery')"
    >
      {{ t('memories', 'Rating') }}
    </NcActionCheckbox>

    <NcActionCheckbox :checked="config.metadata_in_gallery" @update:checked="toggle('metadata_in_gallery')">
      {{ t('memories', 'Metadata') }}
    </NcActionCheckbox>

    <NcActionCheckbox :checked="config.show_filename_in_gallery" @update:checked="toggle('show_filename_in_gallery')">
      {{ t('memories', 'Filename') }}
    </NcActionCheckbox>

    <!-- View-specific extras -->
    <slot />
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionRadio from '@nextcloud/vue/dist/Components/NcActionRadio.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import NcActionButtonGroup from '@nextcloud/vue/dist/Components/NcActionButtonGroup.js';
import ZoomRowAction from '@components/ZoomRowAction.vue';

import UserConfig from '@mixins/UserConfig';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';

import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';

import type { IConfig } from '@typings';

export default defineComponent({
  name: 'ViewOptionsAction',

  components: {
    NcActions,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    NcActionButtonGroup,
    NcActionRadio,
    ZoomRowAction,
    SortVariantIcon,
    ImageMultipleIcon,
  },

  mixins: [UserConfig],

  computed: {
    imageDisplayShape(): string {
      if (this.config.square_thumbs) {
        return 'square';
      } else {
        return 'dynamic';
      }
    },

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
    /** Set sort choice */
    changeImageDisplayShape(mode: string) {
      this.config.square_thumbs = mode == 'square';
      this.updateSetting('square_thumbs');
    },

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
