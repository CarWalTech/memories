<template>
  <NcActions
    :aria-label="`${t('memories', 'View mode')}: ${shapeLabel}`"
    :menu-title="`${t('memories', 'View mode')}: ${shapeLabel}`"
    :force-menu="true"
  >
    <template #icon>
      <component :is="shapeIcon(effectiveShape)" :size="20" />
    </template>

    <NcActionCheckbox v-if="showItemToggle" :checked="itemToggleOn" @update:checked="toggleItemScope">
      {{ itemToggleLabel }}
    </NcActionCheckbox>

    <NcActionSeparator v-if="showItemToggle" />

    <NcActionButton
      type="radio"
      :aria-label="t('memories', 'Dynamic')"
      value="dynamic"
      :model-value="effectiveShape"
      @update:modelValue="changeImageDisplayShape('dynamic')"
      close-after-click
    >
      <template #icon><ViewDynamicIcon :size="20" /></template>
      {{ t('memories', 'Dynamic') }}
    </NcActionButton>

    <NcActionButton
      type="radio"
      :aria-label="t('memories', 'Square')"
      value="square"
      :model-value="effectiveShape"
      @update:modelValue="changeImageDisplayShape('square')"
      close-after-click
    >
      <template #icon><ViewSquareIcon :size="20" /></template>
      {{ t('memories', 'Square') }}
    </NcActionButton>

    <NcActionButton
      type="radio"
      :aria-label="t('memories', 'Grid')"
      value="grid"
      :model-value="effectiveShape"
      @update:modelValue="changeImageDisplayShape('grid')"
      close-after-click
    >
      <template #icon><ViewGridIcon :size="20" /></template>
      {{ t('memories', 'Grid') }}
    </NcActionButton>

    <NcActionButton
      type="radio"
      :aria-label="t('memories', 'List')"
      value="list"
      :model-value="effectiveShape"
      @update:modelValue="changeImageDisplayShape('list')"
      close-after-click
    >
      <template #icon><ViewListIcon :size="20" /></template>
      {{ t('memories', 'List') }}
    </NcActionButton>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';

import ViewPrefs from '@mixins/ViewPrefs';
import viewModeScope, { type ImageDisplayShape } from '@services/view-mode-scope';

import ViewDynamicIcon from 'vue-material-design-icons/ViewComfyOutline.vue';
import ViewSquareIcon from 'vue-material-design-icons/CropSquare.vue';
import ViewGridIcon from 'vue-material-design-icons/ViewGridOutline.vue';
import ViewListIcon from 'vue-material-design-icons/ViewListOutline.vue';

const SHAPE_LABELS: Record<ImageDisplayShape, string> = {
  dynamic: 'Dynamic',
  square: 'Square',
  grid: 'Grid',
  list: 'List',
};

const SHAPE_ICONS: Record<ImageDisplayShape, unknown> = {
  dynamic: ViewDynamicIcon,
  square: ViewSquareIcon,
  grid: ViewGridIcon,
  list: ViewListIcon,
};

export default defineComponent({
  name: 'ViewModeAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionSeparator,
    ViewDynamicIcon,
    ViewSquareIcon,
    ViewGridIcon,
    ViewListIcon,
  },

  mixins: [ViewPrefs],

  computed: {
    shapeLabel(): string {
      return this.t('memories', SHAPE_LABELS[this.effectiveShape]);
    },

    /** Item-scope toggle is only offered on routes with a real sub-identity currently being viewed. */
    showItemToggle(): boolean {
      return this.scopeKey != null && this.itemToggleLabel != null;
    },

    itemToggleLabel(): string | null {
      switch (this.routeId) {
        case 'Folders':
        case 'FolderShare':
          return this.t('memories', 'Custom view mode for this folder');
        case 'Albums':
        case 'AlbumShare':
          return this.t('memories', 'Custom view mode for this album');
        case 'Tags':
        case 'EmbeddedTags':
          return this.t('memories', 'Custom view mode for this tag');
        default:
          return null;
      }
    },

    itemToggleOn(): boolean {
      return viewModeScope.isEnabled(this.routeId, this.scopeKey);
    },
  },

  methods: {
    shapeIcon(shape: ImageDisplayShape) {
      return SHAPE_ICONS[shape];
    },

    changeImageDisplayShape(mode: ImageDisplayShape): void {
      if (this.routeId == null) return;

      if (this.itemToggleOn && this.scopeKey != null) {
        viewModeScope.setItem(this.routeId, this.scopeKey, mode);
      } else {
        viewModeScope.setView(this.routeId, mode);
      }
    },

    toggleItemScope(checked: boolean): void {
      if (this.routeId == null || this.scopeKey == null) return;

      if (checked) {
        viewModeScope.enable(this.routeId, this.scopeKey, this.effectiveShape);
      } else {
        viewModeScope.disable(this.routeId, this.scopeKey);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
// Icon itself already matches every other action button (20px) -- tighten the
// button's own clickable-area/padding so it doesn't read as swimming in empty space.
// Width and height shrink together (not just width) so the round hover/focus indicator stays circular.
:deep(.action-item__menutoggle) {
  width: 36px !important;
  height: 36px !important;
}
</style>
