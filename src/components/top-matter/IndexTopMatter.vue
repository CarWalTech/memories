<template>
  <div class="top-matter">
    <div class="top-row">
      <div class="right-actions">
        <NcButton
          type="tertiary-no-background"
          :title="t('memories', 'Refresh index')"
          :aria-label="t('memories', 'Refresh index')"
          @click="requestFullIndexRefresh"
        >
          <template #icon> <RefreshIcon :size="20" /> </template>
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import { requestFullIndexRefresh } from '@services/index-refresh';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

export default defineComponent({
  name: 'IndexTopMatter',

  components: {
    NcButton,
    RefreshIcon,
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
