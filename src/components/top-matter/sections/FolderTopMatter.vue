<template>
  <div class="top-matter">
    <div class="top-row">
      <div class="name">{{ t('memories', 'Folders') }}</div>
      <div class="right-actions">
        <!-- Progress bar for upload -->
        <PublicUploadHandler ref="uploadHandler" v-if="allowPublicUpload" />

        <template v-if="!isRootPicker">
          <NcActions v-if="!routeIsPublic || allowPublicUpload" :inline="1">
            <NcActionButton
              v-if="!routeIsPublic"
              :aria-label="t('memories', 'Upload files')"
              @click="upload()"
              close-after-click
            >
              {{ t('memories', 'Upload files') }}
              <template #icon> <UploadIcon :size="20" /> </template>
            </NcActionButton>

            <!-- Public upload button -->
            <NcActionButton
              v-if="allowPublicUpload"
              :aria-label="t('memories', 'Upload files')"
              :disabled="uploadHandler()?.processing"
              @click="uploadHandler()?.startUpload()"
            >
              {{ t('memories', 'Upload files') }}
              <template #icon> <UploadIcon :size="20" /> </template>
            </NcActionButton>
          </NcActions>

          <ViewOptionsAction>
            <NcActionSeparator />

            <NcActionCaption name="Folder options"></NcActionCaption>

            <NcActionCheckbox :checked="config.show_empty_folders" @update:checked="toggleShowEmptyFolders">
              {{ t('memories', 'Show empty folders') }}
            </NcActionCheckbox>

            <NcActionCheckbox :checked="config.show_hidden_folders" @update:checked="toggleShowHiddenFolders">
              {{ t('memories', 'Show hidden folders') }}
            </NcActionCheckbox>

            <NcActionCheckbox :checked="config.sort_folder_month" @update:checked="toggleSortFolderMonth">
              {{ t('memories', 'Sort folders oldest first') }}
            </NcActionCheckbox>

            <NcActionSeparator />

            <NcActionButton @click="toggleRecursive" close-after-click>
              {{ recursive ? t('memories', 'Folder view') : t('memories', 'Timeline view') }}
              <template #icon>
                <FoldersIcon v-if="recursive" :size="20" />
                <TimelineIcon v-else :size="20" />
              </template>
            </NcActionButton>
          </ViewOptionsAction>
          <FilterDropdownButton v-if="!routeIsPublic" />
          <RefreshFolderOptionsAction v-if="!routeIsPublic" />

          <NcActions v-if="!routeIsPublic" :aria-label="t('memories', 'Others')">
            <template #icon> <DotsHorizontalIcon :size="20" /> </template>

            <NcActionButton :aria-label="t('memories', 'New folder')" @click="createFolder()" close-after-click>
              {{ t('memories', 'New folder') }}
              <template #icon> <NewFolderIcon :size="20" /> </template>
            </NcActionButton>

            <NcActionButton :aria-label="t('memories', 'Share folder')" @click="share()" close-after-click>
              {{ t('memories', 'Share folder') }}
              <template #icon> <ShareIcon :size="20" /> </template>
            </NcActionButton>
          </NcActions>
        </template>
      </div>
    </div>

    <NcBreadcrumbs :key="$route.path">
      <NcBreadcrumb :name="rootFolderName" :to="getRoute([])" :force-icon-text="routeIsPublic">
        <template #icon>
          <ShareIcon v-if="routeIsPublic" :size="20" />
          <HomeIcon v-else :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="folder in list" :key="folder.idx" :name="folder.text" :to="getRoute(folder.path)" />
    </NcBreadcrumbs>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';
import ViewOptionsAction from '@components/top-actions/ViewOptionsAction.vue';
import RefreshFolderOptionsAction from '@components/top-actions/RefreshFolderOptionsAction.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import * as nativex from '@native';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';
import NewFolderIcon from 'vue-material-design-icons/FolderPlus.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';

export default defineComponent({
  name: 'FolderTopMatter',

  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionCaption,
    NcActionSeparator,
    NcButton,
    PublicUploadHandler,
    ViewOptionsAction,
    RefreshFolderOptionsAction,
    FilterDropdownButton,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
    UploadIcon,
    NewFolderIcon,
    DotsHorizontalIcon,
  },

  mixins: [UserConfig],

  computed: {
    list(): {
      text: string;
      path: string[];
      idx: number;
    }[] {
      let path: string[] | string = this.$route.params.path || '';
      if (typeof path === 'string') {
        path = path.split('/');
      }
      path = path.filter(Boolean); // non-empty

      // With multiple configured Folders roots, a root like "Dropmark/Uploads"
      // is a single logical entry point -- collapse its own internal segments
      // into one crumb (using its basename) instead of exposing "Dropmark" as
      // a separate, misleading navigable level above it.
      const roots = utils.getFoldersRoots(this.config.folders_path);
      if (roots.length > 1) {
        let matchedRoot: string[] | null = null;
        for (const root of roots) {
          const rootSegs = root.split('/').filter(Boolean);
          const isMatch = rootSegs.length <= path.length && rootSegs.every((seg, i) => seg === (path as string[])[i]);
          if (isMatch && (!matchedRoot || rootSegs.length > matchedRoot.length)) {
            matchedRoot = rootSegs;
          }
        }

        if (matchedRoot) {
          const rootPath = matchedRoot;
          const rest = path.slice(rootPath.length);
          return [
            { text: rootPath[rootPath.length - 1] ?? '', path: rootPath, idx: 0 },
            ...rest.map((text, i) => ({ text, path: [...rootPath, ...rest.slice(0, i + 1)], idx: i + 1 })),
          ];
        }
      }

      return path.map((text, idx, arr) => {
        const path = arr.slice(0, idx + 1);
        return { text, path, idx };
      });
    },

    recursive(): boolean {
      return !!this.$route.query.recursive;
    },

    rootFolderName(): string {
      return this.routeIsPublic ? this.initstate.shareTitle : this.t('memories', 'Home');
    },

    isNative(): boolean {
      return nativex.has();
    },

    allowPublicUpload(): boolean {
      return this.routeIsPublic && this.initstate.allow_upload === true;
    },

    isRootPicker(): boolean {
      return utils.isFoldersRootPicker(this.config.folders_path, this.$route.params.path);
    },
  },

  methods: {
    share(): void {
      _m.modals.shareNodeLink(utils.getFolderRoutePath(this.config.folders_path));
    },

    upload(): void {
      _m.modals.upload();
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

    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    uploadHandler(): InstanceType<typeof PublicUploadHandler> | null {
      return (this.$refs.uploadHandler as InstanceType<typeof PublicUploadHandler>) || null;
    },

    toggleShowEmptyFolders(): void {
      this.config.show_empty_folders = !this.config.show_empty_folders;
      this.updateSetting('show_empty_folders');
    },

    toggleShowHiddenFolders(): void {
      this.config.show_hidden_folders = !this.config.show_hidden_folders;
      this.updateSetting('show_hidden_folders');
    },

    toggleSortFolderMonth(): void {
      this.config.sort_folder_month = !this.config.sort_folder_month;
      this.updateSetting('sort_folder_month');
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
  > .text {
    flex-grow: 1;
    line-height: 42px;
    padding-left: 8px;
  }

  .top-row {
    display: flex;
    align-items: center;
  }

  .breadcrumb {
    min-width: 0;
    height: unset;
    .share-name {
      margin-left: 0.75em;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px; // Add spacing between actions and progress bar
  }
}
</style>
