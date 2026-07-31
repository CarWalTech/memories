<template>
  <NcActions :aria-label="t('memories', 'View options')" :menu-title="t('memories', 'View options')" :force-menu="true" @closed="panel = 'root'">
    <template #icon>
      <TuneIcon :size="20" />
    </template>

    <template v-if="panel === 'root'">
      <ZoomRowAction
        :label="`${t('memories', 'Zoom')}: ${zoomLabel}`"
        :disable-minus="!canZoomOut"
        :disable-plus="!canZoomIn"
        @zoom-out="zoomOut"
        @zoom-in="zoomIn"
      />

      <NcActionSeparator />

      <NcActionCaption :name="t('memories', 'Properties for {mode} view', { mode: shapeLabel })" />

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

      <template v-if="hasTiles">
        <NcActionSeparator />

        <NcActionCaption :name="tilesCaption" />

        <NcActionButton @click="panel = 'tilesSort'">
          <template #icon><component :is="sortTriggerIconFor(tilesSortSetting)" :size="20" /></template>
          {{ t('memories', 'Sort by') }}: {{ tilesSortLabel }}
        </NcActionButton>

        <NcActionButton @click="panel = 'tilesGroup'">
          <template #icon>
            <NoGroupIcon v-if="tilesGroupSetting.key === 'none'" :size="20" />
            <component :is="groupTriggerIconFor(tilesGroupSetting)" v-else :size="20" />
          </template>
          {{ t('memories', 'Group by') }}: {{ tilesGroupLabel }}
        </NcActionButton>
      </template>

      <!-- View-specific extras -->
      <slot />
    </template>

    <!-- Subfolder / sub-tag tile row Sort by panel -->
    <template v-if="panel === 'tilesSort'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox
        v-if="showTilesSortItemToggle"
        :checked="tilesSortItemToggleOn"
        @update:checked="toggleTilesSortItemScope"
      >
        {{ tilesSortItemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showTilesSortItemToggle" />

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
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox
        v-if="showTilesGroupItemToggle"
        :checked="tilesGroupItemToggleOn"
        @update:checked="toggleTilesGroupItemScope"
      >
        {{ tilesGroupItemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showTilesGroupItemToggle" />

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

import ViewPrefs from '@mixins/ViewPrefs';
import { type ImageDisplayShape } from '@services/view-mode-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';
import { sortScope, groupScope } from '@services/list-scope';
import { getListScopeDef, type SortKey, type GroupKey, type SortDir, type ListScopeDef, type ListScopeTarget } from '@services/sort-group-defs';

import TuneIcon from 'vue-material-design-icons/Tune.vue';
import SortAscIcon from 'vue-material-design-icons/SortAscending.vue';
import SortDescIcon from 'vue-material-design-icons/SortDescending.vue';
import NoGroupIcon from 'vue-material-design-icons/FormatListBulleted.vue';
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue';
import SortAlphaIcon from 'vue-material-design-icons/SortAlphabeticalVariant.vue';
import FileSizeIcon from 'vue-material-design-icons/Harddisk.vue';
import UpdatedIcon from 'vue-material-design-icons/ClockOutline.vue';
import CreatedIcon from 'vue-material-design-icons/CalendarOutline.vue';
import CountIcon from 'vue-material-design-icons/Counter.vue';
import GroupDayIcon from 'vue-material-design-icons/CalendarTodayOutline.vue';
import GroupWeekIcon from 'vue-material-design-icons/CalendarWeekOutline.vue';
import GroupMonthIcon from 'vue-material-design-icons/CalendarMonthOutline.vue';
import GroupYearIcon from 'vue-material-design-icons/CalendarBlankOutline.vue';

import SortAlphaAscIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SortAlphaDescIcon from 'vue-material-design-icons/SortAlphabeticalDescending.vue';
import SortNumericAscIcon from 'vue-material-design-icons/SortNumericAscending.vue';
import SortNumericDescIcon from 'vue-material-design-icons/SortNumericDescending.vue';
import SortClockAscIcon from 'vue-material-design-icons/SortClockAscendingOutline.vue';
import SortClockDescIcon from 'vue-material-design-icons/SortClockDescendingOutline.vue';
import SortCalendarAscIcon from 'vue-material-design-icons/SortCalendarAscending.vue';
import SortCalendarDescIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

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

/** Icon per Sort by key, shown next to each option in the subfolder/sub-tag Sort by panel. */
const SORT_KEY_ICONS: Record<SortKey, unknown> = {
  name: SortAlphaIcon,
  size: FileSizeIcon,
  updated: UpdatedIcon,
  created: CreatedIcon,
  count: CountIcon,
};

/** Combined key+direction icon for the "Sort by" row -- mirrors the type distinctions above (alpha/numeric/clock/calendar) instead of a generic up/down arrow. */
const SORT_KEY_DIR_ICONS: Record<SortKey, { asc: unknown; desc: unknown }> = {
  name: { asc: SortAlphaAscIcon, desc: SortAlphaDescIcon },
  size: { asc: SortNumericAscIcon, desc: SortNumericDescIcon },
  updated: { asc: SortClockAscIcon, desc: SortClockDescIcon },
  created: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  count: { asc: SortNumericAscIcon, desc: SortNumericDescIcon },
};

const GROUP_KEY_LABELS: Record<Exclude<GroupKey, 'none'>, string> = {
  day: 'Day',
  week: 'Week',
  month: 'Month',
  year: 'Year',
  name: 'Name',
};

/** Icon per Group by key (excluding 'none', which always uses NoGroupIcon directly). */
const GROUP_KEY_ICONS: Record<Exclude<GroupKey, 'none'>, unknown> = {
  day: GroupDayIcon,
  week: GroupWeekIcon,
  month: GroupMonthIcon,
  year: GroupYearIcon,
  name: SortAlphaIcon,
};

/** Combined key+direction icon for the "Group by" row -- same idea as SORT_KEY_DIR_ICONS. */
const GROUP_KEY_DIR_ICONS: Record<Exclude<GroupKey, 'none'>, { asc: unknown; desc: unknown }> = {
  day: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  week: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  month: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  year: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  name: { asc: SortAlphaAscIcon, desc: SortAlphaDescIcon },
};

export default defineComponent({
  name: 'ViewOptionsAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    ZoomRowAction,
    TuneIcon,
    SortAscIcon,
    SortDescIcon,
    NoGroupIcon,
    ArrowLeftIcon,
  },

  mixins: [ViewPrefs],

  data: () => ({
    /** The Folders/EmbeddedTags subfolder/sub-tag tile-row Sort by and Group by live as drill-down sub-panels of this same dropdown, next to "Folder options" etc. */
    panel: 'root' as 'root' | 'tilesSort' | 'tilesGroup',
  }),

  computed: {
    shapeLabel(): string {
      return this.t('memories', SHAPE_LABELS[this.effectiveShape]);
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

    tilesCaption(): string {
      return this.routeId === 'Folders' ? this.t('memories', 'Subfolders') : this.t('memories', 'Sub-tags');
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

    tilesSortLabel(): string {
      const opt = this.tilesDef.sortKeys.find((o) => o.key === this.tilesSortSetting.key);
      return opt?.label ?? '';
    },

    tilesGroupLabel(): string {
      if (this.tilesGroupSetting.key === 'none') return this.t('memories', 'None');
      return this.t('memories', GROUP_KEY_LABELS[this.tilesGroupSetting.key as Exclude<GroupKey, 'none'>]);
    },

    showTilesSortItemToggle(): boolean {
      return this.scopeKey != null;
    },

    tilesSortItemToggleLabel(): string {
      return this.routeId === 'Folders'
        ? this.t('memories', 'Custom subfolder sort for this folder')
        : this.t('memories', 'Custom sub-tag sort for this tag');
    },

    tilesSortItemToggleOn(): boolean {
      return sortScope.isEnabled(this.tilesTarget, this.scopeKey);
    },

    showTilesGroupItemToggle(): boolean {
      return this.scopeKey != null;
    },

    tilesGroupItemToggleLabel(): string {
      return this.routeId === 'Folders'
        ? this.t('memories', 'Custom subfolder grouping for this folder')
        : this.t('memories', 'Custom sub-tag grouping for this tag');
    },

    tilesGroupItemToggleOn(): boolean {
      return groupScope.isEnabled(this.tilesTarget, this.scopeKey);
    },
  },

  created() {
    sortScope.init();
    groupScope.init();
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

    /**
     * NcActionCheckbox's v-model shim re-emits `update:checked` with its
     * current resolved value when it's freshly patched into the DOM (e.g.
     * right as this panel opens), not only on a real user click. Setting to
     * the emitted `value` directly (rather than inverting whatever the
     * checkbox currently resolves to) makes that spurious echo a no-op
     * instead of silently flipping the setting.
     */
    toggleImageProp(key: keyof ImagePropsBundle, value: boolean): void {
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

    sortKeyIcon(key: SortKey) {
      return SORT_KEY_ICONS[key];
    },

    sortTriggerIconFor(setting: { key: SortKey; dir: SortDir }) {
      return SORT_KEY_DIR_ICONS[setting.key][setting.dir];
    },

    groupKeyIcon(key: Exclude<GroupKey, 'none'>) {
      return GROUP_KEY_ICONS[key];
    },

    groupKeyOptsFor(def: ListScopeDef): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return def.groupKeys.map((key) => ({ key, label: this.t('memories', GROUP_KEY_LABELS[key]) }));
    },

    groupTriggerIconFor(setting: { key: GroupKey; dir: SortDir }) {
      if (setting.key === 'none') return null;
      const key = setting.key as Exclude<GroupKey, 'none'>;
      return GROUP_KEY_DIR_ICONS[key][setting.dir];
    },

    writeTilesSort(value: { key: SortKey; dir: SortDir }): void {
      if (this.tilesTarget == null) return;

      if (this.tilesSortItemToggleOn && this.scopeKey != null) {
        sortScope.setItem(this.tilesTarget, this.scopeKey, value);
      } else {
        sortScope.setView(this.tilesTarget, value);
      }
    },

    changeTilesSortKey(key: SortKey): void {
      this.writeTilesSort({ key, dir: this.tilesSortSetting.dir });
    },

    changeTilesSortDir(dir: SortDir): void {
      this.writeTilesSort({ key: this.tilesSortSetting.key, dir });
    },

    toggleTilesSortItemScope(checked: boolean): void {
      if (this.tilesTarget == null || this.scopeKey == null) return;

      if (checked) {
        sortScope.enable(this.tilesTarget, this.scopeKey, this.tilesSortSetting);
      } else {
        sortScope.disable(this.tilesTarget, this.scopeKey);
      }
    },

    writeTilesGroup(value: { key: GroupKey; dir: SortDir }): void {
      if (this.tilesTarget == null) return;

      if (this.tilesGroupItemToggleOn && this.scopeKey != null) {
        groupScope.setItem(this.tilesTarget, this.scopeKey, value);
      } else {
        groupScope.setView(this.tilesTarget, value);
      }
    },

    changeTilesGroupKey(key: GroupKey): void {
      this.writeTilesGroup({ key, dir: this.tilesGroupSetting.dir });
    },

    changeTilesGroupDir(dir: SortDir): void {
      this.writeTilesGroup({ key: this.tilesGroupSetting.key, dir });
    },

    toggleTilesGroupItemScope(checked: boolean): void {
      if (this.tilesTarget == null || this.scopeKey == null) return;

      if (checked) {
        groupScope.enable(this.tilesTarget, this.scopeKey, this.tilesGroupSetting);
      } else {
        groupScope.disable(this.tilesTarget, this.scopeKey);
      }
    },
  },
});
</script>
