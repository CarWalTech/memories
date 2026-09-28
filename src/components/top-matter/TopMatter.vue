<template>
  <div
    ref="container"
    class="top-matter-container timeline-scroller-gap"
    :class="{
      'dynamic-visible': dynamicVisible,
    }"
    v-if="currentmatter"
  >
    <component :is="currentmatter" />
    <NcLoadingIcon
      v-if="refreshing"
      :size="20"
      class="top-matter-refresh-icon"
      :style="{ right: `${refreshIconRight}px`, top: `${refreshIconTop}px` }"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';

import FolderTopMatter from './sections/FolderTopMatter.vue';
import FaceTopMatter from './sections/FaceTopMatter.vue';
import AlbumTopMatter from './sections/AlbumTopMatter.vue';
import PlacesTopMatter from './sections/PlacesTopMatter.vue';
import TagsTopMatter from './sections/TagsTopMatter.vue';
import IndexTopMatter from './IndexTopMatter.vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'TopMatter',

  components: {
    NcLoadingIcon,
    FolderTopMatter,
    FaceTopMatter,
    AlbumTopMatter,
    TagsTopMatter,
    IndexTopMatter,
  },

  props: {
    /** Whether the page's content is currently (re)loading */
    refreshing: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    dynamicVisible: true,

    /** Distance in px from the right edge of the container to the left edge of .right-actions */
    refreshIconRight: 0,
    /** Distance in px from the top of the container to the vertical center of .right-actions */
    refreshIconTop: 0,
    observedContainer: null as HTMLElement | null,
    observedRightActions: null as HTMLElement | null,
    resizeObserver: null as ResizeObserver | null,
  }),

  mounted() {
    utils.bus.on('memories.recycler.scroll', this.onRecyclerScroll);

    this.resizeObserver = new ResizeObserver(this.updateRefreshIconPosition);
    this.syncObservers();
  },

  beforeUnmount() {
    utils.bus.off('memories.recycler.scroll', this.onRecyclerScroll);
    this.resizeObserver?.disconnect();
  },

  updated() {
    // The container is the component's own v-if root, so it can appear or
    // disappear (e.g. FolderShare before shareType is known); re-sync on
    // every update rather than only once in mounted().
    this.syncObservers();
  },

  computed: {
    refs() {
      return this.$refs as {
        container?: HTMLElement;
      };
    },

    currentmatter() {
      switch (this.$route.name) {
        case _m.routes.Folders.name:
          return FolderTopMatter;
        case _m.routes.FolderShare.name:
          return this.initstate.shareType === 'folder' ? FolderTopMatter : null;
        case _m.routes.Albums.name:
          return AlbumTopMatter;
        case _m.routes.Places.name:
          return PlacesTopMatter;
        case _m.routes.Tags.name:
        case _m.routes.EmbeddedTags.name:
          return TagsTopMatter;
        case _m.routes.Recognize.name:
        case _m.routes.FaceRecognition.name:
          return FaceTopMatter;
        case _m.routes.Base.name:
        case _m.routes.AllPhotos.name:
        case _m.routes.Favorites.name:
        case _m.routes.Archive.name:
        case _m.routes.ThisDay.name:
        case _m.routes.Map.name:
          return IndexTopMatter;
        default:
          return null;
      }
    },
  },

  methods: {
    onRecyclerScroll({ dynTopMatterVisible }: utils.BusEvent['memories.recycler.scroll']) {
      this.dynamicVisible = dynTopMatterVisible;
    },

    /** (Re-)attach the resize observer to the container root, which can appear/disappear via v-if. */
    syncObservers() {
      const container = this.refs.container ?? null;
      if (container !== this.observedContainer) {
        if (this.observedContainer) this.resizeObserver?.unobserve(this.observedContainer);
        if (container) this.resizeObserver?.observe(container);
        this.observedContainer = container;
      }

      this.observeRightActions();
    },

    /**
     * (Re-)find the current page's .right-actions element and track its size,
     * so the refresh icon can be pinned to its left edge. The element is
     * swapped out whenever the route-specific top matter component changes
     * (and can also change size once e.g. lazy-loaded breadcrumbs render).
     */
    observeRightActions() {
      const el = this.refs.container?.querySelector<HTMLElement>('.right-actions') ?? null;
      if (el === this.observedRightActions) return;

      if (this.observedRightActions) this.resizeObserver?.unobserve(this.observedRightActions);
      this.observedRightActions = el;
      if (el) this.resizeObserver?.observe(el);

      this.updateRefreshIconPosition();
    },

    /**
     * Position relative to .right-actions itself rather than centering in the
     * whole container: some pages (e.g. Folders, EmbeddedTags) render an
     * extra breadcrumbs row below the action row, which makes the container
     * taller than the action row alone and throws off simple 50% centering.
     */
    updateRefreshIconPosition() {
      const container = this.refs.container;
      const rightActions = this.observedRightActions;
      if (!container || !rightActions) {
        this.refreshIconRight = 0;
        this.refreshIconTop = 0;
        return;
      }

      const containerRect = container.getBoundingClientRect();
      const rightActionsRect = rightActions.getBoundingClientRect();
      this.refreshIconRight = containerRect.right - rightActionsRect.left;
      this.refreshIconTop = rightActionsRect.top + rightActionsRect.height / 2 - containerRect.top;
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter-container {
  position: relative;
  z-index: 200; // above scroller, below top-bar
  padding: 2px 0;
  background-color: var(--color-main-background);
  transition: box-shadow 0.2s ease-in-out;

  &:not(.dynamic-visible) {
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);
  }

  // Hide shadow if inside cluster view
  .cluster-view & {
    box-shadow: none;
  }

  @media (max-width: 768px) {
    padding-left: 10px; // extra space visual
  }

  > div {
    display: flex;
    vertical-align: middle;
  }

  // Positioned by JS (see refreshIconRight/refreshIconTop) to sit at the
  // left edge, vertically centered on .right-actions, since that element
  // belongs to whichever route-specific top matter component is currently
  // rendered.
  .top-matter-refresh-icon {
    position: absolute;
    transform: translateY(-50%);
    margin-right: 8px;
    z-index: 51; // above .right-actions
  }

  :deep .name {
    overflow: hidden;
    text-overflow: ellipsis;
    padding-left: 10px;
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    white-space: nowrap;
    vertical-align: top;
    flex-grow: 1;
  }

  :deep button + .name {
    padding-left: 0;
  }

  :deep .right-actions {
    margin-right: 12px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }

    /**
     * Hide the actions when the selection manager is open.
     * Having two action bars is confusing.
     */
    .memories-timeline:has(.memories-top-bar) & {
      visibility: hidden;
    }

    span {
      cursor: pointer;
    }
  }

  :deep button {
    display: inline-block;
  }
}
</style>
