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
        <div class="item fill-block" v-for="item of group.items" :key="item.path">
          <EmbeddedTagFrame :data="item" :image-display-shape="effectiveShape" @deleted="$emit('deleted', $event)" />
        </div>
      </div>
    </template>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import ViewPrefs from '@mixins/ViewPrefs';
import EmbeddedTagFrame from '@components/frame/EmbeddedTagFrame.vue';

import { zoomScale, listRowHeight as listRowHeightForZoom } from '@services/zoom';
import { groupByName } from '@services/cluster-group';
import type { GroupSetting } from '@services/sort-group-defs';

import type { IEmbeddedTagFolder } from '@typings';

/** Base tile size (px) at the default (100%) zoom level */
const BASE_ITEM_SIZE = 210;

export default defineComponent({
  name: 'EmbeddedTagGrid',

  components: {
    EmbeddedTagFrame,
  },

  mixins: [ViewPrefs],

  props: {
    items: {
      type: Array<IEmbeddedTagFolder>,
      required: true,
    },
    /** "Group by" setting to bucket `items` under section headers; 'none' renders a single flat grid (default). */
    groupSetting: {
      type: Object as PropType<GroupSetting>,
      default: (): GroupSetting => ({ key: 'none', dir: 'asc' }),
    },
  },

  emits: {
    deleted: (path: string) => true,
  },

  computed: {
    isListView(): boolean {
      return this.effectiveShape === 'list';
    },

    minItemSize(): number {
      return Math.round(BASE_ITEM_SIZE * zoomScale(this.config.grid_zoom));
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
    // Keep the base rule's scrollbar-reserving width (desktop -40px / mobile
    // -2px) instead of going full-width -- otherwise list rows run under the
    // scrollbar that the grid/square/dynamic tile layouts already avoid.

    > .item {
      aspect-ratio: unset;
      height: var(--list-row-height);
    }
  }
}
</style>
