<template>
  <NcActions
    :aria-label="t('memories', 'View options')"
    :menu-title="t('memories', 'View options')"
    :force-menu="true"
    @closed="panel = 'root'"
  >
    <template #icon>
      <SortVariantIcon :size="20" />
    </template>

    <!-- Root panel -->
    <template v-if="panel === 'root'">
      <ZoomRowAction
        :label="`${t('memories', 'Zoom')}: ${zoomLabel}`"
        :disable-minus="!canZoomOut"
        :disable-plus="!canZoomIn"
        @zoom-out="zoomOut"
        @zoom-in="zoomIn"
      />

      <NcActionSeparator />

      <NcActionButton @click="panel = 'shape'">
        <template #icon><ImageMultipleIcon :size="20" /></template>
        {{ t('memories', 'View mode') }}: {{ shapeLabel }}
      </NcActionButton>

      <NcActionButton @click="panel = 'props'">
        <template #icon><TuneIcon :size="20" /></template>
        {{ t('memories', 'Image properties') }}
      </NcActionButton>

      <!-- View-specific extras -->
      <slot />
    </template>

    <!-- View mode panel -->
    <template v-if="panel === 'shape'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox v-if="showItemToggle" :checked="itemToggleOn" @update:checked="toggleItemScope">
        {{ itemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showItemToggle" />

      <NcActionRadio
        name="image-display-shape"
        :aria-label="t('memories', 'Dynamic')"
        value="dynamic"
        :checked="effectiveShape"
        @change="changeImageDisplayShape('dynamic')"
        close-after-click
      >
        {{ t('memories', 'Dynamic') }}
      </NcActionRadio>

      <NcActionRadio
        name="image-display-shape"
        :aria-label="t('memories', 'Square')"
        value="square"
        :checked="effectiveShape"
        @change="changeImageDisplayShape('square')"
        close-after-click
      >
        {{ t('memories', 'Square') }}
      </NcActionRadio>

      <NcActionRadio
        name="image-display-shape"
        :aria-label="t('memories', 'List')"
        value="list"
        :checked="effectiveShape"
        @change="changeImageDisplayShape('list')"
        close-after-click
      >
        {{ t('memories', 'List') }}
      </NcActionRadio>
    </template>

    <!-- Image properties panel -->
    <template v-if="panel === 'props'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCaption :name="t('memories', 'Properties for {mode} view', { mode: shapeLabel })" />

      <NcActionCheckbox
        :checked="effectiveImageProps.show_owner_name_timeline"
        @update:checked="toggleImageProp('show_owner_name_timeline')"
      >
        {{ t('memories', 'Owner Name') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.enable_exif_photo_rating_in_gallery"
        @update:checked="toggleImageProp('enable_exif_photo_rating_in_gallery')"
      >
        {{ t('memories', 'Rating') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.metadata_in_gallery"
        @update:checked="toggleImageProp('metadata_in_gallery')"
      >
        {{ t('memories', 'Embedded Tags') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_filename_in_gallery"
        @update:checked="toggleImageProp('show_filename_in_gallery')"
      >
        {{ t('memories', 'Filename') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_datetime_in_gallery"
        @update:checked="toggleImageProp('show_datetime_in_gallery')"
      >
        {{ t('memories', 'Date & Time') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_extension_in_gallery"
        @update:checked="toggleImageProp('show_extension_in_gallery')"
      >
        {{ t('memories', 'Extension') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_dimensions_in_gallery"
        @update:checked="toggleImageProp('show_dimensions_in_gallery')"
      >
        {{ t('memories', 'Image Dimensions') }}
      </NcActionCheckbox>
    </template>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionRadio from '@nextcloud/vue/dist/Components/NcActionRadio.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import ZoomRowAction from '@components/ZoomRowAction.vue';

import ViewPrefs from '@mixins/ViewPrefs';
import viewModeScope, { type ImageDisplayShape } from '@services/view-mode-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';

import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue';

/** camelCase server-side config keys for the Dynamic-mode (global config) properties. */
const REMOTE_NAMES: Record<keyof ImagePropsBundle, string> = {
  show_owner_name_timeline: 'showOwnerNameTimeline',
  enable_exif_photo_rating_in_gallery: 'enableExifPhotoRatingInGallery',
  metadata_in_gallery: 'metadataInGallery',
  show_filename_in_gallery: 'showFilenameInGallery',
  show_datetime_in_gallery: 'showDatetimeInGallery',
  show_extension_in_gallery: 'showExtensionInGallery',
  show_dimensions_in_gallery: 'showDimensionsInGallery',
};

const SHAPE_LABELS: Record<ImageDisplayShape, string> = {
  dynamic: 'Dynamic',
  square: 'Square',
  list: 'List',
};

export default defineComponent({
  name: 'ViewOptionsAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    NcActionRadio,
    ZoomRowAction,
    SortVariantIcon,
    ImageMultipleIcon,
    TuneIcon,
    ArrowLeftIcon,
  },

  mixins: [ViewPrefs],

  data: () => ({
    panel: 'root' as 'root' | 'shape' | 'props',
  }),

  computed: {
    shapeLabel(): string {
      return this.t('memories', SHAPE_LABELS[this.effectiveShape]);
    },

    /** Item-scope toggle is only offered on routes with a real sub-identity currently being viewed. */
    showItemToggle(): boolean {
      return this.scopeKey != null && this.itemToggleLabel != null;
    },

    itemToggleLabel(): string | null {
      switch (this.routeId) {
        case 'Folders':
        case 'FolderShare':
          return this.t('memories', 'Custom view mode for this folder');
        case 'Albums':
        case 'AlbumShare':
          return this.t('memories', 'Custom view mode for this album');
        case 'Tags':
        case 'EmbeddedTags':
          return this.t('memories', 'Custom view mode for this tag');
        default:
          return null;
      }
    },

    itemToggleOn(): boolean {
      return viewModeScope.isEnabled(this.routeId, this.scopeKey);
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
    changeImageDisplayShape(mode: ImageDisplayShape): void {
      if (this.routeId == null) return;

      if (this.itemToggleOn && this.scopeKey != null) {
        viewModeScope.setItem(this.routeId, this.scopeKey, mode);
      } else {
        viewModeScope.setView(this.routeId, mode);
      }
    },

    toggleItemScope(checked: boolean): void {
      if (this.routeId == null || this.scopeKey == null) return;

      if (checked) {
        viewModeScope.enable(this.routeId, this.scopeKey, this.effectiveShape);
      } else {
        viewModeScope.disable(this.routeId, this.scopeKey);
      }
    },

    toggleImageProp(key: keyof ImagePropsBundle): void {
      const shape = this.effectiveShape;
      if (shape === 'dynamic') {
        this.toggle(key, REMOTE_NAMES[key]);
        return;
      }

      imageProps.setModeProp(shape, key, !this.effectiveImageProps[key]);
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

    toggle<K extends keyof ImagePropsBundle>(key: K, remote?: string): void {
      (this.config as any)[key] = !this.config[key];
      this.updateSetting(key, remote);
    },
  },
});
</script>
