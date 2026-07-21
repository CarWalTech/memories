<template>
  <transition name="sidebar-slide">
    <aside
      id="app-sidebar-vue"
      class="app-sidebar reduced"
      v-if="reducedOpen"
      @touchstart.passive="touchstart"
      @touchmove.passive="touchmove"
      @touchend.passive="touchend"
    >
      <div class="top-info" v-if="info">
        <div class="title-row">
          <NcButton
            class="close-button"
            type="tertiary-no-background"
            :aria-label="t('memories', 'Close')"
            @click="close()"
          >
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcButton>
          <h2>{{ info.basename }}</h2>
        </div>

        <div class="action-row">
          <div class="subtitle">
            <span v-if="info.size">{{ utils.humanFileSize(info.size) }}</span>
            <span v-if="info.mtime">{{ utils.getFromNowStr(new Date(info.mtime * 1000)) }}</span>
          </div>
        </div>

        <NcButton v-if="canOpenNative" class="open-native" type="tertiary" @click="openNative()">
          {{ t('memories', 'Open in Files sidebar') }}
          <template #icon> <OpenInNewIcon :size="18" /> </template>
        </NcButton>
      </div>

      <Metadata ref="metadata" />
    </aside>
  </transition>
</template>

<script lang="ts">
import Vue, { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import { registerDavProperty } from '@nextcloud/files/dav';

import Metadata from './Metadata.vue';

import * as utils from '@services/utils';

import type { IImageInfo, IPhoto } from '@typings';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import InfoSvg from '@assets/info.svg';

export default defineComponent({
  name: 'Sidebar',
  components: {
    Metadata,
    NcButton,
    CloseIcon,
    OpenInNewIcon,
  },

  data: () => ({
    nativeOpen: false,
    reducedOpen: false,
    info: null as null | IImageInfo,
    lastKnownWidth: 0,
    nativeMetadata: null as null | InstanceType<typeof Metadata>,
    utils: Object.freeze(utils),

    /** Filename of the photo currently shown in the reduced sidebar,
     * only set when the native Files sidebar is also available for it. */
    nativeFilename: null as string | null,

    /** Swipe-down-to-close tracking (mobile only) */
    swipeActive: false,
    swipeStartY: 0,
    swipeCurrentY: 0,
  }),

  computed: {
    refs() {
      return this.$refs as {
        metadata?: InstanceType<typeof Metadata>;
      };
    },

    native() {
      return globalThis.OCA?.Files?.Sidebar;
    },

    /** Whether the "Open in Files sidebar" button should be shown */
    canOpenNative(): boolean {
      return Boolean(this.native && this.nativeFilename);
    },
  },

  mounted() {
    utils.bus.on('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.on('files:sidebar:closed', this.handleNativeClose);
    utils.bus.on('memories:fragment:pop:sidebar', this.close);

    _m.sidebar = {
      open: this.open.bind(this),
      close: this.close.bind(this),
      isOpen: this.isOpen.bind(this),
      setTab: this.setTab.bind(this),
      invalidateUnless: this.invalidateUnless.bind(this),
      getWidth: this.getWidth.bind(this),
    };

    // Register native tab after DOMContentLoaded
    utils.onDOMLoaded(this.registerNative.bind(this));

    // Remove after https://github.com/nextcloud/server/pull/51077
    registerDavProperty('nc:share-attributes');
  },

  beforeDestroy() {
    utils.bus.off('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.off('files:sidebar:closed', this.handleNativeClose);
    utils.bus.off('memories:fragment:pop:sidebar', this.close);
  },

  methods: {
    async open(photo: IPhoto | number, filename?: string, useNative = false) {
      if (!this.reducedOpen && this.native && !photo) {
        // Explicit request to open the native sidebar directly (e.g. sharing UI)
        this.nativeInit();
        this.native?.setFullScreenMode?.(true);
        this.native?.open(filename);
      } else {
        // Open the Memories-styled reduced sidebar. The native Files sidebar
        // (sharing, activity, comments, etc.) stays one click away via the
        // "Open in Files sidebar" button, instead of replacing this view.
        this.reducedOpen = true;
        this.nativeFilename = useNative ? (filename ?? null) : null;
        await this.$nextTick();

        // Update metadata compoenent
        this.info = (await this.refs.metadata?.update(photo)) ?? null;
        if (!this.info) return; // failure or state change
        this.handleOpen();
      }
    },

    /** Close the reduced sidebar and open the full native Files sidebar instead */
    openNative() {
      if (!this.nativeFilename) return;
      const filename = this.nativeFilename;
      this.close();
      this.open(0, filename, true);
    },

    async close() {
      if (this.nativeOpen) {
        this.native?.close();
      } else {
        if (this.reducedOpen) {
          this.reducedOpen = false;
          await this.$nextTick();
        }
        this.handleClose();
      }
    },

    isOpen() {
      return this.reducedOpen || this.nativeOpen;
    },

    /** Start of a potential swipe-down-to-close gesture (mobile only) */
    touchstart(e: TouchEvent) {
      if (!utils.isMobile()) return;

      // Only take over the gesture when already scrolled to the top,
      // so normal scrolling within the sidebar isn't hijacked.
      const el = e.currentTarget as HTMLElement;
      if (el.scrollTop > 0) return;

      this.swipeStartY = this.swipeCurrentY = e.touches[0].clientY;
      this.swipeActive = true;
    },

    touchmove(e: TouchEvent) {
      if (!this.swipeActive) return;
      this.swipeCurrentY = e.touches[0].clientY;

      // Follow the finger downward (never upward -- already fully open)
      const delta = Math.max(0, this.swipeCurrentY - this.swipeStartY);
      const el = e.currentTarget as HTMLElement;
      el.style.transition = 'none';
      el.style.transform = delta ? `translateY(${delta}px)` : '';
    },

    touchend(e: TouchEvent) {
      if (!this.swipeActive) return;
      this.swipeActive = false;

      const el = e.currentTarget as HTMLElement;

      if (this.swipeCurrentY - this.swipeStartY > 80) {
        // Past the threshold: let go and let the leave-transition take over
        el.style.transition = '';
        el.style.transform = '';
        this.close();
      } else {
        // Short of the threshold: animate back to fully open
        el.style.transition = 'transform 0.2s ease';
        el.style.transform = '';
      }
    },

    setTab(tab: string) {
      this.native?.setActiveTab(tab);
    },

    invalidateUnless(fileid: number) {
      this.refs.metadata?.invalidateUnless(fileid);
      this.nativeMetadata?.invalidateUnless(fileid);
    },

    getWidth() {
      const sidebar = document.getElementById('app-sidebar-vue');
      this.lastKnownWidth = sidebar?.offsetWidth || this.lastKnownWidth;
      return (this.lastKnownWidth || 2) - 2;
    },

    handleClose() {
      utils.bus.emit('memories:sidebar:closed', null);
      utils.fragment.pop(utils.fragment.types.sidebar);
    },

    handleOpen() {
      // Stop sidebar typing from leaking outside
      const sidebar = document.getElementById('app-sidebar-vue');
      sidebar?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === 'Tab') {
          e.stopPropagation();
          return;
        }

        const element = e.target as HTMLElement;
        if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA' || element.isContentEditable) {
          e.stopPropagation();
          return;
        }
      });

      // Emit event
      utils.bus.emit('memories:sidebar:opened', null);

      // Use fragment navigation only on mobile
      if (utils.isMobile()) {
        utils.fragment.push(utils.fragment.types.sidebar);
      }
    },

    handleNativeOpen() {
      this.nativeOpen = true;
      this.handleOpen();
    },

    handleNativeClose() {
      this.nativeOpen = false;
      this.native?.setFullScreenMode?.(false);
      this.handleClose();
    },

    nativeInit() {
      // Initializations for native sidebar, only if required
      if (globalThis.OCA?.Files?.App?.fileList?.filesClient) return;

      // Initialize the object
      globalThis.OCA ??= {};
      globalThis.OCA.Files ??= {};

      // TODO: remove when we have a proper fileinfo standalone library
      // original scripts are loaded from
      // https://github.com/nextcloud/server/blob/5bf3d1bb384da56adbf205752be8f840aac3b0c5/lib/private/legacy/template.php#L120-L122
      const filesClient = (<any>globalThis.OC.Files).getClient();
      Object.assign(globalThis.OCA.Files, { App: { fileList: { filesClient } } }, globalThis.OCA.Files);
    },

    /** Register the Nextcloud Sidebar component */
    async registerNative() {
      // Sidebar script is loaded only after the main script
      // so we need to wait for it to be available
      await new Promise((resolve) => setTimeout(resolve, 200));

      // Pass router to the component
      const router = this.$router;

      // Component instance
      let component: any;
      const self = this;

      // Register sidebar tab
      globalThis.OCA?.Files?.Sidebar?.registerTab(
        new globalThis.OCA.Files.Sidebar.Tab({
          id: 'memories-metadata',
          name: this.t('memories', 'Info'),
          icon: 'icon-details',
          iconSvg: window.atob(InfoSvg.split(',')[1]), // base64 to svg

          mount(el: HTMLElement, fileInfo: { id: string | number }, context: any) {
            component?.$destroy?.();
            component = new Vue({ render: (h) => h(Metadata), router });
            component.$mount(el);

            self.nativeMetadata = component.$children[0];
            self.nativeMetadata?.update(Number(fileInfo.id));
          },

          update(fileInfo: { id: string | number }) {
            self.nativeMetadata?.update(Number(fileInfo.id));
          },

          destroy() {
            component?.$destroy?.();
            component = null;
            self.nativeMetadata = null;
          },
        }),
      );

      // NC29+ disable tags in sidebar by default
      globalThis.OCA?.Files?.Sidebar?.setShowTagsDefault?.(false);
    },
  },
});
</script>

