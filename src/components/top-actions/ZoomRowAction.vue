<template>
  <li class="action zoom-row-action">
    <span class="zoom-row-action__content">
      <span class="zoom-row-action__label">{{ label }}</span>
      <NcButton
        type="tertiary"
        :aria-label="t('memories', 'Zoom out')"
        :disabled="disableMinus"
        @click="$emit('zoom-out')"
      >
        <template #icon> <MinusIcon :size="20" /> </template>
      </NcButton>
      <span class="zoom-row-action__indicator">{{ value }}</span>
      <NcButton
        type="tertiary"
        :aria-label="t('memories', 'Zoom in')"
        :disabled="disablePlus"
        @click="$emit('zoom-in')"
      >
        <template #icon> <PlusIcon :size="20" /> </template>
      </NcButton>
    </span>
  </li>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import MinusIcon from 'vue-material-design-icons/Minus.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import { zoomLabel } from '@services/zoom';

export default defineComponent({
  name: 'ZoomRowAction',

  components: {
    NcButton,
    MinusIcon,
    PlusIcon,
  },

  props: {
    label: {
      type: String,
      required: true,
    },
    value: {
      type: String,
      required: true,
    },
    disableMinus: {
      type: Boolean,
      default: false,
    },
    disablePlus: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['zoom-out', 'zoom-in'],
});
</script>

<style lang="scss" scoped>
.zoom-row-action__content {
  display: flex;
  align-items: center;
  gap: 0px;
  padding: 2px 0px 2px 8px;
  width: 100%;
  box-sizing: border-box;

  .zoom-row-action__label {
    flex-grow: 1;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .zoom-row-action__indicator {
    flex-grow: 1;
    text-align: center;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 50px;
    min-width: 50px;
  }
}
</style>
