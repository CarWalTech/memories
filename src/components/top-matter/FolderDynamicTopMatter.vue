<template>
  <FolderGrid v-if="show" :items="folders" :selection-manager="selectionManager" />
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import UserMixin from '@mixins/UserConfig';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import FolderGrid from './FolderGrid.vue';
import type SelectionManager from '@components/SelectionManager.vue';

import * as utils from '@services/utils';
import { API } from '@services/API';

import type { IFolder } from '@typings';

export default defineComponent({
  name: 'FolderDynamicTopMatter',

  data: () => ({
    folders: [] as IFolder[],
    currentFolder: '<none>',
  }),

  components: {
    FolderGrid,
  },

  mixins: [UserMixin],

  props: {
    selectionManager: {
      type: Object as PropType<InstanceType<typeof SelectionManager>>,
      required: false,
    },
  },

  computed: {
    show() {
      return this.folders.length && !this.$route.query.recursive;
    },
  },

  created() {
    utils.bus.on('memories:folders:deleted', this.onFoldersDeleted);
    utils.bus.on('memories:folders:renamed', this.sortFolders);
  },

  beforeDestroy() {
    utils.bus.off('memories:folders:deleted', this.onFoldersDeleted);
    utils.bus.off('memories:folders:renamed', this.sortFolders);
  },

  methods: {
    async refresh(): Promise<boolean> {
      const folder = utils.getFolderRoutePath(this.config.folders_path);

      // Clear folders if switching to a different folder, otherwise just refresh
      if (this.currentFolder === folder) {
        this.currentFolder = folder;
        this.folders = [];
      }

      // Get subfolders URL
      const url = API.Q(API.FOLDERS_SUB(), { folder });

      // Make API call to get subfolders
      try {
        const data = (await axios.get<IFolder[]>(url)).data;

        // Initialize the fields SelectionManager's Selection map needs at
        // runtime (the API response doesn't include them; IFolder only
        // requires them because it structurally extends IPhoto).
        this.folders = data.map((f) => ({ ...f, flag: 0, key: `folder-${f.fileid}` }));
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

    onFoldersDeleted(fileids: number[]) {
      const deleted = new Set(fileids);
      this.folders = this.folders.filter((f) => !deleted.has(f.fileid));
    },
  },
});
</script>
