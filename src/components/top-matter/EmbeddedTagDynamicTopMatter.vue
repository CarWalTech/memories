<template>
  <EmbeddedTagGrid v-if="show" :items="tags" @deleted="onDeleted" />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import EmbeddedTagGrid from './EmbeddedTagGrid.vue';

import { API } from '@services/API';

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
  },

  methods: {
    async refresh(): Promise<boolean> {
      const path = this.path;

      // Clear tags if switching to a different path, otherwise just refresh
      if (this.currentPath === path) {
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

      return this.tags.length > 0;
    },

    sortTags() {
      this.tags.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));
    },

    onDeleted(path: string) {
      this.tags = this.tags.filter((tag) => tag.path !== path);
    },
  },
});
</script>
