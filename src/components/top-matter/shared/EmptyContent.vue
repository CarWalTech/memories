<template>
  <NcEmptyContent :name="t('memories', 'Nothing to show here')" :description="emptyViewDescription">
    <template #icon>
      <PeopleIcon v-if="routeIsPeople" />
      <ArchiveIcon v-else-if="routeIsArchive" />
      <AlbumIcon v-else-if="routeIsAlbums" />
      <MapIcon v-else-if="routeIsMap" />
      <FolderIcon v-else-if="routeIsFolders" />
      <ImageMultipleIcon v-else />
    </template>
    <template #action>
      <NcButton variant="primary" @click="resetFilters" v-if="hasFilters">
        {{ t('memories', 'Reset filters') }}
      </NcButton>
      <NcButton variant="primary" @click="createFolder" v-else-if="routeIsFolders && !routeIsPublic">
        {{ t('memories', 'New folder') }}
      </NcButton>
    </template>
  </NcEmptyContent>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import UserConfig from '@mixins/UserConfig';

import * as strings from '@services/strings';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import FolderIcon from 'vue-material-design-icons/Folder.vue';

export default defineComponent({
  name: 'EmptyContent',

  components: {
    NcEmptyContent,
    NcButton,
    PeopleIcon,
    ArchiveIcon,
    ImageMultipleIcon,
    AlbumIcon,
    MapIcon,
    FolderIcon,
  },

  mixins: [UserConfig],

  emits: ['reset-filters'],

  props: {
    hasFilters: {
      type: Boolean,
      default: false,
    },
  },

  computed: {
    emptyViewDescription(): string {
      return strings.emptyDescription(this.$route.name!);
    },

  },

  methods: {
    resetFilters() {
      this.$emit('reset-filters');
    },

    async createFolder(): Promise<void> {
      const base = utils.getFolderRoutePath(this.config.folders_path);
      const name = await dav.createFolderWithPrompt(base);
      if (!name) return;

      // Navigate straight into the new folder
      let path: string[] | string = this.$route.params.path || [];
      if (typeof path === 'string') {
        path = path.split('/');
      }

      this.$router.push(this.getRoute([...path, name]));
    },

    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },
  },
});
</script>
