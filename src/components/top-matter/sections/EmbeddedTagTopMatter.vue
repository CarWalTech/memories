<template>
  <div class="top-matter">
    <div class="top-row">
      <div class="name">{{ t('memories', 'Embedded Tags') }}</div>
      <div class="right-actions">
        <NcActions :inline="2">
          <NcActionButton :aria-label="t('memories', 'New tag')" @click="createTag" close-after-click>
            {{ t('memories', 'New tag') }}
            <template #icon> <TagPlusIcon :size="20" /> </template>
          </NcActionButton>

          <NcActionButton v-if="path" :aria-label="t('memories', 'Delete tag')" @click="deleteTag" close-after-click>
            {{ t('memories', 'Delete tag') }}
            <template #icon> <TagMinusIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>

        <ViewModeAction />

        <SortByAction />

        <FilterDropdownAction />

        <NcActions :aria-label="t('memories', 'Others')" @closed="closePanel">
          <template #icon> <DotsHorizontalIcon :size="20" /> </template>

          <template v-if="panel === 'root'">
            <NcActionButton :aria-label="t('memories', 'Prune empty tags')" @click="pruneEmpty" close-after-click>
              {{ t('memories', 'Prune empty tags') }}
              <template #icon> <TagOffIcon :size="20" /> </template>
            </NcActionButton>

            <NcActionButton :aria-label="t('memories', 'Find missing tags')" @click="findMissing" close-after-click>
              {{ t('memories', 'Find missing tags') }}
              <template #icon> <TagSearchIcon :size="20" /> </template>
            </NcActionButton>

            <NcActionSeparator />
          </template>

          <RefreshMenuActions :panel="panel" @open="openPanel('refresh')" @back="closePanel" />
        </NcActions>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import ViewModeAction from '@components/top-actions/ViewModeAction.vue';
import SortByAction from '@components/top-actions/SortByAction.vue';
import FilterDropdownAction from '@components/top-actions/FilterDropdownAction.vue';
import RefreshMenuActions from '@components/top-actions/RefreshMenuActions';

import Submenu from '@mixins/Submenu';
import * as dav from '@services/dav';

import TagPlusIcon from 'vue-material-design-icons/TagPlus.vue';
import TagMinusIcon from 'vue-material-design-icons/TagMinus.vue';
import TagOffIcon from 'vue-material-design-icons/TagOff.vue';
import TagSearchIcon from 'vue-material-design-icons/TagSearch.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';

export default defineComponent({
  name: 'EmbeddedTagTopMatter',

  components: {
    NcActions,
    NcActionButton,
    NcActionSeparator,
    NcButton,
    ViewModeAction,
    SortByAction,
    FilterDropdownAction,
    RefreshMenuActions,
    TagPlusIcon,
    TagMinusIcon,
    TagOffIcon,
    TagSearchIcon,
    DotsHorizontalIcon,
  },

  /** `Submenu` owns `panel` for the "Others" dropdown: 'root' plus the 'refresh' drill-down. */
  mixins: [Submenu],

  computed: {
    pathParts(): string[] {
      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/');
      }
      return path.filter(Boolean);
    },

    /** Current full tag path, e.g. "Family/Vacation", or '' at the root */
    path(): string {
      return this.pathParts.join('/');
    },
  },

  methods: {
    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    async createTag(): Promise<void> {
      const name = await dav.createEmbeddedTagWithPrompt(this.path);
      if (!name) return;

      // Navigate straight into the new tag
      this.$router.push(this.getRoute([...this.pathParts, name]));
    },

    async deleteTag(): Promise<void> {
      const currentName = this.pathParts[this.pathParts.length - 1] ?? '';
      const deleted = await dav.deleteEmbeddedTagWithConfirm(this.path, currentName);
      if (!deleted) return;

      // Navigate up to the parent tag
      this.$router.push(this.getRoute(this.pathParts.slice(0, -1)));
    },

    async pruneEmpty(): Promise<void> {
      await dav.pruneEmptyEmbeddedTagsWithConfirm();
    },

    async findMissing(): Promise<void> {
      await dav.findMissingEmbeddedTags();
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
