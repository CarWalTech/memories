<template>
  <EmbeddedTagGrid v-if="show" :items="tags" :group-setting="groupSetting" @deleted="onDeleted" />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import UserConfig from '@mixins/UserConfig';
import EmbeddedTagGrid from '../../EmbeddedTagGrid.vue';

import * as utils from '@services/utils';
import { API } from '@services/API';
import { sortScope, groupScope, scopeKeyFor } from '@services/list-scope';
import type { GroupSetting } from '@services/sort-group-defs';

import type { IEmbeddedTagFolder } from '@typings';

export default defineComponent({
  name: 'EmbeddedTagDynamicTopMatter',

  data: () => ({
    tags: [] as IEmbeddedTagFolder[],
    currentPath: '<none>',
  }),

  components: {
    EmbeddedTagGrid,
  },

  mixins: [UserConfig],

  created() {
    sortScope.init();
    groupScope.init();
    utils.bus.on('memories:embedded-tags:changed', this.refresh);
    utils.bus.on('memories:user-config-changed', this.sortTags);
  },

  beforeDestroy() {
    utils.bus.off('memories:embedded-tags:changed', this.refresh);
    utils.bus.off('memories:user-config-changed', this.sortTags);
  },

  computed: {
    show() {
      return this.tags.length > 0 && !this.$route.query.recursive;
    },

    path(): string {
      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/');
      }
      return path.filter(Boolean).join('/');
    },

    /** Same tag-path identity used by view-mode-scope's item overrides, reused here for the EmbeddedTagsTiles target. */
    scopeKey(): string | null {
      return scopeKeyFor('EmbeddedTags', this.$route.params, this.$route.query, this.config.folders_path);
    },

    groupSetting(): GroupSetting {
      return groupScope.resolve('EmbeddedTagsTiles', this.scopeKey);
    },
  },

  methods: {
    async refresh(): Promise<boolean> {
      const path = this.path;

      // Clear tags if switching to a different path, otherwise just refresh
      if (this.currentPath !== path) {
        this.currentPath = path;
        this.tags = [];
      }

      // Get child tags URL
      const url = API.Q(API.EMBEDDED_TAGS_SUB(), { path });

      // Make API call to get child tags
      try {
        this.tags = (await axios.get<IEmbeddedTagFolder[]>(url)).data;
      } catch (e) {
        console.error(e);
        return false;
      }

      this.sortTags();

      // Must match `show`: when in recursive/timeline-view mode the grid
      // isn't rendered at all, so it shouldn't count as "has content" either
      // -- otherwise Timeline's empty-state check (list.length && !dtmContent)
      // stays falsely non-empty and neither the grid nor EmptyContent render.
      return !!this.show;
    },

    sortTags() {
      const sort = sortScope.resolve('EmbeddedTagsTiles', this.scopeKey);
      if (sort.key === 'count') {
        this.tags.sort((a, b) => a.count - b.count);
      } else {
        this.tags.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));
      }

      if (sort.dir === 'desc') {
        this.tags.reverse();
      }
    },

    onDeleted(path: string) {
      this.tags = this.tags.filter((tag) => tag.path !== path);
    },
  },
});
</script>
