<template>
  <div class="top-matter">
    <div class="top-row">
      <span class="name">{{ viewName }}</span>
      <div class="right-actions">
        <ViewModeAction />
        <SortByAction />
        <FilterDropdownAction />
        <RefreshOptionsAction />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import ViewModeAction from '@components/top-actions/ViewModeAction.vue';
import SortByAction from '@components/top-actions/SortByAction.vue';
import RefreshOptionsAction from '@components/top-actions/RefreshOptionsAction.vue';
import FilterDropdownAction from '@components/top-actions/FilterDropdownAction.vue';
import * as strings from '@services/strings';

export default defineComponent({
  name: 'IndexTopMatter',

  components: {
    ViewModeAction,
    SortByAction,
    RefreshOptionsAction,
    FilterDropdownAction,
  },

  computed: {
    viewName(): string {
      return strings.viewName(this.$route.name!);
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  padding: 8px;
  display: flex;
  flex-direction: column;
  row-gap: 4px;

  .top-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    .name {
      height: 42px;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}
</style>
