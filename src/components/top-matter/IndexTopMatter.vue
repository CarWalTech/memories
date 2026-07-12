<template>
  <div class="top-matter">
    <div class="top-row">
      <div class="right-actions">
        <ViewOptionsActions />
        <FilterDropdownButton />
        <NcActions :aria-label="t('memories', 'Sort options')">
          <template #icon> <SortVariantIcon :size="20" /> </template>
          <NcActionText :name="t('memories', 'WIP')" />
        </NcActions>

        <NcActions :aria-label="t('memories', 'Refresh')">
          <template #icon> <RefreshIcon :size="20" /> </template>

          <NcActionButton close-after-click @click="requestFullIndexRefresh('all')">
            {{ t('memories', 'Refresh files') }}
          </NcActionButton>
          <NcActionButton close-after-click @click="requestFullIndexRefresh('untracked')">
            {{ t('memories', 'Refresh untracked files') }}
          </NcActionButton>
        </NcActions>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionText from '@nextcloud/vue/dist/Components/NcActionText.js';
import ViewOptionsActions from '@components/ViewOptionsActions.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import { requestFullIndexRefresh } from '@services/index-refresh';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';

export default defineComponent({
  name: 'IndexTopMatter',

  components: {
    NcActions,
    NcActionButton,
    NcActionText,
    ViewOptionsActions,
    FilterDropdownButton,
    RefreshIcon,
    SortVariantIcon,
  },

  methods: {
    requestFullIndexRefresh,
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
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}
</style>
