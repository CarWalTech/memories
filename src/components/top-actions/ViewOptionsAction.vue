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
        <template #icon><component :is="shapeIcon(effectiveShape)" :size="20" /></template>
        {{ t('memories', 'View mode') }}: {{ shapeLabel }}
      </NcActionButton>

      <NcActionButton @click="openPropsPanel">
        <template #icon><TuneIcon :size="20" /></template>
        {{ t('memories', 'Image properties') }}
      </NcActionButton>

      <template v-if="hasSortGroup">
        <NcActionCaption v-if="hasTileSection" :name="t('memories', 'Photos')" />

        <NcActionButton @click="panel = 'sort'">
          <template #icon>
            <SortAscIcon v-if="sortSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Sort by') }}: {{ sortLabel }}
        </NcActionButton>

        <NcActionButton @click="panel = 'group'">
          <template #icon>
            <NoGroupIcon v-if="groupSetting.key === 'none'" :size="20" />
            <SortAscIcon v-else-if="groupSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Group by') }}: {{ groupLabel }}
        </NcActionButton>
      </template>

      <template v-if="routeId === 'Folders'">
        <NcActionCaption :name="t('memories', 'Subfolders')" />

        <NcActionButton @click="panel = 'folderSort'">
          <template #icon>
            <SortAscIcon v-if="folderSortSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Sort by') }}: {{ folderSortLabel }}
        </NcActionButton>

        <NcActionButton @click="panel = 'folderGroup'">
          <template #icon>
            <NoGroupIcon v-if="folderGroupSetting.key === 'none'" :size="20" />
            <SortAscIcon v-else-if="folderGroupSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Group by') }}: {{ folderGroupLabel }}
        </NcActionButton>
      </template>

      <template v-if="routeId === 'EmbeddedTags'">
        <NcActionCaption :name="t('memories', 'Sub-tags')" />

        <NcActionButton @click="panel = 'tagSort'">
          <template #icon>
            <SortAscIcon v-if="tagSortSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Sort by') }}: {{ tagSortLabel }}
        </NcActionButton>

        <NcActionButton @click="panel = 'tagGroup'">
          <template #icon>
            <NoGroupIcon v-if="tagGroupSetting.key === 'none'" :size="20" />
            <SortAscIcon v-else-if="tagGroupSetting.dir === 'asc'" :size="20" />
            <SortDescIcon v-else :size="20" />
          </template>
          {{ t('memories', 'Group by') }}: {{ tagGroupLabel }}
        </NcActionButton>
      </template>

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

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Dynamic')"
        value="dynamic"
        :model-value="effectiveShape"
        @update:modelValue="changeImageDisplayShape('dynamic')"
        close-after-click
      >
        <template #icon><ViewDynamicIcon :size="20" /></template>
        {{ t('memories', 'Dynamic') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Square')"
        value="square"
        :model-value="effectiveShape"
        @update:modelValue="changeImageDisplayShape('square')"
        close-after-click
      >
        <template #icon><ViewSquareIcon :size="20" /></template>
        {{ t('memories', 'Square') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'List')"
        value="list"
        :model-value="effectiveShape"
        @update:modelValue="changeImageDisplayShape('list')"
        close-after-click
      >
        <template #icon><ViewListIcon :size="20" /></template>
        {{ t('memories', 'List') }}
      </NcActionButton>
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
    </template>

    <!-- Sort by panel -->
    <template v-if="panel === 'sort'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox v-if="showSortItemToggle" :checked="sortItemToggleOn" @update:checked="toggleSortItemScope">
        {{ sortItemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showSortItemToggle" />

      <NcActionButton
        v-for="opt in sortDef.sortKeys"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="sortSetting.key"
        @update:modelValue="changeSortKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="sortKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Ascending')"
        value="asc"
        :model-value="sortSetting.dir"
        @update:modelValue="changeSortDir('asc')"
        close-after-click
      >
        <template #icon><SortAscIcon :size="20" /></template>
        {{ t('memories', 'Ascending') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Descending')"
        value="desc"
        :model-value="sortSetting.dir"
        @update:modelValue="changeSortDir('desc')"
        close-after-click
      >
        <template #icon><SortDescIcon :size="20" /></template>
        {{ t('memories', 'Descending') }}
      </NcActionButton>
    </template>

    <!-- Group by panel -->
    <template v-if="panel === 'group'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox v-if="showGroupItemToggle" :checked="groupItemToggleOn" @update:checked="toggleGroupItemScope">
        {{ groupItemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showGroupItemToggle" />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'None')"
        value="none"
        :model-value="groupSetting.key"
        @update:modelValue="changeGroupKey('none')"
        close-after-click
      >
        <template #icon><NoGroupIcon :size="20" /></template>
        {{ t('memories', 'None') }}
      </NcActionButton>

      <NcActionButton
        v-for="opt in groupKeyOpts"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="groupSetting.key"
        @update:modelValue="changeGroupKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="groupKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <template v-if="groupSetting.key !== 'none'">
        <NcActionSeparator />

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Ascending')"
          value="asc"
          :model-value="groupSetting.dir"
          @update:modelValue="changeGroupDir('asc')"
          close-after-click
        >
          <template #icon><SortAscIcon :size="20" /></template>
          {{ t('memories', 'Ascending') }}
        </NcActionButton>

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Descending')"
          value="desc"
          :model-value="groupSetting.dir"
          @update:modelValue="changeGroupDir('desc')"
          close-after-click
        >
          <template #icon><SortDescIcon :size="20" /></template>
          {{ t('memories', 'Descending') }}
        </NcActionButton>
      </template>
    </template>

    <!-- Subfolder tile Sort by panel (Folders route only) -->
    <template v-if="panel === 'folderSort'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox
        v-if="showFolderSortItemToggle"
        :checked="folderSortItemToggleOn"
        @update:checked="toggleFolderSortItemScope"
      >
        {{ t('memories', 'Custom subfolder sort for this folder') }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showFolderSortItemToggle" />

      <NcActionButton
        v-for="opt in folderSortDef.sortKeys"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="folderSortSetting.key"
        @update:modelValue="changeFolderSortKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="sortKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Ascending')"
        value="asc"
        :model-value="folderSortSetting.dir"
        @update:modelValue="changeFolderSortDir('asc')"
        close-after-click
      >
        <template #icon><SortAscIcon :size="20" /></template>
        {{ t('memories', 'Ascending') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Descending')"
        value="desc"
        :model-value="folderSortSetting.dir"
        @update:modelValue="changeFolderSortDir('desc')"
        close-after-click
      >
        <template #icon><SortDescIcon :size="20" /></template>
        {{ t('memories', 'Descending') }}
      </NcActionButton>
    </template>

    <!-- Subfolder tile Group by panel (Folders route only) -->
    <template v-if="panel === 'folderGroup'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox
        v-if="showFolderGroupItemToggle"
        :checked="folderGroupItemToggleOn"
        @update:checked="toggleFolderGroupItemScope"
      >
        {{ t('memories', 'Custom subfolder grouping for this folder') }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showFolderGroupItemToggle" />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'None')"
        value="none"
        :model-value="folderGroupSetting.key"
        @update:modelValue="changeFolderGroupKey('none')"
        close-after-click
      >
        <template #icon><NoGroupIcon :size="20" /></template>
        {{ t('memories', 'None') }}
      </NcActionButton>

      <NcActionButton
        v-for="opt in folderGroupKeyOpts"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="folderGroupSetting.key"
        @update:modelValue="changeFolderGroupKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="groupKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <template v-if="folderGroupSetting.key !== 'none'">
        <NcActionSeparator />

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Ascending')"
          value="asc"
          :model-value="folderGroupSetting.dir"
          @update:modelValue="changeFolderGroupDir('asc')"
          close-after-click
        >
          <template #icon><SortAscIcon :size="20" /></template>
          {{ t('memories', 'Ascending') }}
        </NcActionButton>

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Descending')"
          value="desc"
          :model-value="folderGroupSetting.dir"
          @update:modelValue="changeFolderGroupDir('desc')"
          close-after-click
        >
          <template #icon><SortDescIcon :size="20" /></template>
          {{ t('memories', 'Descending') }}
        </NcActionButton>
      </template>
    </template>

    <!-- Sub-tag tile Sort by panel (EmbeddedTags route only) -->
    <template v-if="panel === 'tagSort'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox v-if="showTagSortItemToggle" :checked="tagSortItemToggleOn" @update:checked="toggleTagSortItemScope">
        {{ t('memories', 'Custom sub-tag sort for this tag') }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showTagSortItemToggle" />

      <NcActionButton
        v-for="opt in tagSortDef.sortKeys"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="tagSortSetting.key"
        @update:modelValue="changeTagSortKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="sortKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Ascending')"
        value="asc"
        :model-value="tagSortSetting.dir"
        @update:modelValue="changeTagSortDir('asc')"
        close-after-click
      >
        <template #icon><SortAscIcon :size="20" /></template>
        {{ t('memories', 'Ascending') }}
      </NcActionButton>

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'Descending')"
        value="desc"
        :model-value="tagSortSetting.dir"
        @update:modelValue="changeTagSortDir('desc')"
        close-after-click
      >
        <template #icon><SortDescIcon :size="20" /></template>
        {{ t('memories', 'Descending') }}
      </NcActionButton>
    </template>

    <!-- Sub-tag tile Group by panel (EmbeddedTags route only) -->
    <template v-if="panel === 'tagGroup'">
      <NcActionButton @click="panel = 'root'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox
        v-if="showTagGroupItemToggle"
        :checked="tagGroupItemToggleOn"
        @update:checked="toggleTagGroupItemScope"
      >
        {{ t('memories', 'Custom sub-tag grouping for this tag') }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showTagGroupItemToggle" />

      <NcActionButton
        type="radio"
        :aria-label="t('memories', 'None')"
        value="none"
        :model-value="tagGroupSetting.key"
        @update:modelValue="changeTagGroupKey('none')"
        close-after-click
      >
        <template #icon><NoGroupIcon :size="20" /></template>
        {{ t('memories', 'None') }}
      </NcActionButton>

      <NcActionButton
        v-for="opt in tagGroupKeyOpts"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="tagGroupSetting.key"
        @update:modelValue="changeTagGroupKey(opt.key)"
        close-after-click
      >
        <template #icon><component :is="groupKeyIcon(opt.key)" :size="20" /></template>
        {{ opt.label }}
      </NcActionButton>

      <template v-if="tagGroupSetting.key !== 'none'">
        <NcActionSeparator />

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Ascending')"
          value="asc"
          :model-value="tagGroupSetting.dir"
          @update:modelValue="changeTagGroupDir('asc')"
          close-after-click
        >
          <template #icon><SortAscIcon :size="20" /></template>
          {{ t('memories', 'Ascending') }}
        </NcActionButton>

        <NcActionButton
          type="radio"
          :aria-label="t('memories', 'Descending')"
          value="desc"
          :model-value="tagGroupSetting.dir"
          @update:modelValue="changeTagGroupDir('desc')"
          close-after-click
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
import viewModeScope, { type ImageDisplayShape } from '@services/view-mode-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';
import { zoomLabel, clampZoom, ZOOM_MIN, ZOOM_MAX } from '@services/zoom';
import { sortScope, groupScope } from '@services/list-scope';
import { getListScopeDef, listScopeTargetFor, type ListScopeTarget, type SortKey, type GroupKey, type SortDir } from '@services/sort-group-defs';

import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';
import SortAscIcon from 'vue-material-design-icons/SortAscending.vue';
import SortDescIcon from 'vue-material-design-icons/SortDescending.vue';
import NoGroupIcon from 'vue-material-design-icons/FormatListBulleted.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue';

// Sort by / Group by / View mode option icons
import SortAlphaIcon from 'vue-material-design-icons/SortAlphabeticalVariant.vue';
import FileSizeIcon from 'vue-material-design-icons/Harddisk.vue';
import UpdatedIcon from 'vue-material-design-icons/ClockOutline.vue';
import CreatedIcon from 'vue-material-design-icons/CalendarOutline.vue';
import CountIcon from 'vue-material-design-icons/Counter.vue';
import GroupDayIcon from 'vue-material-design-icons/CalendarTodayOutline.vue';
import GroupWeekIcon from 'vue-material-design-icons/CalendarWeekOutline.vue';
import GroupMonthIcon from 'vue-material-design-icons/CalendarMonthOutline.vue';
import GroupYearIcon from 'vue-material-design-icons/CalendarBlankOutline.vue';
import ViewDynamicIcon from 'vue-material-design-icons/ViewComfyOutline.vue';
import ViewSquareIcon from 'vue-material-design-icons/CropSquare.vue';
import ViewListIcon from 'vue-material-design-icons/ViewListOutline.vue';

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

const GROUP_KEY_LABELS: Record<Exclude<GroupKey, 'none'>, string> = {
  day: 'Day',
  week: 'Week',
  month: 'Month',
  year: 'Year',
  name: 'Name',
};

/** Icon per Sort by key, shown next to each option in the Sort by panels. */
const SORT_KEY_ICONS: Record<SortKey, unknown> = {
  name: SortAlphaIcon,
  size: FileSizeIcon,
  updated: UpdatedIcon,
  created: CreatedIcon,
  count: CountIcon,
};

/** Icon per Group by key (excluding 'none', which always uses NoGroupIcon directly). */
const GROUP_KEY_ICONS: Record<Exclude<GroupKey, 'none'>, unknown> = {
  day: GroupDayIcon,
  week: GroupWeekIcon,
  month: GroupMonthIcon,
  year: GroupYearIcon,
  name: SortAlphaIcon,
};

/** Icon per View mode shape. */
const SHAPE_ICONS: Record<ImageDisplayShape, unknown> = {
  dynamic: ViewDynamicIcon,
  square: ViewSquareIcon,
  list: ViewListIcon,
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
    SortVariantIcon,
    SortAscIcon,
    SortDescIcon,
    NoGroupIcon,
    ImageMultipleIcon,
    TuneIcon,
    ArrowLeftIcon,
    ViewDynamicIcon,
    ViewSquareIcon,
    ViewListIcon,
  },

  mixins: [ViewPrefs],

  data: () => ({
    panel: 'root' as 'root' | 'shape' | 'props' | 'sort' | 'group' | 'folderSort' | 'folderGroup' | 'tagSort' | 'tagGroup',
  }),

  computed: {
    shapeLabel(): string {
      return this.t('memories', SHAPE_LABELS[this.effectiveShape]);
    },

    /** Whether this route has a second, independently-sortable tile row (Folders/EmbeddedTags) alongside its main content. */
    hasTileSection(): boolean {
      return this.routeId === 'Folders' || this.routeId === 'EmbeddedTags';
    },

    /**
     * Recognize/FaceRecognition/Places cluster lists have no sortable/groupable
     * backend fields (reverted -- see sort-group-defs.ts), so Sort by/Group by
     * would be inert buttons there; hide them on those *list* pages only --
     * an opened person/place's photo grid still sorts/groups like any other.
     */
    hasSortGroup(): boolean {
      const isClusterListRoute = this.routeId === 'Recognize' || this.routeId === 'FaceRecognition' || this.routeId === 'Places';
      return !isClusterListRoute || !!this.$route.params.name;
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

    /** Which Sort by / Group by target governs this route's primary content (see @services/sort-group-defs). */
    listScopeTarget(): ListScopeTarget | null {
      return listScopeTargetFor(this.routeId, this.$route);
    },

    /** Shared applicability def (sort keys, group keys, defaults) for the current listScopeTarget. */
    sortDef() {
      return getListScopeDef(this.listScopeTarget);
    },

    groupKeyOpts(): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return this.sortDef.groupKeys.map((key) => ({ key, label: this.t('memories', GROUP_KEY_LABELS[key]) }));
    },

    sortSetting() {
      return sortScope.resolve(this.listScopeTarget, this.scopeKey);
    },

    groupSetting() {
      return groupScope.resolve(this.listScopeTarget, this.scopeKey);
    },

    sortLabel(): string {
      const opt = this.sortDef.sortKeys.find((o) => o.key === this.sortSetting.key);
      return opt?.label ?? '';
    },

    groupLabel(): string {
      if (this.groupSetting.key === 'none') return this.t('memories', 'None');
      return this.t('memories', GROUP_KEY_LABELS[this.groupSetting.key]);
    },

    /** Item-scope toggles for Sort by / Group by are offered on the same routes as View mode's. */
    showSortItemToggle(): boolean {
      return this.scopeKey != null && this.sortItemToggleLabel != null;
    },

    sortItemToggleLabel(): string | null {
      switch (this.routeId) {
        case 'Folders':
        case 'FolderShare':
          return this.t('memories', 'Custom sort for this folder');
        case 'Albums':
        case 'AlbumShare':
          return this.t('memories', 'Custom sort for this album');
        case 'Tags':
        case 'EmbeddedTags':
          return this.t('memories', 'Custom sort for this tag');
        default:
          return null;
      }
    },

    sortItemToggleOn(): boolean {
      return sortScope.isEnabled(this.listScopeTarget, this.scopeKey);
    },

    showGroupItemToggle(): boolean {
      return this.scopeKey != null && this.groupItemToggleLabel != null;
    },

    groupItemToggleLabel(): string | null {
      switch (this.routeId) {
        case 'Folders':
        case 'FolderShare':
          return this.t('memories', 'Custom grouping for this folder');
        case 'Albums':
        case 'AlbumShare':
          return this.t('memories', 'Custom grouping for this album');
        case 'Tags':
        case 'EmbeddedTags':
          return this.t('memories', 'Custom grouping for this tag');
        default:
          return null;
      }
    },

    groupItemToggleOn(): boolean {
      return groupScope.isEnabled(this.listScopeTarget, this.scopeKey);
    },

    /**
     * Subfolder tile Sort by / Group by -- only relevant on the Folders
     * route (see the root panel's `routeId === 'Folders'` guard), always
     * targeting 'FoldersTiles' rather than `listScopeTarget`, independent of
     * the photo timeline's own Sort by / Group by above.
     */
    folderSortDef() {
      return getListScopeDef('FoldersTiles');
    },

    folderSortSetting() {
      return sortScope.resolve('FoldersTiles', this.scopeKey);
    },

    folderGroupSetting() {
      return groupScope.resolve('FoldersTiles', this.scopeKey);
    },

    folderGroupKeyOpts(): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return this.folderSortDef.groupKeys.map((key) => ({ key, label: this.t('memories', GROUP_KEY_LABELS[key]) }));
    },

    folderSortLabel(): string {
      const opt = this.folderSortDef.sortKeys.find((o) => o.key === this.folderSortSetting.key);
      return opt?.label ?? '';
    },

    folderGroupLabel(): string {
      if (this.folderGroupSetting.key === 'none') return this.t('memories', 'None');
      return this.t('memories', GROUP_KEY_LABELS[this.folderGroupSetting.key]);
    },

    showFolderSortItemToggle(): boolean {
      return this.scopeKey != null;
    },

    showFolderGroupItemToggle(): boolean {
      return this.scopeKey != null;
    },

    folderSortItemToggleOn(): boolean {
      return sortScope.isEnabled('FoldersTiles', this.scopeKey);
    },

    folderGroupItemToggleOn(): boolean {
      return groupScope.isEnabled('FoldersTiles', this.scopeKey);
    },

    /**
     * Sub-tag tile Sort by / Group by -- only relevant on the EmbeddedTags
     * route (see the root panel's `routeId === 'EmbeddedTags'` guard), always
     * targeting 'EmbeddedTagsTiles' rather than `listScopeTarget`, independent
     * of the photo timeline's own Sort by / Group by above.
     */
    tagSortDef() {
      return getListScopeDef('EmbeddedTagsTiles');
    },

    tagSortSetting() {
      return sortScope.resolve('EmbeddedTagsTiles', this.scopeKey);
    },

    tagGroupSetting() {
      return groupScope.resolve('EmbeddedTagsTiles', this.scopeKey);
    },

    tagGroupKeyOpts(): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return this.tagSortDef.groupKeys.map((key) => ({ key, label: this.t('memories', GROUP_KEY_LABELS[key]) }));
    },

    tagSortLabel(): string {
      const opt = this.tagSortDef.sortKeys.find((o) => o.key === this.tagSortSetting.key);
      return opt?.label ?? '';
    },

    tagGroupLabel(): string {
      if (this.tagGroupSetting.key === 'none') return this.t('memories', 'None');
      return this.t('memories', GROUP_KEY_LABELS[this.tagGroupSetting.key]);
    },

    showTagSortItemToggle(): boolean {
      return this.scopeKey != null;
    },

    showTagGroupItemToggle(): boolean {
      return this.scopeKey != null;
    },

    tagSortItemToggleOn(): boolean {
      return sortScope.isEnabled('EmbeddedTagsTiles', this.scopeKey);
    },

    tagGroupItemToggleOn(): boolean {
      return groupScope.isEnabled('EmbeddedTagsTiles', this.scopeKey);
    },
  },

  created() {
    sortScope.init();
    groupScope.init();
  },

  methods: {
    sortKeyIcon(key: SortKey) {
      return SORT_KEY_ICONS[key];
    },

    groupKeyIcon(key: Exclude<GroupKey, 'none'>) {
      return GROUP_KEY_ICONS[key];
    },

    shapeIcon(shape: ImageDisplayShape) {
      return SHAPE_ICONS[shape];
    },

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

    changeSortKey(key: SortKey): void {
      this.writeSort({ key, dir: this.sortSetting.dir });
    },

    changeSortDir(dir: SortDir): void {
      this.writeSort({ key: this.sortSetting.key, dir });
    },

    toggleSortItemScope(checked: boolean): void {
      if (this.listScopeTarget == null || this.scopeKey == null) return;

      if (checked) {
        sortScope.enable(this.listScopeTarget, this.scopeKey, this.sortSetting);
      } else {
        sortScope.disable(this.listScopeTarget, this.scopeKey);
      }
    },

    writeSort(value: { key: SortKey; dir: SortDir }): void {
      if (this.listScopeTarget == null) return;

      if (this.sortItemToggleOn && this.scopeKey != null) {
        sortScope.setItem(this.listScopeTarget, this.scopeKey, value);
      } else {
        sortScope.setView(this.listScopeTarget, value);
      }
    },

    changeGroupKey(key: GroupKey): void {
      this.writeGroup({ key, dir: this.groupSetting.dir });
    },

    changeGroupDir(dir: SortDir): void {
      this.writeGroup({ key: this.groupSetting.key, dir });
    },

    toggleGroupItemScope(checked: boolean): void {
      if (this.listScopeTarget == null || this.scopeKey == null) return;

      if (checked) {
        groupScope.enable(this.listScopeTarget, this.scopeKey, this.groupSetting);
      } else {
        groupScope.disable(this.listScopeTarget, this.scopeKey);
      }
    },

    writeGroup(value: { key: GroupKey; dir: SortDir }): void {
      if (this.listScopeTarget == null) return;

      if (this.groupItemToggleOn && this.scopeKey != null) {
        groupScope.setItem(this.listScopeTarget, this.scopeKey, value);
      } else {
        groupScope.setView(this.listScopeTarget, value);
      }
    },

    changeFolderSortKey(key: SortKey): void {
      this.writeFolderSort({ key, dir: this.folderSortSetting.dir });
    },

    changeFolderSortDir(dir: SortDir): void {
      this.writeFolderSort({ key: this.folderSortSetting.key, dir });
    },

    toggleFolderSortItemScope(checked: boolean): void {
      if (this.scopeKey == null) return;

      if (checked) {
        sortScope.enable('FoldersTiles', this.scopeKey, this.folderSortSetting);
      } else {
        sortScope.disable('FoldersTiles', this.scopeKey);
      }
    },

    writeFolderSort(value: { key: SortKey; dir: SortDir }): void {
      if (this.folderSortItemToggleOn && this.scopeKey != null) {
        sortScope.setItem('FoldersTiles', this.scopeKey, value);
      } else {
        sortScope.setView('FoldersTiles', value);
      }
    },

    changeFolderGroupKey(key: GroupKey): void {
      this.writeFolderGroup({ key, dir: this.folderGroupSetting.dir });
    },

    changeFolderGroupDir(dir: SortDir): void {
      this.writeFolderGroup({ key: this.folderGroupSetting.key, dir });
    },

    toggleFolderGroupItemScope(checked: boolean): void {
      if (this.scopeKey == null) return;

      if (checked) {
        groupScope.enable('FoldersTiles', this.scopeKey, this.folderGroupSetting);
      } else {
        groupScope.disable('FoldersTiles', this.scopeKey);
      }
    },

    writeFolderGroup(value: { key: GroupKey; dir: SortDir }): void {
      if (this.folderGroupItemToggleOn && this.scopeKey != null) {
        groupScope.setItem('FoldersTiles', this.scopeKey, value);
      } else {
        groupScope.setView('FoldersTiles', value);
      }
    },

    changeTagSortKey(key: SortKey): void {
      this.writeTagSort({ key, dir: this.tagSortSetting.dir });
    },

    changeTagSortDir(dir: SortDir): void {
      this.writeTagSort({ key: this.tagSortSetting.key, dir });
    },

    toggleTagSortItemScope(checked: boolean): void {
      if (this.scopeKey == null) return;

      if (checked) {
        sortScope.enable('EmbeddedTagsTiles', this.scopeKey, this.tagSortSetting);
      } else {
        sortScope.disable('EmbeddedTagsTiles', this.scopeKey);
      }
    },

    writeTagSort(value: { key: SortKey; dir: SortDir }): void {
      if (this.tagSortItemToggleOn && this.scopeKey != null) {
        sortScope.setItem('EmbeddedTagsTiles', this.scopeKey, value);
      } else {
        sortScope.setView('EmbeddedTagsTiles', value);
      }
    },

    changeTagGroupKey(key: GroupKey): void {
      this.writeTagGroup({ key, dir: this.tagGroupSetting.dir });
    },

    changeTagGroupDir(dir: SortDir): void {
      this.writeTagGroup({ key: this.tagGroupSetting.key, dir });
    },

    toggleTagGroupItemScope(checked: boolean): void {
      if (this.scopeKey == null) return;

      if (checked) {
        groupScope.enable('EmbeddedTagsTiles', this.scopeKey, this.tagGroupSetting);
      } else {
        groupScope.disable('EmbeddedTagsTiles', this.scopeKey);
      }
    },

    writeTagGroup(value: { key: GroupKey; dir: SortDir }): void {
      if (this.tagGroupItemToggleOn && this.scopeKey != null) {
        groupScope.setItem('EmbeddedTagsTiles', this.scopeKey, value);
      } else {
        groupScope.setView('EmbeddedTagsTiles', value);
      }
    },

    openPropsPanel(): void {
      this.panel = 'props';
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

    toggle<K extends keyof ImagePropsBundle>(key: K, value: boolean, remote?: string): void {
      (this.config as any)[key] = value;
      this.updateSetting(key, remote);
    },
  },
});
</script>
