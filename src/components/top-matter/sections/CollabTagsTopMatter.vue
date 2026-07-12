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
        <ViewOptionsAction />
        <FilterDropdownButton />
        <RefreshOptionsAction />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import ViewOptionsAction from '@components/top-actions/ViewOptionsAction.vue';
import RefreshOptionsAction from '@components/top-actions/RefreshOptionsAction.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import * as strings from '@services/strings';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';

export default defineComponent({
  name: 'CollabTagsTopMatter',
  components: {
    NcActions,
    NcActionButton,
    ViewOptionsAction,
    RefreshOptionsAction,
    FilterDropdownButton,
    BackIcon,
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
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  padding: 8px;
  display: flex;
  flex-direction: column;
  row-gap: 4px;
  > .text {
    flex-grow: 1;
    line-height: 42px;
    padding-left: 8px;
  }

  .top-row {
    display: flex;
    align-items: center;
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}
</style>
