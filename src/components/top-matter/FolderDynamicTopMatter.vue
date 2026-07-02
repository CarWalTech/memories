<template>
  <div v-if="show">
    <div class="selection-bar" v-if="selected.size">
      <span class="selection-count">
        {{ n('memories', '{n} selected', '{n} selected', selected.size, { n: selected.size }) }}
      </span>

      <NcActions :inline="2">
        <NcActionButton v-if="selected.size === 1" @click="renameSelected" close-after-click>
          {{ t('memories', 'Rename') }}
          <template #icon> <PencilIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton @click="deleteSelected" close-after-click>
          {{ t('memories', 'Delete') }}
          <template #icon> <DeleteIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton @click="clearSelection" close-after-click>
          {{ t('memories', 'Cancel') }}
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <FolderGrid :items="folders" :selected="selected" @toggle-select="toggleSelect" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserMixin from '@mixins/UserConfig';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { getLanguage } from '@nextcloud/l10n';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import FolderGrid from './FolderGrid.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import { API } from '@services/API';

import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';

import type { IFolder } from '@typings';

export default defineComponent({
  name: 'FolderDynamicTopMatter',

  data: () => ({
    folders: [] as IFolder[],
    currentFolder: '<none>',
    basePath: '',
    selected: new Set<number>(),
  }),

  components: {
    FolderGrid,
    NcActions,
    NcActionButton,
    PencilIcon,
    DeleteIcon,
    CloseIcon,
  },

  mixins: [UserMixin],

  computed: {
    show() {
      return this.folders.length && !this.$route.query.recursive;
    },
  },

  methods: {
    async refresh(): Promise<boolean> {
      const folder = utils.getFolderRoutePath(this.config.folders_path);

      // Clear folders if switching to a different folder, otherwise just refresh
      if (this.currentFolder === folder) {
        this.currentFolder = folder;
        this.folders = [];
      }

      this.basePath = folder;
      this.clearSelection();

      // Get subfolders URL
      const url = API.Q(API.FOLDERS_SUB(), { folder });

      // Make API call to get subfolders
      try {
        this.folders = (await axios.get<IFolder[]>(url)).data;
      } catch (e) {
        console.error(e);
        return false;
      }

      // Filter out hidden and/or empty folders
      this.folders = this.folders.filter((f) => {
        if (!this.config.show_hidden_folders && f.name.startsWith('.')) return false;
        if (!this.config.show_empty_folders && !f.previews?.length) return false;
        return true;
      });

      this.sortFolders();

      return this.folders.length > 0;
    },

    sortFolders() {
      this.folders.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));
    },

    toggleSelect(folder: IFolder) {
      const selected = new Set(this.selected);
      if (selected.has(folder.fileid)) {
        selected.delete(folder.fileid);
      } else {
        selected.add(folder.fileid);
      }
      this.selected = selected;
    },

    clearSelection() {
      this.selected = new Set();
    },

    async renameSelected(): Promise<void> {
      const folder = this.folders.find((f) => this.selected.has(f.fileid));
      if (!folder) return;

      const name = (
        await utils.prompt({
          title: this.t('memories', 'Rename folder'),
          message: this.t('memories', 'Enter a new name for "{name}"', { name: folder.name }),
          name: this.t('memories', 'Name'),
        })
      )?.trim();

      if (!name || name === folder.name) return;

      if (name.includes('/')) {
        showError(this.t('memories', 'Folder name cannot contain "/"'));
        return;
      }

      try {
        await dav.renameFolder(`${this.basePath}/${folder.name}`, `${this.basePath}/${name}`);
      } catch (e) {
        showError(
          e?.response?.status === 412
            ? this.t('memories', 'A folder with this name already exists')
            : this.t('memories', 'Failed to rename folder'),
        );
        return;
      }

      folder.name = name;
      this.sortFolders();
      this.clearSelection();
    },

    async deleteSelected(): Promise<void> {
      const targets = this.folders.filter((f) => this.selected.has(f.fileid));
      if (!targets.length || !(await utils.dialogs.moveToTrash(targets.length))) return;

      const calls = targets.map((f) => async () => {
        try {
          await dav.deleteFolder(`${this.basePath}/${f.name}`);
          return f.fileid;
        } catch (e) {
          console.error('Failed to delete folder', f, e);
          showError(this.t('memories', 'Failed to delete {name}.', { name: f.name }));
          return 0;
        }
      });

      for await (const ids of dav.runInParallel(calls, 10)) {
        const deleted = new Set(ids.filter(Boolean));
        this.folders = this.folders.filter((f) => !deleted.has(f.fileid));
      }

      this.clearSelection();
    },
  },
});
</script>

<style lang="scss" scoped>
.selection-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 15px 10px;

  .selection-count {
    font-weight: bold;
  }
}
</style>
