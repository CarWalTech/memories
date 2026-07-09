<template>
  <div id="mobile-nav">
    <router-link
      v-for="link in links"
      :key="link.name"
      :to="{ name: link.name }"
      @click.native="linkClick"
      replace
      exact-path
    >
      <component :is="link.icon" :size="22" />
      {{ link.text }}
    </router-link>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as nativex from '@native';

import { translate as t } from '@services/l10n';
import { availableNavState, withRequiredNavItem } from '@services/nav-items';
import UserConfig from '@mixins/UserConfig';

import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';

// Fallback shown if the user's selection is empty or entirely unavailable
// (e.g. an admin disabled every feature the user had picked).
const FALLBACK_LINKS = [
  { name: 'timeline', icon: ImageMultipleIcon, text: t('memories', 'Photos') },
  { name: 'explore', icon: SearchIcon, text: t('memories', 'Explore') },
  { name: 'albums', icon: AlbumIcon, text: t('memories', 'Albums') },
];

export default defineComponent({
  name: 'MobileNav',

  mixins: [UserConfig],

  computed: {
    links() {
      const byName = new Map(availableNavState.items.map((item) => [item.name, item]));
      const items = (this.config.mobile_nav_items ?? [])
        .map((name) => byName.get(name))
        .filter((item): item is (typeof availableNavState.items)[number] => !!item);

      const links = withRequiredNavItem(items).map((item) => ({
        name: item.name,
        icon: item.icon,
        text: item.shortTitle ?? item.title,
      }));

      return links.length ? links : FALLBACK_LINKS;
    },
  },

  methods: {
    linkClick() {
      nativex.playTouchSound();
    },
  },
});
</script>

<style lang="scss">
:root {
  // iOS PWA shenanigans
  --mobile-nav-height: calc(58px + max(calc(env(safe-area-inset-bottom) - 15px), 0px));
}

// Show correct nav depending on screen size
#mobile-nav {
  contain: strict;
  display: none;
}

@media (max-width: 768px) {
  #content-vue > .app-navigation {
    display: none;
  }

  #mobile-nav {
    display: flex;
  }

  // Make space for the nav
  #app-content-vue > .router-outlet.has-nav {
    height: calc(100% - var(--mobile-nav-height));
  }
}
</style>

<style lang="scss" scoped>
#mobile-nav {
  background-color: var(--color-main-background);
  height: var(--mobile-nav-height);
  text-align: center;
  padding: 8px;
  padding-top: 3px;
  font-size: 0.9em;
  overflow: hidden;

  :deep a {
    flex: 1 1 0px;
    opacity: 0.75;

    span.material-design-icon {
      border-radius: 20px;
      padding: 4px;
      max-width: 70px;
      margin: 0 auto;
    }

    &.router-link-exact-active {
      opacity: 1;

      span.material-design-icon {
        background: var(--color-primary-element-light);
      }
    }
  }
}
</style>