<style scoped lang="scss">
#app-sidebar-vue {
  position: fixed;
  top: 0;
  right: 0;
  width: 34vw;
  min-width: 380px;
  height: 100% !important;
  z-index: 2525;
  padding: 10px;
  background-color: var(--color-main-background);
  border-left: 1px solid var(--color-border);

  @media (max-width: 512px) {
    width: 100vw;
    min-width: unset;
  }

  .top-info {
    padding: 10px;
    padding-right: 0;
  }

  .title-row {
    display: flex;
    align-items: center;
    gap: 4px;

    .close-button {
      flex-shrink: 0;
      margin-left: -10px; // flush with the sidebar's left edge
    }

    h2 {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      margin: 0;
    }
  }

  .action-row {
    margin-top: 4px;
  }

  .subtitle {
    color: var(--color-text-lighter);
    > span {
      margin-right: 4px;
    }
  }

  .open-native {
    margin-top: 8px;
    width: 100%;
    justify-content: flex-start;
  }
}

// Slide up/down to match the swipe-up-to-open / swipe-down-to-close
// gestures, mobile only (desktop keeps the instant show/hide).
@media (max-width: 512px) {
  .sidebar-slide-enter-active,
  .sidebar-slide-leave-active {
    transition: transform 0.25s ease;
  }

  .sidebar-slide-enter,
  .sidebar-slide-leave-to {
    transform: translateY(100%);
  }
}
</style>

<style lang="scss">
// Prevent sidebar from becoming too big
#app-sidebar-vue {
  max-width: 480px !important;
  position: fixed !important;

  &.reduced {
    overflow-y: auto;
  }

  @media (max-width: 512px) {
    max-width: unset !important;
  }
}

// Hack to put the floating dropdown menu above the
// sidebar ... this may have unintended side effects
.vs__dropdown-menu--floating {
  z-index: 2526;
}

// Make metadata tab scrollbar thin
#tab-memories-metadata,
.app-sidebar.reduced {
  scrollbar-width: thin;
  &::-webkit-scrollbar {
    width: 5px;
  }
  &::-webkit-scrollbar-track {
    background: transparent;
  }
}
</style>
