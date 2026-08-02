<template>
  <NcActions v-if="applicable" :aria-label="`${titleLabel}: ${sortLabel}`" :force-menu="true" @closed="closePanel">
    <template #icon>
      <component :is="triggerIcon" :size="20" />
    </template>

    <!-- Sort by panel (root) -->
    <template v-if="panel === 'root'">
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

      <NcActionButton is-menu @click="openPanel('group')">
        <template #icon>
          <NoGroupIcon v-if="groupSetting.key === 'none'" :size="20" />
          <component :is="groupTriggerIconFor(groupSetting)" v-else :size="20" />
        </template>
        {{ t('memories', 'Group by') }}: {{ groupLabel }}
      </NcActionButton>
    </template>

    <!-- Group by panel (nested under Sort by) -->
    <template v-if="panel === 'group'">
      <SubmenuBackAction @back="closePanel" />

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
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import SubmenuBackAction from './SubmenuBackAction';

import listScopeAction from '@mixins/ListScopeAction';
import Submenu from '@mixins/Submenu';
import { sortScope, groupScope, writeScoped } from '@services/list-scope';
import {
  sortKeyIcon,
  sortTriggerIconFor,
  groupKeyIcon,
  groupKeyOptsFor,
  groupTriggerIconFor,
  groupLabelFor,
} from '@services/sort-group-icons';
import type { SortKey, GroupKey, SortDir } from '@services/sort-group-defs';

import SortAscIcon from 'vue-material-design-icons/SortAscending.vue';
import SortDescIcon from 'vue-material-design-icons/SortDescending.vue';
import NoGroupIcon from 'vue-material-design-icons/FormatListBulleted.vue';

export default defineComponent({
  name: 'SortByAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionSeparator,
    SubmenuBackAction,
    SortAscIcon,
    SortDescIcon,
    NoGroupIcon,
  },

  /** `Submenu` owns `panel`: 'root' (Sort by) plus the 'group' drill-down. */
  mixins: [listScopeAction(sortScope, { title: 'Sort by' }), Submenu],

  computed: {
    sortLabel(): string {
      const opt = this.def.sortKeys.find((o) => o.key === this.setting.key);
      return opt?.label ?? '';
    },

    triggerIcon() {
      return sortTriggerIconFor(this.setting);
    },

    // -- Group by (nested panel) --

    groupSetting() {
      return groupScope.resolve(this.effectiveTarget, this.scopeKey);
    },

    groupKeyOpts(): { key: Exclude<GroupKey, 'none'>; label: string }[] {
      return groupKeyOptsFor(this.def);
    },

    groupLabel(): string {
      return groupLabelFor(this.groupSetting);
    },
  },

  created() {
    groupScope.init();
  },

  methods: {
    sortKeyIcon,
    groupKeyIcon,
    groupTriggerIconFor,

    changeSortKey(key: SortKey): void {
      this.write({ key, dir: this.setting.dir });
    },

    changeSortDir(dir: SortDir): void {
      this.write({ key: this.setting.key, dir });
    },

    changeGroupKey(key: GroupKey): void {
      writeScoped(groupScope, this.effectiveTarget, this.scopeKey, { key, dir: this.groupSetting.dir });
    },

    changeGroupDir(dir: SortDir): void {
      writeScoped(groupScope, this.effectiveTarget, this.scopeKey, { key: this.groupSetting.key, dir });
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
