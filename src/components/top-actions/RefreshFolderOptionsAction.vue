<template>
  <NcActions :aria-label="t('memories', 'Refresh')">
    <template #icon> <RefreshIcon :size="20" /> </template>

    <NcActionCaption :name="t('memories', 'Refresh untracked files')" />
    <NcActionButton :disabled="refreshingIndex" close-after-click @click="doRefresh('untracked', false)">
      {{ t('memories', 'Refresh Folder') }}
    </NcActionButton>
    <NcActionButton :disabled="refreshingIndex" close-after-click @click="doRefresh('untracked', true)">
      {{ t('memories', 'Refresh Folder + Subfolders') }}
    </NcActionButton>

    <NcActionSeparator />

    <NcActionCaption :name="t('memories', 'Refresh all files')" />
    <NcActionButton :disabled="refreshingIndex" close-after-click @click="doRefresh('all', false)">
      {{ t('memories', 'Refresh Folder') }}
    </NcActionButton>
    <NcActionButton :disabled="refreshingIndex" close-after-click @click="doRefresh('all', true)">
      {{ t('memories', 'Refresh Folder + Subfolders') }}
    </NcActionButton>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { API } from '@services/API';
import * as utils from '@services/utils';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

export default defineComponent({
  name: 'RefreshFolderOptionsAction',

  components: {
    NcActions,
    NcActionButton,
    NcActionSeparator,
    NcActionCaption,

    RefreshIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    refreshingIndex: false,
  }),

  methods: {
    async doRefresh(mode: 'all' | 'untracked', recursive: boolean): Promise<void> {
      this.refreshingIndex = true;
      try {
        const path = utils.getFolderRoutePath(this.config.folders_path);
        const res = await axios.post<{ done?: boolean; queued?: boolean }>(API.INDEX_REFRESH_FOLDER(), {
          path,
          recursive,
          mode,
        });

        if (res.data.done) {
          // Finished within the request itself (the common case for an
          // ordinary-sized folder) -- refresh the view now that we know
          // there's something new to show, instead of waiting on a
          // background job that may not run for a while.
          showSuccess(
            recursive ? this.t('memories', 'Folder and subfolders reindexed') : this.t('memories', 'Folder reindexed'),
          );
          utils.bus.emit('memories:timeline:soft-refresh', null);
        } else {
          showSuccess(this.t('memories', 'This folder has a lot to index -- continuing in the background'));
        }
      } catch (e) {
        showError(e?.response?.data?.message ?? this.t('memories', 'Failed to refresh folder index'));
      } finally {
        this.refreshingIndex = false;
      }
    },
  },
});
</script>
