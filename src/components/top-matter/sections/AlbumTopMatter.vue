<template>
  <div class="top-matter">
    <div class="top-row">
      <NcActions v-if="!isAlbumList">
        <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <div class="name">{{ name }}</div>
      <div class="right-actions">
        <NcActions :inline="1">
          <NcActionButton
            :aria-label="t('memories', 'Create new album')"
            :title="t('memories', 'Create new album')"
            @click="refs.createModal.open(false)"
            close-after-click
            v-if="isAlbumList"
          >
            {{ t('memories', 'Create new album') }}
            <template #icon> <PlusIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>

        <ViewModeAction>
          <template v-if="isAlbumList">
            <NcActionCheckbox :checked="config.show_hidden_albums" @update:checked="toggleShowHiddenAlbums">
              {{ t('memories', 'Show hidden albums') }}
            </NcActionCheckbox>
          </template>
        </ViewModeAction>
        <SortByAction />
        <FilterDropdownAction />
        <NcActions
          :inline="othersInline"
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Others')"
          @closed="othersPanel = 'root'"
        >
          <template #icon> <DotsHorizontalIcon :size="20" /> </template>

          <template v-if="othersPanel === 'root'">
            <NcActionButton
              :aria-label="t('memories', 'Edit album details')"
              :title="t('memories', 'Edit album details')"
              @click="refs.createModal.open(true)"
              close-after-click
              v-if="canEditAlbum"
            >
              {{ t('memories', 'Edit album details') }}
              <template #icon> <EditIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              :aria-label="t('memories', 'Remove album')"
              :title="t('memories', 'Remove album')"
              @click="refs.deleteModal.open()"
              close-after-click
              v-if="!isAlbumList"
            >
              {{ t('memories', 'Remove album') }}
              <template #icon> <DeleteIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              :aria-label="t('memories', 'Share album')"
              :title="t('memories', 'Share album')"
              @click="openShareModal()"
              close-after-click
              v-if="canEditAlbum"
            >
              {{ t('memories', 'Share album') }}
              <template #icon> <ShareIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              :aria-label="t('memories', 'Download album')"
              :title="t('memories', 'Download album')"
              @click="downloadAlbum()"
              close-after-click
              v-if="!isAlbumList"
            >
              {{ t('memories', 'Download album') }}
              <template #icon> <DownloadIcon :size="20" /> </template>
            </NcActionButton>

            <NcActionSeparator />

            <NcActionButton :aria-label="t('memories', 'Refresh')" @click="othersPanel = 'refresh'">
              {{ t('memories', 'Refresh') }}
              <template #icon> <RefreshIcon :size="20" /> </template>
            </NcActionButton>
          </template>

          <template v-if="othersPanel === 'refresh'">
            <NcActionButton @click="othersPanel = 'root'">
              <template #icon> <BackIcon :size="20" /> </template>
              {{ t('memories', 'Back') }}
            </NcActionButton>

            <NcActionSeparator />

            <NcActionButton close-after-click @click="requestFullIndexRefresh('all')">
              {{ t('memories', 'Refresh files') }}
            </NcActionButton>
            <NcActionButton close-after-click @click="requestFullIndexRefresh('untracked')">
              {{ t('memories', 'Refresh untracked files') }}
            </NcActionButton>
          </template>
        </NcActions>
      </div>
    </div>

    <AlbumCreateModal ref="createModal" />
    <AlbumDeleteModal ref="deleteModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';

import axios from '@nextcloud/axios';

import AlbumCreateModal from '@components/modal/AlbumCreateModal.vue';
import AlbumDeleteModal from '@components/modal/AlbumDeleteModal.vue';
import ViewModeAction from '@components/top-actions/ViewModeAction.vue';
import SortByAction from '@components/top-actions/SortByAction.vue';
import FilterDropdownAction from '@components/top-actions/FilterDropdownAction.vue';

import { downloadWithHandle } from '@services/dav';
import { API } from '@services/API';
import * as utils from '@services/utils';
import { requestFullIndexRefresh } from '@services/index-refresh';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';

import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

export default defineComponent({
  name: 'AlbumTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,

    AlbumCreateModal,
    AlbumDeleteModal,
    ViewModeAction,
    SortByAction,
    FilterDropdownAction,

    BackIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
    DotsHorizontalIcon,
    RefreshIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    othersPanel: 'root' as 'root' | 'refresh',
  }),

  computed: {
    refs() {
      return this.$refs as unknown as {
        createModal: InstanceType<typeof AlbumCreateModal>;
        deleteModal: InstanceType<typeof AlbumDeleteModal>;
      };
    },

    isAlbumList(): boolean {
      return !this.$route.params.name;
    },

    canEditAlbum(): boolean {
      return !this.isAlbumList && this.$route.params.user === utils.uid;
    },

    name(): string {
      // Album name is displayed in the dynamic top matter (timeline)
      return this.isAlbumList ? this.t('memories', 'Albums') : String();
    },

    isMobile(): boolean {
      return utils.isMobile();
    },

    /**
     * Only promote items to standalone inline buttons while showing the root
     * panel -- otherwise the Refresh submenu's Back/entry buttons would get
     * promoted out of the dropdown instead of the album action buttons.
     */
    othersInline(): number {
      if (this.othersPanel !== 'root') return 0;
      return this.isMobile || this.isAlbumList ? 0 : 2;
    },
  },

  methods: {
    requestFullIndexRefresh,

    back() {
      this.$router.go(-1);
    },

    toggleShowHiddenAlbums(): void {
      this.config.show_hidden_albums = !this.config.show_hidden_albums;
      this.updateSetting('show_hidden_albums', 'showHiddenAlbums');
    },

    openShareModal() {
      _m.modals.albumShare(this.$route.params.user, this.$route.params.name);
    },

    async downloadAlbum() {
      const res = await axios.post(API.ALBUM_DOWNLOAD(this.$route.params.user, this.$route.params.name));
      if (res.status === 200 && res.data.handle) {
        downloadWithHandle(res.data.handle);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  padding: 8px;
  display: flex;
  flex-direction: column;
  row-gap: 4px;

  .top-row {
    display: flex;
    align-items: center;
    .name {
      height: 42px;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}
</style>
