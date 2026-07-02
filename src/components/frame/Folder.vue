<template>
  <router-link
    draggable="false"
    class="folder fill-block"
    :class="{
      hasPreview: previews.length > 0,
      onePreview: previews.length === 1,
      selected: selected,
    }"
    :to="target"
    @click.native="onClick"
  >
    <div class="select-checkbox top-left" :class="{ visible: selectionMode }" @click.stop.prevent="onToggleSelect">
      <CheckboxMarkedCircleIcon v-if="selected" class="icon checked" />
      <CheckboxBlankCircleOutlineIcon v-else class="icon" />
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
  </router-link>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import UserConfig from '@mixins/UserConfig';

import * as utils from '@services/utils/helpers';

import type { IFolder, IPhoto } from '@typings';

import FolderIcon from 'vue-material-design-icons/Folder.vue';
import CheckboxMarkedCircleIcon from 'vue-material-design-icons/CheckboxMarkedCircle.vue';
import CheckboxBlankCircleOutlineIcon from 'vue-material-design-icons/CheckboxBlankCircleOutline.vue';

export default defineComponent({
  name: 'Folder',
  components: {
    FolderIcon,
    CheckboxMarkedCircleIcon,
    CheckboxBlankCircleOutlineIcon,
  },

  mixins: [UserConfig],

  props: {
    data: {
      type: Object as PropType<IFolder>,
      required: true,
    },
    selected: {
      type: Boolean,
      default: false,
    },
    selectionMode: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['toggle-select'],

  computed: {
    /** Open folder */
    target() {
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
  },

  methods: {
    /** Get preview url */
    previewUrl(info: IPhoto) {
      return utils.getPreviewUrl({
        photo: info,
        sqsize: 256,
      });
    },

    /** Toggle selection instead of navigating when in selection mode */
    onClick(e: Event) {
      if (this.selectionMode) {
        e.preventDefault();
        this.onToggleSelect();
      }
    },

    onToggleSelect() {
      this.$emit('toggle-select');
    },
  },
});
</script>

<style lang="scss" scoped>
.folder {
  cursor: pointer;
}

.select-checkbox {
  cursor: pointer;
  z-index: 200;
  position: absolute;
  margin: 8px;
  opacity: 0;
  transition: opacity 0.2s ease-in-out;
  filter: drop-shadow(0 0 2px rgba(0, 0, 0, 0.8));

  .icon {
    width: 28px;
    height: 28px;
    color: white;
  }

  .icon.checked {
    color: var(--color-primary-element, #00639b);
  }

  .folder:hover > &,
  &.visible {
    opacity: 1;
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
    opacity: 0;
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
      transition: filter 0.2s ease-in-out;

      &.error {
        display: none;
      }
      .folder:hover & {
        filter: brightness(100%);
      }
    }
  }
}
</style>
