<template>
  <NcActions v-if="applicable" :aria-label="`${titleLabel}: ${sortLabel}`" :force-menu="true" @closed="panel = 'sort'">
    <template #icon>
      <component :is="triggerIcon" :size="20" />
    </template>

    <!-- Sort by panel (root) -->
    <template v-if="panel === 'sort'">
      <NcActionCheckbox v-if="showItemToggle" :checked="itemToggleOn" @update:checked="toggleItemScope">
        {{ itemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="showItemToggle" />

      <NcActionButton
        v-for="opt in def.sortKeys"
        :key="opt.key"
        type="radio"
        :aria-label="opt.label"
        :value="opt.key"
        :model-value="setting.key"
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
        :model-value="setting.dir"
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
        :model-value="setting.dir"
        @update:modelValue="changeSortDir('desc')"
        close-after-click
      >
        <template #icon><SortDescIcon :size="20" /></template>
        {{ t('memories', 'Descending') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton @click="panel = 'group'">
        <template #icon>
          <NoGroupIcon v-if="groupSetting.key === 'none'" :size="20" />
          <component :is="groupTriggerIconFor(groupSetting)" v-else :size="20" />
        </template>
        {{ t('memories', 'Group by') }}: {{ groupLabel }}
      </NcActionButton>
    </template>

    <!-- Group by panel (nested under Sort by) -->
    <template v-if="panel === 'group'">
      <NcActionButton @click="panel = 'sort'">
        <template #icon><ArrowLeftIcon :size="20" /></template>
        {{ t('memories', 'Back') }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionCheckbox v-if="groupShowItemToggle" :checked="groupItemToggleOn" @update:checked="toggleGroupItemScope">
        {{ groupItemToggleLabel }}
      </NcActionCheckbox>

      <NcActionSeparator v-if="groupShowItemToggle" />

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
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';

import listScopeAction from '@mixins/ListScopeAction';
import { sortScope, groupScope } from '@services/list-scope';
import type { SortKey, GroupKey, SortDir, ListScopeDef } from '@services/sort-group-defs';

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

/** Icon per Sort by key, shown next to each option in the Sort by panel. */
const SORT_KEY_ICONS: Record<SortKey, unknown> = {
  name: SortAlphaIcon,
  size: FileSizeIcon,
  updated: UpdatedIcon,
  created: CreatedIcon,
  count: CountIcon,
};

/** Combined key+direction icon for the toolbar trigger -- mirrors the type distinctions above (alpha/numeric/clock/calendar) instead of a generic up/down arrow. */
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
  name: 'SortByAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    SortAscIcon,
    SortDescIcon,
    NoGroupIcon,
    ArrowLeftIcon,
  },

  mixins: [
    listScopeAction(sortScope, {
      title: 'Sort by',
      itemToggle: {
        folder: 'Custom sort for this folder',
        album: 'Custom sort for this album',
        tag: 'Custom sort for this tag',
      },
    }),
  ],

  data: () => ({
    /** Group by is a drill-down sub-panel of this same dropdown rather than its own toolbar button. */
    panel: 'sort' as 'sort' | 'group',
  }),

  computed: {
    sortLabel(): string {
      const opt = this.def.sortKeys.find((o) => o.key === this.setting.key);
      return opt?.label ?? '';
    },

    triggerIcon() {
      return this.sortTriggerIconFor(this.setting);
    },

    // -- Group by (nested panel) --

    groupSetting() {
      return groupScope.resolve(this.effectiveTarget, this.scopeKey);
    },

    groupKeyOpts(): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return this.groupKeyOptsFor(this.def);
    },

    groupLabel(): string {
      if (this.groupSetting.key === 'none') return this.t('memories', 'None');
      return this.t('memories', GROUP_KEY_LABELS[this.groupSetting.key as Exclude<GroupKey, 'none'>]);
    },

    groupShowItemToggle(): boolean {
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
      return groupScope.isEnabled(this.effectiveTarget, this.scopeKey);
    },
  },

  created() {
    groupScope.init();
  },

  methods: {
    sortKeyIcon(key: SortKey) {
      return SORT_KEY_ICONS[key];
    },

    sortTriggerIconFor(setting: { key: SortKey; dir: SortDir }) {
      return SORT_KEY_DIR_ICONS[setting.key][setting.dir];
    },

    changeSortKey(key: SortKey): void {
      this.write({ key, dir: this.setting.dir });
    },

    changeSortDir(dir: SortDir): void {
      this.write({ key: this.setting.key, dir });
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

    writeGroup(value: { key: GroupKey; dir: SortDir }): void {
      if (this.effectiveTarget == null) return;

      if (this.groupItemToggleOn && this.scopeKey != null) {
        groupScope.setItem(this.effectiveTarget, this.scopeKey, value);
      } else {
        groupScope.setView(this.effectiveTarget, value);
      }
    },

    changeGroupKey(key: GroupKey): void {
      this.writeGroup({ key, dir: this.groupSetting.dir });
    },

    changeGroupDir(dir: SortDir): void {
      this.writeGroup({ key: this.groupSetting.key, dir });
    },

    toggleGroupItemScope(checked: boolean): void {
      if (this.effectiveTarget == null || this.scopeKey == null) return;

      if (checked) {
        groupScope.enable(this.effectiveTarget, this.scopeKey, this.groupSetting);
      } else {
        groupScope.disable(this.effectiveTarget, this.scopeKey);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
// Tighten the button's own clickable-area/padding around its 20px icon -- same treatment as ViewModeAction,
// so every plain single-icon action button in the toolbar reads the same size.
:deep(.action-item__menutoggle) {
  width: 36px !important;
  height: 36px !important;
}
</style>
