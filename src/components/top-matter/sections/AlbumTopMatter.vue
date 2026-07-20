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
        <ViewOptionsAction>
          <template v-if="isAlbumList">
            <NcActionSeparator />

            <NcActionCaption :name="t('memories', 'Album options')" />

            <NcActionCheckbox :checked="config.show_hidden_albums" @update:checked="toggleShowHiddenAlbums">
              {{ t('memories', 'Show hidden albums') }}
            </NcActionCheckbox>
          </template>
        </ViewOptionsAction>
        <FilterDropdownButton />
        <RefreshOptionsAction />
        <NcActions :inline="isMobile ? 0 : 2" v-if="!routeIsPublic" :aria-label="t('memories', 'Others')">
          <template #icon> <DotsHorizontalIcon :size="20" /> </template>

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
import ViewOptionsAction from '@components/top-actions/ViewOptionsAction.vue';
import RefreshOptionsAction from '@components/top-actions/RefreshOptionsAction.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import { downloadWithHandle } from '@services/dav';
import { API } from '@services/API';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';

import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';

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
    ViewOptionsAction,
    RefreshOptionsAction,
    FilterDropdownButton,

    BackIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
    DotsHorizontalIcon,
  },

  mixins: [UserConfig],

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
  },

  methods: {
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
