<template>
  <div class="top-matter">
    <div class="top-row">
      <NcActions v-if="name">
        <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <span class="name">{{ name || viewname }}</span>

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

import * as strings from '@services/strings';
import { requestFullIndexRefresh } from '@services/index-refresh';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';

export default defineComponent({
  name: 'CollabTagsTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionText,
    ViewOptionsActions,
    BackIcon,
    RefreshIcon,
    SortVariantIcon,
  },

  computed: {
    viewname(): string {
      return strings.viewName(this.$route.name!);
    },

    name(): string | null {
      switch (this.$route.name) {
        case _m.routes.Tags.name:
          return this.t('recognize', this.$route.params.name);
        default:
          return null;
      }
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    requestFullIndexRefresh,
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  padding: 8px;

  .top-row {
    display: flex;
    align-items: center;
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-grow: 1;
    justify-content: flex-end;
  }
}
</style>
