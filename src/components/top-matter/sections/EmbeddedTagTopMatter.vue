<template>
  <div class="top-matter">
    <div class="top-row">
      <div class="name">{{ t('memories', 'Embedded Tags') }}</div>
      <div class="right-actions">
        <FilterDropdownButton />

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

        <ViewOptionsAction>
          <NcActionSeparator />

          <NcActionButton @click="toggleRecursive" close-after-click>
            {{ recursive ? t('memories', 'Tag view') : t('memories', 'Timeline view') }}
            <template #icon>
              <TagsIcon v-if="recursive" :size="20" />
              <TimelineIcon v-else :size="20" />
            </template>
          </NcActionButton>
        </ViewOptionsAction>

        <RefreshOptionsAction />

        <NcActions :aria-label="t('memories', 'Others')">
          <template #icon> <DotsHorizontalIcon :size="20" /> </template>
          <NcActionText :name="t('memories', 'More actions coming soon')" />
        </NcActions>
      </div>
    </div>

    <NcBreadcrumbs :key="$route.path">
      <NcBreadcrumb :name="t('memories', 'Embedded Tags')" :to="getRoute([])">
        <template #icon>
          <TagsIcon :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="tag in list" :key="tag.idx" :name="tag.text" :to="getRoute(tag.path)" />
    </NcBreadcrumbs>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import NcActionText from '@nextcloud/vue/dist/Components/NcActionText.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import ViewOptionsAction from '@components/top-actions/ViewOptionsAction.vue';
import RefreshOptionsAction from '@components/top-actions/RefreshOptionsAction.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import * as dav from '@services/dav';

import TagsIcon from 'vue-material-design-icons/Tag.vue';
import TagPlusIcon from 'vue-material-design-icons/TagPlus.vue';
import TagMinusIcon from 'vue-material-design-icons/TagMinus.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';

export default defineComponent({
  name: 'EmbeddedTagTopMatter',

  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    NcActionSeparator,
    NcActionText,
    NcButton,
    ViewOptionsAction,
    RefreshOptionsAction,
    FilterDropdownButton,
    TagsIcon,
    TagPlusIcon,
    TagMinusIcon,
    TimelineIcon,
    DotsHorizontalIcon,
  },

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

    list(): {
      text: string;
      path: string[];
      idx: number;
    }[] {
      return this.pathParts.map((text, idx, arr) => {
        const path = arr.slice(0, idx + 1);
        return { text, path, idx };
      });
    },

    recursive(): boolean {
      return !!this.$route.query.recursive;
    },
  },

  methods: {
    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

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

  .breadcrumb {
    min-width: 0;
    height: unset;
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}
</style>
