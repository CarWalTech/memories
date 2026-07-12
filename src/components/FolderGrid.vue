<template>
  <div class="grid" :style="{ '--min-item-size': `${minItemSize}px` }">
    <div class="item fill-block" v-for="item of items" :key="item.fileid">
      <Folder :data="item" :selection-manager="selectionManager" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import UserConfig from '@mixins/UserConfig';
import Folder from '@components/frame/Folder.vue';
import type SelectionManager from '@components/SelectionManager.vue';

import { zoomScale } from '@services/zoom';

import type { IFolder } from '@typings';

/** Base tile size (px) at the default (100%) zoom level */
const BASE_ITEM_SIZE = 210;

export default defineComponent({
  name: 'FolderGrid',

  components: {
    Folder,
  },

  mixins: [UserConfig],

  props: {
    items: {
      type: Array<IFolder>,
      required: true,
    },
    selectionManager: {
      type: Object as PropType<InstanceType<typeof SelectionManager>>,
      required: false,
    },
  },

  computed: {
    minItemSize(): number {
      return Math.round(BASE_ITEM_SIZE * zoomScale(this.config.grid_zoom));
    },
  },
});
</script>

<style lang="scss" scoped>
.grid {
  display: grid;
  padding: 0 5px;
  grid-gap: 10px;
  grid-template-columns: repeat(auto-fill, minmax(calc(min(50%, var(--min-item-size)) - 5px), 1fr));

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
