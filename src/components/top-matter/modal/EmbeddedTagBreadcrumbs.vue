<template>
  <NcBreadcrumbs :key="$route.path">
    <NcBreadcrumb :name="t('memories', 'Embedded Tags')" :to="getRoute([])">
      <template #icon>
        <TagsIcon :size="20" />
      </template>
    </NcBreadcrumb>
    <NcBreadcrumb v-for="tag in list" :key="tag.idx" :name="tag.text" :to="getRoute(tag.path)" />
  </NcBreadcrumbs>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');

import TagsIcon from 'vue-material-design-icons/Tag.vue';

export default defineComponent({
  name: 'EmbeddedTagBreadcrumbs',

  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    TagsIcon,
  },

  computed: {
    pathParts(): string[] {
      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/');
      }
      return path.filter(Boolean);
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
  },

  methods: {
    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },
  },
});
</script>
