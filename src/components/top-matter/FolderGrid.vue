<template>
  <div class="grid">
    <div class="item fill-block" v-for="item of items" :key="item.fileid">
      <Folder
        :data="item"
        :selected="selected.has(item.fileid)"
        :selection-mode="selected.size > 0"
        @toggle-select="$emit('toggle-select', item)"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import UserConfig from '@mixins/UserConfig';
import Folder from '@components/frame/Folder.vue';

import type { IFolder } from '@typings';

export default defineComponent({
  name: 'ClusterGrid',

  components: {
    Folder,
  },

  mixins: [UserConfig],

  props: {
    items: {
      type: Array<IFolder>,
      required: true,
    },
    selected: {
      type: Object as PropType<Set<number>>,
      default: () => new Set<number>(),
    },
  },

  emits: ['toggle-select'],
});
</script>

<style lang="scss" scoped>
.grid {
  display: grid;
  padding: 0 5px;
  grid-gap: 10px;
  grid-template-columns: repeat(auto-fill, minmax(calc(min(50%, 210px) - 5px), 1fr));

  width: calc(100% - 40px); // leave space for scroller
  @media (max-width: 768px) {
    width: calc(100% - 2px); // compensation for negative margin
    grid-gap: 5px;
  }

  > .item {
    aspect-ratio: 1 / 1;
    position: relative;
  }
}
</style>
