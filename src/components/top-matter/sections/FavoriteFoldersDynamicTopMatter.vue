<template>
  <FolderGrid v-if="folders.length" :items="folders" />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';

import UserMixin from '@mixins/UserConfig';

import FolderGrid from '../modal/FolderGrid.vue';

import { API } from '@services/API';

import type { IFolder } from '@typings';

/** Shape of a single entry returned by API.FOLDERS_FAVORITES() */
type IFavoriteFolder = Omit<IFolder, 'path'> & { path: string };

export default defineComponent({
  name: 'FavoriteFoldersDynamicTopMatter',

  data: () => ({
    folders: [] as IFolder[],
  }),

  components: {
    FolderGrid,
  },

  mixins: [UserMixin],

  methods: {
    async refresh(): Promise<boolean> {
      // Nothing pinned: skip the request entirely rather than hitting the
      // backend (which resolves each path against the filesystem) on every
      // visit to the Favorites page for no reason.
      if (!this.config.favorite_folders) {
        this.folders = [];
        return false;
      }

      let folders: IFolder[];
      try {
        const data = (await axios.get<IFavoriteFolder[]>(API.FOLDERS_FAVORITES())).data;
        folders = data.map((f) => ({
          ...f,
          flag: 0,
          key: `folder-${f.fileid}`,
          path: f.path.split('/').filter(Boolean),
        }));
      } catch (e) {
        console.error(e);
        return false;
      }

      this.folders = folders;

      return !!this.folders.length;
    },
  },
});
</script>
