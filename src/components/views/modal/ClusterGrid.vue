<template>
  <RecycleScroller
    ref="recycler"
    tabindex="1"
    type-field="type"
    key-field="id"
    size-field="size"
    class="grid-recycler hide-scrollbar-mobile"
    :class="classList"
    :items="rows"
    :skipHover="true"
    :buffer="400"
    @resize="resize"
  >
    <template #before>
      <slot name="before"></slot>
    </template>

    <template v-slot="{ item }">
      <div v-if="item.type === 'header'" class="grid-group-header" :style="{ height: `${item.size}px` }">
        {{ item.label }}
      </div>

      <div v-else class="grid-row" :style="{ height: `${item.size}px` }">
        <div v-for="cl in item.clusters" :key="cl.cluster_id" class="grid-item" :style="{ width: `${width}px` }">
          <Cluster :data="cl" :link="link" :counters="counters" :image-display-shape="effectiveShape" @click="click(cl)" />
        </div>
      </div>
    </template>
  </RecycleScroller>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import Cluster from '@components/frame/Cluster.vue';
import ViewPrefs from '@mixins/ViewPrefs';

import type { ICluster } from '@typings';
import * as utils from '@services/utils';
import { listRowHeight } from '@services/zoom';
import { groupByName } from '@services/cluster-group';
import type { GroupSetting } from '@services/sort-group-defs';

/** Fixed height for a "Group by: Name" section header row. */
const GROUP_HEADER_HEIGHT = 40;

interface HeaderRow {
  type: 'header';
  id: string;
  label: string;
  size: number;
}

interface ClusterRow {
  type: 'row';
  id: string;
  clusters: ICluster[];
  size: number;
}

type GridRow = HeaderRow | ClusterRow;

export default defineComponent({
  name: 'ClusterGrid',

  components: {
    Cluster,
  },

  mixins: [ViewPrefs],

  props: {
    items: {
      type: Array<ICluster>,
      required: true,
    },
    maxSize: {
      type: Number,
      default: 180,
    },
    minCols: {
      type: Number,
      default: 3,
    },
    link: {
      type: Boolean,
      default: true,
    },
    plus: {
      type: Boolean,
      default: false,
    },
    focus: {
      type: Boolean,
      default: false,
    },
    /** "Group by" setting to bucket `items` under section headers; 'none' renders a flat grid (default). */
    groupSetting: {
      type: Object as PropType<GroupSetting>,
      default: (): GroupSetting => ({ key: 'none', dir: 'asc' }),
    },
  },

  emits: {
    click: (item: ICluster) => true,
    plus: () => true,
  },

  data: () => ({
    recyclerWidth: 300,
  }),

  mounted() {
    this.resize();
  },

  watch: {
    async items() {
      if (this.focus) {
        await this.$nextTick();
        this.refs.recycler?.$el.focus();
      }
    },
  },

  computed: {
    refs() {
      return this.$refs as {
        recycler: VueRecyclerType;
      };
    },

    /** Whether to render a single-column list instead of a grid of cards */
    isListView(): boolean {
      return this.effectiveShape === 'list';
    },

    /** Height of the cluster */
    height() {
      if (this.isListView) {
        return listRowHeight(this.config.grid_zoom);
      }

      if (this.routeIsAlbums) {
        // album view: add gap for text below album
        // 4px extra on mobile for mark#2147915
        return this.width + (utils.isMobile() ? 46 : 42);
      }

      return this.width;
    },

    /** Width of the cluster */
    width() {
      if (this.isListView) {
        return this.recyclerWidth;
      }

      return utils.round(this.recyclerWidth / this.gridItems, 2);
    },

    /** Number of items horizontally */
    gridItems() {
      if (this.isListView) {
        return 1;
      }

      // Restrict the number of columns between minCols and the size cap
      return Math.max(Math.floor(this.recyclerWidth / this.maxSize), this.minCols);
    },

    /** Classes list on object */
    classList() {
      return {
        empty: !this.items.length,
        'cluster--album': this.routeIsAlbums,
        'cluster--list': this.isListView,
      };
    },

    /** Whether the clusters should show counters */
    counters() {
      return !this.routeIsAlbums;
    },

    /** List of clusters to display */
    clusters() {
      const items = [...this.items];

      // Add plus button if required
      if (this.plus) {
        items.unshift({
          cluster_type: 'plus',
          cluster_id: -1,
          name: '',
          count: 0,
        });
      }

      return items;
    },

    /** Flat list of virtual-scroll rows: optional group headers + chunks of `clusters` at `gridItems` per row. */
    rows(): GridRow[] {
      const groups = groupByName(this.clusters, this.groupSetting);
      const rows: GridRow[] = [];

      for (const group of groups) {
        if (group.label != null) {
          rows.push({ type: 'header', id: `header-${group.label}`, label: group.label, size: GROUP_HEADER_HEIGHT });
        }

        for (let i = 0; i < group.items.length; i += this.gridItems) {
          rows.push({
            type: 'row',
            id: `row-${group.label ?? ''}-${i}`,
            clusters: group.items.slice(i, i + this.gridItems),
            size: this.height,
          });
        }
      }

      return rows;
    },
  },

  methods: {
    click(item: ICluster) {
      switch (item.cluster_type) {
        case 'plus':
          this.$emit('plus');
          break;
        default:
          this.$emit('click', item);
      }
    },

    resize() {
      this.recyclerWidth = this.refs.recycler?.$el.clientWidth;
    },
  },
});
</script>

<style lang="scss" scoped>
.grid-recycler {
  will-change: scroll-position;
  flex: 1;
  max-height: 100%;
  overflow-y: scroll !important;

  &.empty {
    visibility: hidden;
  }

  &:focus {
    outline: none;
  }

  margin: 1px;
  @media (max-width: 768px) {
    &.cluster--album {
      margin: 6px; // mark#2147915
    }
  }

  .grid-row {
    display: flex;
  }

  .grid-item {
    position: relative;
  }

  .grid-group-header {
    display: flex;
    align-items: center;
    padding-left: 3px;
    font-size: 1.075em;
    font-weight: 500;
  }
}
</style>
