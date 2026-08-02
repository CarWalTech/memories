<template>
  <router-link
    draggable="false"
    class="folder fill-block"
    :class="{
      hasPreview: previews.length > 0,
      onePreview: previews.length === 1,
      empty: data.count === 0,
      'list-view': isListView,
    }"
    :to="target"
  >
    <!-- Compact list row layout -->
    <template v-if="isListView">
      <div class="list-thumb">
        <TagIcon v-if="!previews.length" class="icon" />
        <div class="img-outer" v-else>
          <XImg class="ximg fill-block" :src="previewUrl(previews[0])" />
        </div>
      </div>

      <div class="list-info">
        <span class="list-filename">{{ data.name }}</span>
      </div>

      <NcCounterBubble class="list-count">{{ data.count }}</NcCounterBubble>

      <div class="list-delete" @click.stop.prevent="onDelete">
        <TagRemoveIcon :size="18" />
      </div>
    </template>

    <!-- Regular grid tile layout -->
    <template v-else>
      <div class="delete" @click.stop.prevent="onDelete">
        <TagRemoveIcon :size="18" />
      </div>

      <div class="count-bubble">
        <NcCounterBubble>{{ data.count }}</NcCounterBubble>
      </div>

      <div class="big-icon top-left fill-block">
        <TagIcon class="icon" />
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

import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js';

import UserConfig from '@mixins/UserConfig';

import * as dav from '@services/dav';
import * as utils from '@services/utils/helpers';

import type { IEmbeddedTagFolder, IPhoto } from '@typings';
import type { ImageDisplayShape } from '@services/view-mode-scope';

import TagIcon from 'vue-material-design-icons/Tag.vue';
import TagRemoveIcon from 'vue-material-design-icons/TagRemove.vue';

export default defineComponent({
  name: 'EmbeddedTagFrame',
  components: {
    TagIcon,
    TagRemoveIcon,
    NcCounterBubble,
  },

  mixins: [UserConfig],

  props: {
    data: {
      type: Object as PropType<IEmbeddedTagFolder>,
      required: true,
    },
    imageDisplayShape: {
      type: String as PropType<ImageDisplayShape>,
      required: true,
    },
  },

  emits: {
    deleted: (path: string) => true,
  },

  computed: {
    /** Whether to render the compact list-row layout instead of a tile */
    isListView(): boolean {
      return this.imageDisplayShape === 'list';
    },

    /** Open this tag path */
    target() {
      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/').filter(Boolean);
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
  },

  methods: {
    /** Get preview url */
    previewUrl(info: IPhoto) {
      return utils.getPreviewUrl({
        photo: info,
        sqsize: 256,
      });
    },

    async onDelete(): Promise<void> {
      const deleted = await dav.deleteEmbeddedTagWithConfirm(this.data.path, this.data.name);
      if (deleted) {
        this.$emit('deleted', this.data.path);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.folder {
  cursor: pointer;
  --icon-dist: 8px;

  @media (max-width: 768px) {
    --icon-dist: 4px;
  }

  // A tag with no matching photos left (e.g. its last photo was retagged
  // away) -- keep it visible for manual cleanup, but make the emptiness
  // obvious instead of looking identical to a freshly-loaded populated tile.
  &.empty {
    opacity: 0.5;
  }
}

.count-bubble {
  position: absolute;
  top: calc(var(--icon-dist, 8px) + 2px);
  left: calc(var(--icon-dist, 8px) + 2px);
  z-index: 200;
  pointer-events: none;

  :deep .counter-bubble__counter {
    color: unset !important;
  }
}

.delete {
  position: absolute;
  top: calc(var(--icon-dist, 8px) + 2px);
  right: calc(var(--icon-dist, 8px) + 2px);
  z-index: 200;
  border-radius: 50%;
  cursor: pointer;
  display: none;
  opacity: 0.7;
  padding: 3px;
  filter: invert(1) brightness(100);

  &:hover {
    opacity: 1;
    background-color: rgba(0, 0, 0, 0.3);
  }

  @media (hover: hover) and (pointer: fine) {
    .folder:hover & {
      display: flex;
    }
  }

  @media (max-width: 768px) {
    display: flex;
  }
}

.big-icon {
  cursor: pointer;
  z-index: 100;
  transition: opacity 0.2s ease-in-out;

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
    :deep .material-design-icon {
      opacity: 1;
      filter: invert(1) brightness(100);
    }
    .name {
      color: white;
    }
  }

  // Show it on hover if not a preview
  .folder:hover > & > .icon {
    opacity: 0.8;
  }
  .folder.hasPreview:hover > & {
    opacity: 0.9;
  }

  > .icon {
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
    &:hover {
      background-color: var(--color-background-hover);
    }
  }
}

.list-thumb {
  position: relative;
  flex: 0 0 auto;
  width: auto;
  height: calc(100% - 12px);
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

.list-count {
  flex: 0 0 auto;

  :deep .counter-bubble__counter {
    color: unset !important;
  }
}

.list-delete {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  cursor: pointer;
  opacity: 0.7;
  padding: 3px;

  &:hover {
    opacity: 1;
    background-color: var(--color-background-hover);
  }
}
</style>
