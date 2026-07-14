<template>
  <router-link
    draggable="false"
    class="folder fill-block"
    :class="{
      hasPreview: previews.length > 0,
      onePreview: previews.length === 1,
      selected: data.flag & c.FLAG_SELECTED,
      'list-view': isListView,
    }"
    :to="target"
    :event="navigationEvent"
    @click.native="onClick"
    @touchstart.native.passive="onTouchStart"
    @touchmove.native.passive="onTouchMove"
    @touchend.native="onTouchEnd"
    @touchcancel.native="onTouchEnd"
  >
    <!-- Compact list row layout -->
    <template v-if="isListView">
      <div class="list-select" v-if="!routeIsPublic && selectionManager" @click.stop.prevent="onToggleSelect">
        <CheckCircleIcon :size="18" />
      </div>

      <div class="list-thumb">
        <FolderIcon v-if="!previews.length" class="icon" />
        <div class="img-outer" v-else>
          <XImg class="ximg fill-block" :src="previewUrl(previews[0])" />
        </div>
      </div>

      <div class="list-info">
        <span class="list-filename">{{ data.name }}</span>
      </div>
    </template>

    <!-- Regular grid tile layout -->
    <template v-else>
      <div class="select" v-if="!routeIsPublic && selectionManager" @click.stop.prevent="onToggleSelect">
        <CheckCircleIcon :size="18" />
      </div>

      <div class="big-icon top-left fill-block">
        <FolderIcon class="icon" />
        <div class="name">{{ data.name }}</div>
      </div>

      <div class="previews fill-block">
        <div class="preview-container fill-block">
          <div class="img-outer" v-for="info of previews" :key="info.fileid">
            <XImg class="ximg fill-block" :src="previewUrl(info)" />
          </div>
        </div>
      </div>
    </template>
  </router-link>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import UserConfig from '@mixins/UserConfig';
import type SelectionManager from '@components/SelectionManager.vue';

import * as utils from '@services/utils/helpers';

import type { IFolder, IPhoto } from '@typings';

import FolderIcon from 'vue-material-design-icons/Folder.vue';
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue';

export default defineComponent({
  name: 'Folder',
  components: {
    FolderIcon,
    CheckCircleIcon,
  },

  mixins: [UserConfig],

  props: {
    data: {
      type: Object as PropType<IFolder>,
      required: true,
    },
    selectionManager: {
      type: Object as PropType<InstanceType<typeof SelectionManager>>,
      required: false,
    },
  },

  computed: {
    /** Whether to render the compact list-row layout instead of a tile */
    isListView(): boolean {
      return !!this.config.list_view;
    },

    /** Open folder */
    target() {
      // Tiles that carry their own full route path (root picker, favorite
      // folders) always open in the Folders view, regardless of which
      // route the tile is currently rendered on. The `abs` query flag tells
      // the Folders view this is a full account-relative path, not one
      // relative to a configured single Folders root -- see
      // getFolderRoutePath() -- otherwise a single non-default root (e.g.
      // folders_path=/Immich) would get prepended a second time.
      if (this.data.path) {
        return {
          name: _m.routes.Folders.name,
          params: { path: this.data.path },
          query: { abs: '1' },
          hash: undefined,
        };
      }

      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/');
      }

      path = [...path, this.data.name]; // intentional copy
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    previews(): IPhoto[] {
      const previews = this.data.previews;
      if (!previews?.length) {
        return [];
      }

      if (previews.length > 0 && previews.length < 4) {
        return [previews[0]];
      } else {
        return previews.slice(0, 4);
      }
    },

    /**
     * router-link's own click handler races against our @click.native
     * handler -- depending on DOM listener registration order, its
     * navigation can fire before our preventDefault() has a chance to
     * stop it. Disabling its click-triggered navigation entirely while a
     * selection is active sidesteps that race instead of fighting it.
     *
     * Note: reads selectionManager.size (a plain reactive data property),
     * not selectionManager.empty() -- that reads Selection.size on the
     * underlying Map, which Vue 2 can't track reactively, so a computed
     * depending on it would never re-evaluate after the first render.
     */
    navigationEvent(): string | string[] {
      return this.selectionManager && this.selectionManager.size > 0 ? [] : 'click';
    },
  },

  methods: {
    /** Get preview url */
    previewUrl(info: IPhoto) {
      return utils.getPreviewUrl({
        photo: info,
        sqsize: 256,
      });
    },

    /** Toggle selection instead of navigating when a selection is active */
    onClick(e: Event) {
      this.selectionManager?.clickFolder(this.data, e);
    },

    onToggleSelect() {
      this.selectionManager?.toggleFolder(this.data);
    },

    /** Touch hold to select, mirroring Photo.vue's long-press gesture */
    onTouchStart(e: TouchEvent) {
      this.selectionManager?.touchstartFolder(this.data, e);
    },

    onTouchMove(e: TouchEvent) {
      this.selectionManager?.touchmoveFolder(e);
    },

    onTouchEnd(e: TouchEvent) {
      this.selectionManager?.touchendFolder(e);
    },
  },
});
</script>

