<template>
  <div class="grid-groups">
    <template v-for="group in groups">
      <div v-if="group.label != null" :key="`header-${group.label}`" class="group-header">{{ group.label }}</div>

      <div
        :key="`grid-${group.label ?? ''}`"
        class="grid"
        :class="{ 'list-view': isListView }"
        :style="{ '--min-item-size': `${minItemSize}px`, '--list-row-height': `${listRowHeight}px` }"
      >
        <div class="item fill-block" v-for="item of group.items" :key="item.fileid">
          <Folder :data="item" :selection-manager="selectionManager" :image-display-shape="effectiveShape" />
        </div>
      </div>
    </template>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import ViewPrefs from '@mixins/ViewPrefs';
import Folder from '@components/frame/Folder.vue';
import type SelectionManager from '@components/SelectionManager.vue';

import { rowHeight, listRowHeight as listRowHeightForZoom } from '@services/zoom';
import * as utils from '@services/utils';
import { groupByName } from '@services/cluster-group';
import type { GroupSetting } from '@services/sort-group-defs';

import type { IFolder } from '@typings';

export default defineComponent({
  name: 'FolderGrid',

  components: {
    Folder,
  },

  mixins: [ViewPrefs],

  props: {
    items: {
      type: Array<IFolder>,
      required: true,
    },
    selectionManager: {
      type: Object as PropType<InstanceType<typeof SelectionManager>>,
      required: false,
    },
    /** "Group by" setting to bucket `items` under section headers; 'none' renders a single flat grid (default). */
    groupSetting: {
      type: Object as PropType<GroupSetting>,
      default: (): GroupSetting => ({ key: 'none', dir: 'asc' }),
    },
  },

  computed: {
    isListView(): boolean {
      return this.effectiveShape === 'list';
    },

    /** Match the folder tile size to the photo timeline's tile size at the current zoom level */
    minItemSize(): number {
      return rowHeight(this.config.grid_zoom, utils.isMobile());
    },

    listRowHeight(): number {
      return listRowHeightForZoom(this.config.grid_zoom);
    },

    groups() {
      return groupByName(this.items, this.groupSetting);
    },
  },
});
</script>

<style lang="scss" scoped>
.group-header {
  padding: 10px 8px 4px;
  font-size: 1.075em;
  font-weight: 500;
}

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

  &.list-view {
    display: flex;
    flex-direction: column;
    grid-gap: 0;
    padding: 0;
    width: 100%;

    > .item {
      aspect-ratio: unset;
      height: var(--list-row-height);
    }
  }
}
</style>
