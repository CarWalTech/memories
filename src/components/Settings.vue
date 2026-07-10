<template>
  <div>
    <NcAppSettingsDialog
      id="memories-settings"
      class="memories-modal"
      :open="open"
      :show-navigation="true"
      :name="names.header"
      @update:open="onClose"
    >
      <NcAppSettingsSection id="general-settings" :name="names.general">
        <NcCheckboxRadioSwitch :checked.sync="config.square_thumbs" @update:checked="updateSquareThumbs" type="switch">
          {{ t('memories', 'Square grid mode') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.enable_top_memories"
          @update:checked="updateEnableTopMemories"
          type="switch"
        >
          {{ t('memories', 'Show past photos on top of timeline') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.stack_raw_files"
          @update:checked="updateStackRawFiles"
          type="switch"
        >
          {{ t('memories', 'Stack RAW files with same name') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.dedup_identical"
          @update:checked="updateDedupIdentical"
          type="switch"
        >
          {{ t('memories', 'De-duplicate identical files') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.show_owner_name_timeline"
          @update:checked="updateShowOwnerNameTimeline"
          type="switch"
        >
          {{ t('memories', 'Show photo owner name on timeline') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="mobile-nav-settings" :name="names.mobileNav">
        <p class="settings-hint">
          {{
            t('memories', 'Choose up to {max} items for the mobile bottom navigation bar. Drag to reorder.', {
              max: MAX_MOBILE_NAV_ITEMS,
            })
          }}
        </p>

        <draggable v-model="mobileNavOrder" tag="ul" class="mobile-nav-order" handle=".drag-handle">
          <li v-for="item in mobileNavOrder" :key="item.name">
            <DragHandleIcon class="drag-handle" :size="18" />
            <component :is="item.icon" :size="18" />
            <span class="label">{{ item.title }}</span>
            <NcButton
              v-if="item.name !== REQUIRED_NAV_ITEM"
              type="tertiary"
              :ariaLabel="t('memories', 'Remove')"
              :disabled="mobileNavOrder.length <= MIN_MOBILE_NAV_ITEMS"
              @click="removeMobileNavItem(item.name)"
            >
              <template #icon><CloseIcon :size="16" /></template>
            </NcButton>
          </li>
        </draggable>

        <NcSelect
          v-if="mobileNavOrder.length < MAX_MOBILE_NAV_ITEMS"
          v-model="mobileNavAddSelection"
          class="mobile-nav-add"
          :options="mobileNavAvailableToAdd"
          label="title"
          :placeholder="t('memories', 'Add an item…')"
        />
      </NcAppSettingsSection>

      <NcAppSettingsSection id="viewer-settings" :name="names.viewer">
        <NcCheckboxRadioSwitch
          :checked.sync="config.livephoto_autoplay"
          @update:checked="updateLivephotoAutoplay"
          type="switch"
        >
          {{ t('memories', 'Autoplay Live Photos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.livephoto_loop"
          @update:checked="updateLivephotoLoop"
          type="switch"
        >
          {{ t('memories', 'Loop Live Photos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch :checked.sync="config.video_loop" @update:checked="updateVideoLoop" type="switch">
          {{ t('memories', 'Loop Videos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.sidebar_filepath"
          @update:checked="updateSidebarFilepath"
          type="switch"
        >
          {{ t('memories', 'Show full file path in sidebar') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.metadata_in_slideshow"
          @update:checked="updateMetadataInSlideshow"
          type="switch"
        >
          {{ t('memories', 'Show metadata in slideshow') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.metadata_in_gallery"
          @update:checked="updateMetadataInGallery"
          type="switch"
        >
          {{ t('memories', 'Show metadata in gallery') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.enable_exif_photo_rating_in_gallery"
          @update:checked="updateEnableExifPhotoRatingInGallery"
          type="switch"
        >
          {{ t('memories', 'Enable photo rating in gallery') }}
        </NcCheckboxRadioSwitch>

        <div class="radio-group">
          <div class="title">{{ t('memories', 'High resolution image loading behavior') }}</div>
          <NcCheckboxRadioSwitch
            :checked="highResCond"
            value="zoom"
            name="vhrc_radio"
            type="radio"
            @update:checked="updateHighResCond($event)"
            >{{ t('memories', 'Load high resolution image on zoom') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="highResCond"
            value="always"
            name="vhrc_radio"
            type="radio"
            @update:checked="updateHighResCond($event)"
            >{{ t('memories', 'Always load high resolution image (not recommended)') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="highResCond"
            value="never"
            name="vhrc_radio"
            type="radio"
            @update:checked="updateHighResCond($event)"
            >{{ t('memories', 'Never load high resolution image') }}
          </NcCheckboxRadioSwitch>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="metadata-settings" :name="names.metadata">
        <div class="radio-group">
          <div class="title">{{ t('memories', 'Where to write edited metadata (dates, location, tags, rating)') }}</div>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_write_mode"
            value="sidecar_only"
            name="metadata_write_mode_radio"
            type="radio"
            @update:checked="updateMetadataWriteMode($event)"
            >{{ t('memories', 'Sidecars only (created if needed)') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_write_mode"
            value="file_only"
            name="metadata_write_mode_radio"
            type="radio"
            @update:checked="updateMetadataWriteMode($event)"
            >{{ t('memories', 'Photo metadata only') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_write_mode"
            value="file_and_sidecar"
            name="metadata_write_mode_radio"
            type="radio"
            @update:checked="updateMetadataWriteMode($event)"
            >{{ t('memories', 'Photo metadata and sidecars') }}
          </NcCheckboxRadioSwitch>
        </div>

        <div class="radio-group">
          <div class="title">{{ t('memories', 'Where to read metadata from, when a sidecar file exists') }}</div>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_read_mode"
            value="sidecar_only"
            name="metadata_read_mode_radio"
            type="radio"
            @update:checked="updateMetadataReadMode($event)"
            >{{ t('memories', 'Sidecar only (fall back to photo if no sidecar exists)') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_read_mode"
            value="prefer_sidecar"
            name="metadata_read_mode_radio"
            type="radio"
            @update:checked="updateMetadataReadMode($event)"
            >{{ t('memories', 'Prioritize sidecar data') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_read_mode"
            value="prefer_file"
            name="metadata_read_mode_radio"
            type="radio"
            @update:checked="updateMetadataReadMode($event)"
            >{{ t('memories', 'Prioritize photo metadata') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :checked="config.metadata_read_mode"
            value="file_only"
            name="metadata_read_mode_radio"
            type="radio"
            @update:checked="updateMetadataReadMode($event)"
            >{{ t('memories', 'Photo metadata only (ignore sidecars)') }}
          </NcCheckboxRadioSwitch>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="account-settings" :name="names.account" v-if="isNative">
        {{ t('memories', 'Logged in as {user}', { user }) }}
        <NcButton @click="logout" id="sign-out">
          {{ t('memories', 'Sign out') }}
        </NcButton>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="device-settings" :name="t('memories', 'Device Folders')" v-if="isNative">
        {{ t('memories', 'Local folders to include in the timeline view') }}
        <NcCheckboxRadioSwitch
          v-for="folder in localFolders"
          :key="folder.id"
          :checked.sync="folder.enabled"
          @update:checked="updateDeviceFolders"
          type="switch"
        >
          {{ folder.name }}
        </NcCheckboxRadioSwitch>

        <NcButton @click="runNxSetup()" type="secondary">
          {{ t('memories', 'Run initial device setup') }}
        </NcButton>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="folders-settings" :name="names.folders">
        <NcTextField
          :label="t('memories', 'Folders Path')"
          :label-visible="true"
          v-model="config.folders_path"
          @click="chooseFoldersPath"
          readonly
        />

        <NcTextField
          :label="t('memories', 'Timeline Path')"
          :label-visible="true"
          v-model="config.timeline_path"
          @click="chooseTimelinePath"
          readonly
        />

        <NcTextField
          :label="t('memories', 'All Photos: Excluded Paths')"
          :label-visible="true"
          v-model="config.all_photos_exclude_paths"
          @click="chooseAllPhotosExcludePaths"
          readonly
        />

        <NcCheckboxRadioSwitch
          :checked.sync="config.show_hidden_folders"
          @update:checked="updateShowHidden"
          type="switch"
        >
          {{ t('memories', 'Show hidden folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.show_empty_folders"
          @update:checked="updateShowEmpty"
          type="switch"
        >
          {{ t('memories', 'Show empty folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.sort_folder_month"
          @update:checked="updateSortFolderMonth"
          type="switch"
        >
          {{ t('memories', 'Sort folders oldest-first') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="albums-settings" :name="names.albums">
        <NcCheckboxRadioSwitch
          :checked.sync="config.sort_album_month"
          @update:checked="updateSortAlbumMonth"
          type="switch"
        >
          {{ t('memories', 'Sort albums oldest-first') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.show_hidden_albums"
          @update:checked="updateShowHidden"
          type="switch"
        >
          {{ t('memories', 'Show hidden albums') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>
    </NcAppSettingsDialog>

    <MultiPathSelectionModal ref="foldersPathModal" :title="foldersPathSelTitle" :min="1" @close="saveFoldersPath" />
    <MultiPathSelectionModal ref="multiPathModal" :title="pathSelTitle" :min="1" @close="saveTimelinePath" />
    <MultiPathSelectionModal
      ref="excludePathsModal"
      :title="excludePathsSelTitle"
      :add-prompt="t('memories', 'Choose a folder to exclude')"
      @close="saveAllPhotosExcludePaths"
    />
  </div>
</template>

<style scoped>
input[type='text'] {
  width: 100%;
}
</style>

<script lang="ts">
import { defineComponent } from 'vue';

import draggable from 'vuedraggable';

import UserConfig from '@mixins/UserConfig';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { availableNavState, withRequiredNavItem, REQUIRED_NAV_ITEM, type AvailableNavItem } from '@services/nav-items';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');
const NcSelect = () => import('@nextcloud/vue/dist/Components/NcSelect.js');
const NcAppSettingsDialog = () => import('@nextcloud/vue/dist/Components/NcAppSettingsDialog.js');
const NcAppSettingsSection = () => import('@nextcloud/vue/dist/Components/NcAppSettingsSection.js');
const NcCheckboxRadioSwitch = () => import('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js');

import MultiPathSelectionModal from '@components/modal/MultiPathSelectionModal.vue';

import DragHandleIcon from 'vue-material-design-icons/DragVertical.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';

import type { IConfig } from '@typings';

export default defineComponent({
  name: 'Settings',

  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcAppSettingsDialog,
    NcAppSettingsSection,
    NcCheckboxRadioSwitch,
    MultiPathSelectionModal,
    draggable,
    DragHandleIcon,
    CloseIcon,
  },

  mixins: [UserConfig],

  emits: {
    'update:open': (open: boolean) => true,
  },

  data: () => ({
    localFolders: [] as nativex.LocalFolderConfig[],
    names: {
      header: t('memories', 'Memories Settings'),
      general: t('memories', 'General'),
      mobileNav: t('memories', 'Mobile Navigation'),
      viewer: t('memories', 'Photo Viewer'),
      metadata: t('memories', 'Metadata'),
      account: t('memories', 'Account'),
      folders: t('memories', 'Folders'),
      albums: t('memories', 'Albums'),
    },
    MIN_MOBILE_NAV_ITEMS: 1,
    MAX_MOBILE_NAV_ITEMS: 5,
    REQUIRED_NAV_ITEM,
    mobileNavAddSelection: null as AvailableNavItem | null,
  }),

  props: {
    open: {
      type: Boolean,
      required: true,
    },
  },

  computed: {
    refs() {
      return this.$refs as {
        foldersPathModal: InstanceType<typeof MultiPathSelectionModal>;
        multiPathModal: InstanceType<typeof MultiPathSelectionModal>;
        excludePathsModal: InstanceType<typeof MultiPathSelectionModal>;
      };
    },

    foldersPathSelTitle(): string {
      return this.t('memories', 'Choose Folders Paths');
    },

    pathSelTitle(): string {
      return this.t('memories', 'Choose Timeline Paths');
    },

    excludePathsSelTitle(): string {
      return this.t('memories', 'Choose Paths to Exclude from All Photos');
    },

    isNative(): boolean {
      return nativex.has();
    },

    user(): string {
      return utils.uid ?? String();
    },

    highResCond(): IConfig['high_res_cond_default'] {
      return this.config.high_res_cond || this.config.high_res_cond_default || 'zoom';
    },

    mobileNavOrder: {
      get(): AvailableNavItem[] {
        const byName = new Map(availableNavState.items.map((item) => [item.name, item]));
        const items = (this.config.mobile_nav_items ?? [])
          .map((name) => byName.get(name))
          .filter((item): item is AvailableNavItem => !!item);
        return withRequiredNavItem(items);
      },

      set(items: AvailableNavItem[]) {
        this.config.mobile_nav_items = items.map((item) => item.name);
        this.updateSetting('mobile_nav_items');
      },
    },

    mobileNavAvailableToAdd(): AvailableNavItem[] {
      const selected = new Set(this.config.mobile_nav_items ?? []);
      return availableNavState.items.filter((item) => !selected.has(item.name));
    },
  },

  watch: {
    mobileNavAddSelection(item: AvailableNavItem | null) {
      if (!item) return;
      this.mobileNavOrder = [...this.mobileNavOrder, item];
      this.mobileNavAddSelection = null;
    },

    open(value: boolean) {
      utils.fragment.if(value, utils.fragment.types.settings);
    },
  },

  mounted() {
    if (this.isNative) {
      this.refreshNativeConfig();
    }

    // Fragment navigation
    utils.bus.on('memories:fragment:pop:settings', this.onClose);
  },

  beforeDestroy() {
    utils.bus.off('memories:fragment:pop:settings', this.onClose);
  },

  methods: {
    onClose() {
      this.$emit('update:open', false);
    },

    // Paths settings
    async chooseTimelinePath() {
      this.refs.multiPathModal.open(this.config.timeline_path.split(';'));
    },

    async saveTimelinePath(paths: string[]) {
      if (!paths || !paths.length) return;

      const newPath = paths.join(';');
      if (newPath !== this.config.timeline_path) {
        this.config.timeline_path = newPath;
        await this.updateSetting('timeline_path', 'timelinePath');
      }
    },

    async chooseAllPhotosExcludePaths() {
      const paths = this.config.all_photos_exclude_paths ? this.config.all_photos_exclude_paths.split(';') : [];
      this.refs.excludePathsModal.open(paths);
    },

    async saveAllPhotosExcludePaths(paths: string[]) {
      // Unlike timeline_path, an empty list is valid here (no exclusions).
      const newPaths = (paths ?? []).join(';');
      if (newPaths !== this.config.all_photos_exclude_paths) {
        this.config.all_photos_exclude_paths = newPaths;
        await this.updateSetting('all_photos_exclude_paths', 'allPhotosExcludePaths');
      }
    },

    async chooseFoldersPath() {
      this.refs.foldersPathModal.open(this.config.folders_path.split(';'));
    },

    async saveFoldersPath(paths: string[]) {
      if (!paths || !paths.length) return;

      const newPath = paths.join(';');
      if (newPath !== this.config.folders_path) {
        this.config.folders_path = newPath;
        await this.updateSetting('folders_path', 'foldersPath');
      }
    },

    // General settings
    async updateSquareThumbs() {
      await this.updateSetting('square_thumbs');
    },

    async updateEnableTopMemories() {
      await this.updateSetting('enable_top_memories', 'enableTopMemories');
    },

    async updateStackRawFiles() {
      await this.updateSetting('stack_raw_files', 'stackRawFiles');
    },

    async updateDedupIdentical() {
      await this.updateSetting('dedup_identical', 'dedupIdentical');
    },

    async updateShowOwnerNameTimeline() {
      await this.updateSetting('show_owner_name_timeline', 'showOwnerNameTimeline');
    },

    // Mobile navigation settings
    removeMobileNavItem(name: string) {
      if (name === REQUIRED_NAV_ITEM) return;
      if (this.mobileNavOrder.length <= this.MIN_MOBILE_NAV_ITEMS) return;
      this.mobileNavOrder = this.mobileNavOrder.filter((item) => item.name !== name);
    },

    // Viewer settings
    async updateHighResCond(val: IConfig['high_res_cond']) {
      this.config.high_res_cond = val;
      await this.updateSetting('high_res_cond');
    },

    async updateLivephotoAutoplay() {
      await this.updateSetting('livephoto_autoplay', 'livephotoAutoplay');
    },

    async updateLivephotoLoop() {
      await this.updateSetting('livephoto_loop', 'livephotoLoop');
    },

    async updateVideoLoop() {
      await this.updateSetting('video_loop', 'videoLoop');
    },

    async updateSidebarFilepath() {
      await this.updateSetting('sidebar_filepath', 'sidebarFilepath');
    },

    async updateMetadataWriteMode(val: IConfig['metadata_write_mode']) {
      this.config.metadata_write_mode = val;
      await this.updateSetting('metadata_write_mode', 'metadataWriteMode');
    },

    async updateMetadataReadMode(val: IConfig['metadata_read_mode']) {
      this.config.metadata_read_mode = val;
      await this.updateSetting('metadata_read_mode', 'metadataReadMode');
    },

    async updateMetadataInSlideshow() {
      await this.updateSetting('metadata_in_slideshow', 'metadataInSlideshow');
    },

    async updateMetadataInGallery() {
      await this.updateSetting('metadata_in_gallery', 'metadataInGallery');
    },

    async updateEnableExifPhotoRatingInGallery() {
      await this.updateSetting('enable_exif_photo_rating_in_gallery', 'enableExifPhotoRatingInGallery');
    },

    // Folders settings
    async updateShowHidden() {
      await this.updateSetting('show_hidden_folders', 'showHidden');
      await this.updateSetting('show_hidden_albums', 'showHiddenAlbums');
    },

    async updateShowEmpty() {
      await this.updateSetting('show_empty_folders', 'showEmpty');
    },

    async updateSortFolderMonth() {
      await this.updateSetting('sort_folder_month', 'sortFolderMonth');
    },

    // Albums settings
    async updateSortAlbumMonth() {
      await this.updateSetting('sort_album_month', 'sortAlbumMonth');
    },

    // --------------- Native APIs start -----------------------------
    refreshNativeConfig() {
      this.localFolders = nativex.getLocalFolders();
    },

    updateDeviceFolders() {
      nativex.setLocalFolders(this.localFolders);
    },

    runNxSetup() {
      this.$router.replace('/nxsetup');
    },

    async logout() {
      if (
        await utils.confirmDestructive({
          title: this.t('memories', 'Sign out'),
          message: this.t('memories', 'Are you sure you want to log out {user}?', { user: this.user }),
          confirm: this.t('memories', 'Sign out'),
          confirmClasses: 'error',
          cancel: this.t('memories', 'Cancel'),
        })
      ) {
        nativex.logout();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
#memories-settings:deep {
  .app-settings__content {
    // Fix weirdness when focusing on toggle input on mobile
    position: relative;
  }

  input[readonly] {
    cursor: pointer;
    user-select: none;
  }

  .app-settings-section {
    margin-bottom: 20px !important;
  }

  #sign-out {
    margin-top: 10px;
  }

  .checkbox-radio-switch__label {
    padding: 1px 14px; // was 4px 14px, make it more compact
  }

  .radio-group {
    margin-top: 6px;

    .title {
      font-weight: 500;
    }

    .checkbox-radio-switch-radio {
      margin: 2px 16px; // indent for radio button
    }
  }

  .settings-hint {
    color: var(--color-text-maxcontrast);
    margin-bottom: 6px;
  }

  .mobile-nav-order {
    padding: 0;
    margin: 0 0 10px 0;
    list-style: none;

    li {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 4px 0;

      .drag-handle {
        cursor: grab;
        opacity: 0.6;
      }

      .label {
        flex-grow: 1;
      }
    }
  }

  .mobile-nav-add {
    max-width: 300px;
  }
}
</style>
