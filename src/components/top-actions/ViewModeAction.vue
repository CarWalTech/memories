<template>
  <NcActions
    :aria-label="`${t('memories', 'View mode')}: ${shapeLabel}`"
    :menu-title="`${t('memories', 'View mode')}: ${shapeLabel}`"
    :force-menu="true"
    @closed="closePanel"
  >
    <template #icon>
      <component :is="shapeIcon(effectiveShape)" :size="20" />
    </template>

    <template v-if="panel === 'root'">
      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Mosaic')"
        value="dynamic"
        :model-value="selectedShapeGroup"
        @update:modelValue="changeImageDisplayShape('dynamic')"
        close-after-click
      >
        <template #icon><ViewDynamicIcon :size="20" /></template>
        {{ t('memories', 'Mosaic') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Square')"
        value="square"
        :model-value="selectedShapeGroup"
        @update:modelValue="changeImageDisplayShape('square')"
        close-after-click
      >
        <template #icon><ViewSquareIcon :size="20" /></template>
        {{ t('memories', 'Square') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Grid')"
        value="grid"
        :model-value="selectedShapeGroup"
        @update:modelValue="changeImageDisplayShape('grid')"
        close-after-click
      >
        <template #icon><ViewGridIcon :size="20" /></template>
        {{ t('memories', 'Grid') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'List')"
        value="list"
        :model-value="selectedShapeGroup"
        @update:modelValue="changeImageDisplayShape('list')"
        close-after-click
      >
        <template #icon><ViewListIcon :size="20" /></template>
        {{ t('memories', 'List') }}
      </NcActionButton>

      <NcActionSeparator />

      <ZoomRowAction
        :label="`${t('memories', 'Zoom')}`"
        :value="`${zoomLabel}`"
        :disable-minus="!canZoomOut"
        :disable-plus="!canZoomIn"
        @zoom-out="zoomOut"
        @zoom-in="zoomIn"
      />

      <template v-if="hasTiles">
        <NcActionButton is-menu @click="openPanel('tilesSort')">
          <template #icon><component :is="sortTriggerIconFor(tilesSortSetting)" :size="20" /></template>
          {{ `${t('memories', 'Sort tiles by')}` }}
        </NcActionButton>

        <NcActionButton is-menu @click="openPanel('tilesGroup')">
          <template #icon>
            <NoGroupIcon v-if="tilesGroupSetting.key === 'none'" :size="20" />
            <component :is="groupTriggerIconFor(tilesGroupSetting)" v-else :size="20" />
          </template>
          {{ `${t('memories', 'Group tiles by')}` }}
        </NcActionButton>
      </template>

      <NcActionButton is-menu @click="openPanel('info')">
        <template #icon><InformationOutlineIcon :size="20" /></template>
        {{ t('memories', 'Show additional info') }}
      </NcActionButton>

      <NcActionSeparator v-if="showRememberToggle || hasTiles || !!$slots.default" />

      <!-- The single per-view toggle: governs everything this menu (and Sort by) can set for this folder/album/tag. -->
      <NcActionCheckbox v-if="showRememberToggle" :checked="remembered" @update:checked="toggleRemembered">
        {{ rememberLabel }}
      </NcActionCheckbox>

      <template v-if="hasTiles">
        <NcActionCheckbox :checked="recursive" @update:checked="toggleRecursive" close-after-click>
          {{ t('memories', 'Show recrusive contents') }}
        </NcActionCheckbox>
      </template>

      <!-- View-specific extras -->
      <slot />
    </template>

    <!-- Properties / tile options / view-specific extras -->
    <template v-if="panel === 'info'">
      <SubmenuBackAction @back="closePanel" />

      <NcActionCaption :name="infoCaption" />

      <NcActionCheckbox
        :checked="effectiveImageProps.show_owner_name_timeline"
        @update:checked="toggleImageProp('show_owner_name_timeline', $event)"
      >
        {{ t('memories', 'Owner Name') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.enable_exif_photo_rating_in_gallery"
        @update:checked="toggleImageProp('enable_exif_photo_rating_in_gallery', $event)"
      >
        {{ t('memories', 'Rating') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.metadata_in_gallery"
        @update:checked="toggleImageProp('metadata_in_gallery', $event)"
      >
        {{ t('memories', 'Embedded Tags') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_filename_in_gallery"
        @update:checked="toggleImageProp('show_filename_in_gallery', $event)"
      >
        {{ t('memories', 'Filename') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_datetime_in_gallery"
        @update:checked="toggleImageProp('show_datetime_in_gallery', $event)"
      >
        {{ t('memories', 'Date & Time') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_extension_in_gallery"
        @update:checked="toggleImageProp('show_extension_in_gallery', $event)"
      >
        {{ t('memories', 'Extension') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        :checked="effectiveImageProps.show_dimensions_in_gallery"
        @update:checked="toggleImageProp('show_dimensions_in_gallery', $event)"
      >
        {{ t('memories', 'Image Dimensions') }}
      </NcActionCheckbox>

      <NcActionCheckbox
        v-if="config.systemtags_enabled"
        :checked="effectiveImageProps.show_collab_tags_in_gallery"
        @update:checked="toggleImageProp('show_collab_tags_in_gallery', $event)"
      >
        {{ t('memories', 'Collaborative Tags') }}
      </NcActionCheckbox>

      <!-- View-specific extras -->
      <slot name="info" />
    </template>

    <!-- Subfolder / sub-tag tile row Sort by panel -->
    <template v-if="panel === 'tilesSort'">
      <SubmenuBackAction @back="closePanel" />

      <NcActionButton
        v-for="opt in tilesDef.sortKeys"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="tilesSortSetting.key"
        @update:modelValue="changeTilesSortKey(opt.key)"
      >
        <template #icon><component :is="sortKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Ascending')"
        value="asc"
        :model-value="tilesSortSetting.dir"
        @update:modelValue="changeTilesSortDir('asc')"
      >
        <template #icon><SortAscIcon :size="20" /></template>
        {{ t('memories', 'Ascending') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Descending')"
        value="desc"
        :model-value="tilesSortSetting.dir"
        @update:modelValue="changeTilesSortDir('desc')"
      >
        <template #icon><SortDescIcon :size="20" /></template>
        {{ t('memories', 'Descending') }}
      </NcActionButton>
    </template>

    <!-- Subfolder / sub-tag tile row Group by panel -->
    <template v-if="panel === 'tilesGroup'">
      <SubmenuBackAction @back="closePanel" />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'None')"
        value="none"
        :model-value="tilesGroupSetting.key"
        @update:modelValue="changeTilesGroupKey('none')"
      >
        <template #icon><NoGroupIcon :size="20" /></template>
        {{ t('memories', 'None') }}
      </NcActionButton>

      <NcActionButton
        v-for="opt in groupKeyOptsFor(tilesDef)"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="tilesGroupSetting.key"
        @update:modelValue="changeTilesGroupKey(opt.key)"
      >
        <template #icon><component :is="groupKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <template v-if="tilesGroupSetting.key !== 'none'">
        <NcActionSeparator />

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Ascending')"
          value="asc"
          :model-value="tilesGroupSetting.dir"
          @update:modelValue="changeTilesGroupDir('asc')"
        >
          <template #icon><SortAscIcon :size="20" /></template>
          {{ t('memories', 'Ascending') }}
        </NcActionButton>

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Descending')"
          value="desc"
          :model-value="tilesGroupSetting.dir"
          @update:modelValue="changeTilesGroupDir('desc')"
        >
          <template #icon><SortDescIcon :size="20" /></template>
          {{ t('memories', 'Descending') }}
        </NcActionButton>
      </template>
    </template>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import ZoomRowAction from './ZoomRowAction.vue';
import SubmenuBackAction from './SubmenuBackAction';

import ViewPrefs from '@mixins/ViewPrefs';
import Submenu from '@mixins/Submenu';
import viewModeScope, { type ImageDisplayShape } from '@services/view-mode-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';
import { sortScope, groupScope, writeScoped } from '@services/list-scope';
import { rememberViewPrefs, forgetViewPrefs, isRemembered } from '@services/view-prefs';
import {
  sortKeyIcon,
  sortTriggerIconFor,
  groupKeyIcon,
  groupKeyOptsFor,
  groupTriggerIconFor,
} from '@services/sort-group-icons';
import {
  getListScopeDef,
  type SortKey,
  type GroupKey,
  type SortDir,
  type ListScopeDef,
  type ListScopeTarget,
} from '@services/sort-group-defs';

import ViewDynamicIcon from 'vue-material-design-icons/ViewCompact.vue';
import ViewSquareIcon from 'vue-material-design-icons/ViewDashboard.vue';
import ViewGridIcon from 'vue-material-design-icons/ViewGridOutline.vue';
import ViewListIcon from 'vue-material-design-icons/ViewListOutline.vue';
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue';
import SortAscIcon from 'vue-material-design-icons/SortAscending.vue';
import SortDescIcon from 'vue-material-design-icons/SortDescending.vue';
import NoGroupIcon from 'vue-material-design-icons/FormatListBulleted.vue';

/** camelCase server-side config keys for the Dynamic-mode (global config) properties. */
const REMOTE_NAMES: Record<keyof ImagePropsBundle, string> = {
  show_owner_name_timeline: 'showOwnerNameTimeline',
  enable_exif_photo_rating_in_gallery: 'enableExifPhotoRatingInGallery',
  metadata_in_gallery: 'metadataInGallery',
  show_filename_in_gallery: 'showFilenameInGallery',
  show_datetime_in_gallery: 'showDatetimeInGallery',
  show_extension_in_gallery: 'showExtensionInGallery',
  show_dimensions_in_gallery: 'showDimensionsInGallery',
  show_collab_tags_in_gallery: 'showCollabTagsInGallery',
};

const SHAPE_LABELS: Record<ImageDisplayShape, string> = {
  dynamic: 'Mosaic',
  square: 'Square',
  grid: 'Grid',
  list: 'List',
};

const SHAPE_ICONS: Record<ImageDisplayShape, unknown> = {
  dynamic: ViewDynamicIcon,
  square: ViewSquareIcon,
  grid: ViewGridIcon,
  list: ViewListIcon,
};

export default defineComponent({
  name: 'ViewModeAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    ZoomRowAction,
    SubmenuBackAction,
    ViewDynamicIcon,
    ViewSquareIcon,
    ViewGridIcon,
    ViewListIcon,
    InformationOutlineIcon,
    SortAscIcon,
    SortDescIcon,
    NoGroupIcon,
  },

  /** `Submenu` owns `panel`: 'root' plus the 'info' / 'tilesSort' / 'tilesGroup' drill-downs. */
  mixins: [ViewPrefs, Submenu],

  computed: {
    shapeLabel(): string {
      return this.t('memories', SHAPE_LABELS[this.effectiveShape]);
    },

    /**
     * Image properties are stored per shape, except on a remembered view --
     * there they belong to the folder/album/tag itself, whatever shape it is
     * currently showing.
     */
    infoCaption(): string {
      if (this.remembered) return this.t('memories', 'Properties for this view');
      return this.t('memories', 'Properties for {mode} view', { mode: this.shapeLabel });
    },

    /**
     * Which radio reads as selected. Collapses the mosaic family onto its
     * 'dynamic' radio so picking Square doesn't leave the menu with nothing
     * checked.
     */
    selectedShapeGroup(): ImageDisplayShape {
      return this.effectiveShape;
    },

    /** Only offered on routes with a real sub-identity currently being viewed. */
    showRememberToggle(): boolean {
      return this.scopeKey != null && this.rememberLabel != null;
    },

    rememberLabel(): string | null {
      switch (this.routeId) {
        case 'Folders':
        case 'FolderShare':
        case 'Albums':
        case 'AlbumShare':
        case 'Tags':
        case 'EmbeddedTags':
          return this.t('memories', 'Remember display style');
        default:
          return null;
      }
    },

    /** Whether this folder/album/tag keeps its own copy of every per-view preference. */
    remembered(): boolean {
      return isRemembered(this.routeId, this.scopeKey);
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

    // -- Subfolder / sub-tag tile row Sort by / Group by (nested panels) --

    /** Folders/EmbeddedTags each show a second, independently-sortable tile row (subfolders / sub-tags) alongside their main content. */
    hasTiles(): boolean {
      return this.routeId === 'Folders' || this.routeId === 'EmbeddedTags';
    },

    tilesTarget(): ListScopeTarget | null {
      if (this.routeId === 'Folders') return 'FoldersTiles';
      if (this.routeId === 'EmbeddedTags') return 'EmbeddedTagsTiles';
      return null;
    },

    /** Folders/EmbeddedTags can flatten their tree into the plain photo timeline. */
    recursive(): boolean {
      return !!this.$route.query.recursive;
    },

    tilesDef(): ListScopeDef {
      return getListScopeDef(this.tilesTarget);
    },

    tilesSortSetting() {
      return sortScope.resolve(this.tilesTarget, this.scopeKey);
    },

    tilesGroupSetting() {
      return groupScope.resolve(this.tilesTarget, this.scopeKey);
    },
  },

  created() {
    sortScope.init();
    groupScope.init();
  },

  methods: {
    shapeIcon(shape: ImageDisplayShape) {
      return SHAPE_ICONS[shape];
    },

    changeImageDisplayShape(mode: ImageDisplayShape): void {
      if (this.routeId == null) return;

      if (this.remembered && this.scopeKey != null) {
        viewModeScope.setItem(this.routeId, this.scopeKey, mode);
      } else {
        viewModeScope.setView(this.routeId, mode);
      }
    },

    /**
     * The one per-view toggle: turning it on snapshots everything currently in
     * effect for this folder/album/tag (shape, sort, grouping, tile sort and
     * grouping, image properties) onto the item itself; turning it off drops
     * the lot and hands the view back to its view-type defaults.
     */
    toggleRemembered(checked: boolean): void {
      if (this.routeId == null || this.scopeKey == null) return;

      if (checked) {
        rememberViewPrefs(this.routeId, this.scopeKey, this.$route, {
          shape: this.effectiveShape,
          imageProps: this.effectiveImageProps,
        });
      } else {
        forgetViewPrefs(this.routeId, this.scopeKey, this.$route);
      }
    },

    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
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

    /**
     * NcActionCheckbox's v-model shim re-emits `update:checked` with its
     * current resolved value when it's freshly patched into the DOM (e.g.
     * right as this panel opens), not only on a real user click. Setting to
     * the emitted `value` directly (rather than inverting whatever the
     * checkbox currently resolves to) makes that spurious echo a no-op
     * instead of silently flipping the setting.
     */
    toggleImageProp(key: keyof ImagePropsBundle, value: boolean): void {
      if (this.remembered && this.routeId != null && this.scopeKey != null) {
        imageProps.setItemProp(this.routeId, this.scopeKey, key, value);
        return;
      }

      const shape = this.effectiveShape;
      if (shape === 'dynamic') {
        this.toggle(key, value, REMOTE_NAMES[key]);
        return;
      }

      imageProps.setModeProp(shape, key, value);
    },

    toggle<K extends keyof ImagePropsBundle>(key: K, value: boolean, remote?: string): void {
      (this.config as any)[key] = value;
      this.updateSetting(key, remote);
    },

    sortKeyIcon,
    sortTriggerIconFor,
    groupKeyIcon,
    groupKeyOptsFor,
    groupTriggerIconFor,

    changeTilesSortKey(key: SortKey): void {
      writeScoped(sortScope, this.tilesTarget, this.scopeKey, { key, dir: this.tilesSortSetting.dir });
    },

    changeTilesSortDir(dir: SortDir): void {
      writeScoped(sortScope, this.tilesTarget, this.scopeKey, { key: this.tilesSortSetting.key, dir });
    },

    changeTilesGroupKey(key: GroupKey): void {
      writeScoped(groupScope, this.tilesTarget, this.scopeKey, { key, dir: this.tilesGroupSetting.dir });
    },

    changeTilesGroupDir(dir: SortDir): void {
      writeScoped(groupScope, this.tilesTarget, this.scopeKey, { key: this.tilesGroupSetting.key, dir });
    },
  },
});
</script>

<style lang="scss" scoped>
// Icon itself already matches every other action button (20px) -- tighten the
// button's own clickable-area/padding so it doesn't read as swimming in empty space.
// Width and height shrink together (not just width) so the round hover/focus indicator stays circular.
:deep(.action-item__menutoggle) {
  width: 36px !important;
  height: 36px !important;
}
</style>
