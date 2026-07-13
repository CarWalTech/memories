<template>
  <div class="tags-top-matter">
    <CollabTagsTopMatter v-if="routeIsTags" />
    <EmbeddedTagTopMatter v-else />

    <TagsTabBar v-if="config.systemtags_enabled" />

    <EmbeddedTagBreadcrumbs v-if="routeIsEmbeddedTags" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import CollabTagsTopMatter from './CollabTagsTopMatter.vue';
import EmbeddedTagTopMatter from './EmbeddedTagTopMatter.vue';
import EmbeddedTagBreadcrumbs from './EmbeddedTagBreadcrumbs.vue';
import TagsTabBar from './TagsTabBar.vue';

export default defineComponent({
  name: 'TagsTopMatter',

  mixins: [UserConfig],

  components: {
    CollabTagsTopMatter,
    EmbeddedTagTopMatter,
    EmbeddedTagBreadcrumbs,
    TagsTabBar,
  },
});
</script>

<style lang="scss" scoped>
.tags-top-matter {
  display: flex;
  flex-direction: column;
}
</style>
