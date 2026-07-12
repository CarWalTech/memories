<template>
  <NcActions :aria-label="t('memories', 'Sort options')">
    <template #icon> <SortVariantIcon :size="20" /> </template>

    <NcActionText :name="t('memories', 'WIP')" />

    <!-- View-specific extras -->
    <slot />
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionText from '@nextcloud/vue/dist/Components/NcActionText.js';

import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue';

export default defineComponent({
  name: 'SortOptionsAction',

  components: {
    NcActions,
    NcActionText,
    SortVariantIcon,
  },
});
</script>
