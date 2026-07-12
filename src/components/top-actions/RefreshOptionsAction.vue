<template>
  <NcActions :aria-label="t('memories', 'Refresh')">
    <template #icon> <RefreshIcon :size="20" /> </template>

    <NcActionButton close-after-click @click="requestFullIndexRefresh('all')">
      {{ t('memories', 'Refresh files') }}
    </NcActionButton>
    <NcActionButton close-after-click @click="requestFullIndexRefresh('untracked')">
      {{ t('memories', 'Refresh untracked files') }}
    </NcActionButton>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import UserConfig from '@mixins/UserConfig';
import { requestFullIndexRefresh } from '@services/index-refresh';

export default defineComponent({
  name: 'RefreshOptionsAction',

  components: {
    NcActions,
    NcActionButton,

    RefreshIcon,
  },

  mixins: [UserConfig],

  computed: {},

  methods: {
    requestFullIndexRefresh,
  },
});
</script>
