<template>
  <NcActions :aria-label="t('memories', 'Refresh')">
    <template #icon> <RefreshIcon :size="20" /> </template>

    <!-- No panel: this dropdown is nothing but Refresh, so it needs no drill-down. -->
    <RefreshMenuActions />
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import RefreshMenuActions from './RefreshMenuActions';
import UserConfig from '@mixins/UserConfig';

export default defineComponent({
  name: 'RefreshOptionsAction',

  components: {
    NcActions,
    RefreshMenuActions,

    RefreshIcon,
  },

  mixins: [UserConfig],
});
</script>