<style lang="scss" scoped>
.folder {
  cursor: pointer;
  --icon-dist: 8px;

  transition: background-color 0.15s ease;

  @media (max-width: 768px) {
    --icon-dist: 4px;
  }

  // Revealed behind .big-icon / .previews as they shrink inward on selection
  &.selected {
    background-color: var(--color-primary-select-light);
  }
}

// Distance of icon from border, same "zoom out" treatment as Photo.vue
$icon-half-size: 6px;

// Selection icon, same treatment as Photo.vue's .select:
// a single check-circle icon that is recolored via CSS instead
// of swapping icon components for selected vs unselected state.
.select {
  position: absolute;
  top: calc(var(--icon-dist) + 2px);
  right: calc(var(--icon-dist) + 2px);
  z-index: 200;
  border-radius: 50%;
  cursor: pointer;
  display: none;
  opacity: 0.7;
  filter: invert(1) brightness(100);

  @mixin visible {
    display: flex;
    opacity: 1;
  }

  @media (hover: hover) and (pointer: fine) {
    .folder:hover > & {
      @include visible;
    }
  }

  .folder.selected > & {
    @include visible;
    filter: invert(0);
    background-color: white;
    color: var(--color-primary);
  }

  :deep path {
    // fill up the space, same fix as Photo.vue's check-circle-icon
    transform: scale(1.2) translate(-2px, -2px);
  }
}

.big-icon {
  cursor: pointer;
  z-index: 100;
  box-sizing: border-box;
  transition:
    opacity 0.2s ease-in-out,
    padding 0.15s ease;

  // Shrink inward on selection, revealing .folder's background color
  .folder.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  :deep .material-design-icon__svg {
    width: 50%;
    height: 50%;
  }

  > .name {
    cursor: pointer;
    width: 100%;
    padding: 0 5%;
    text-align: center;
    font-size: 1.08em;
    word-wrap: break-word;
    text-overflow: ellipsis;
    max-height: 35%;
    line-height: 1em;
    position: absolute;
    top: 65%;

    @media (max-width: 768px) {
      font-size: 0.95em;
    }
  }

  // Make it white if there is a preview
  .folder.hasPreview > & {
    .folder-icon {
      opacity: 1;
      filter: invert(1) brightness(100);
    }
    .name {
      color: white;
    }
  }

  // Show it on hover if not a preview
  .folder:hover > & > .folder-icon {
    opacity: 0.8;
  }
  .folder.hasPreview:hover > & {
    opacity: 0.9;
  }

  > .folder-icon {
    cursor: pointer;
    height: 90%;
    width: 100%;
    opacity: 0.3;
  }
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  box-sizing: border-box;
  transition: padding 0.15s ease;

  // Shrink inward on selection, revealing .folder's background color
  .folder.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  .preview-container {
    border-radius: 10px;
    overflow: hidden;
  }

  .img-outer {
    background-color: var(--color-background-dark);
    padding: 0;
    margin: 0;
    width: 50%;
    height: 50%;
    display: inline-block;

    .folder.onePreview > & {
      width: 100%;
      height: 100%;
    }

    > img {
      object-fit: cover;
      padding: 0;
      filter: brightness(50%);
      opacity: 1;
      background-color: var(--color-background-dark);
      transition: filter opacity 0.2s ease-in-out;

      &.error {
        display: none;
      }
      .folder:hover & {
        filter: brightness(50%);
        opacity: 0.5;
      }
    }
  }
}

// List view row layout
.folder.list-view {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 8px;
  border-radius: var(--border-radius, 8px);

  @media (hover: hover) and (pointer: fine) {
    &:not(.selected):hover {
      background-color: var(--color-background-hover);
    }
  }

  &.selected {
    background-color: var(--color-primary-select-light);
  }
}

.list-select {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  opacity: 0.7;

  .folder.selected & {
    opacity: 1;
    color: var(--color-primary);
  }
}

.list-thumb {
  position: relative;
  flex: 0 0 auto;
  width: auto;
  height: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border-radius: 4px;
  background-color: var(--color-background-dark);
  margin: 6px 0;
  display: flex;
  align-items: center;
  justify-content: center;

  .icon {
    width: 60%;
    height: 60%;
    opacity: 0.3;
  }

  .img-outer {
    width: 100%;
    height: 100%;
  }

  .ximg {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.list-info {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;

  .list-filename {
    font-size: 0.95em;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}
</style>
