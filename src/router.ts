import Router, { type Route, type RouteConfig } from 'vue-router';
import Vue from 'vue';

import { generateUrl } from '@nextcloud/router';

import Timeline from '@components/views/Timeline.vue';
import Explore from '@components/views/Explore.vue';
import SplitTimeline from '@components/views/SplitTimeline.vue';
import ClusterView from '@components/views/ClusterView.vue';
import NativeXSetup from '@native/Setup.vue';

import { translate as t } from '@services/l10n';
import { constants as c } from '@services/utils';

import { ROUTE_NAMES, type RouteId } from './route-ids';

// Routes are defined here
export type { RouteId };

export const routes: { [key in RouteId]: RouteConfig } = {
  Base: {
    path: '/',
    component: Timeline,
    name: ROUTE_NAMES.Base,
    props: (route: Route) => ({ rootTitle: t('memories', 'Timeline') }),
  },

  Folders: {
    path: '/folders/:path*',
    component: Timeline,
    name: ROUTE_NAMES.Folders,
    props: (route: Route) => ({ rootTitle: t('memories', 'Folders') }),
  },

  AllPhotos: {
    path: '/all',
    component: Timeline,
    name: ROUTE_NAMES.AllPhotos,
    props: (route: Route) => ({ rootTitle: t('memories', 'All Photos') }),
  },

  Favorites: {
    path: '/favorites',
    component: Timeline,
    name: ROUTE_NAMES.Favorites,
    props: (route: Route) => ({ rootTitle: t('memories', 'Favorites') }),
  },

  Videos: {
    path: '/videos',
    component: Timeline,
    name: ROUTE_NAMES.Videos,
    props: (route: Route) => ({ rootTitle: t('memories', 'Videos') }),
  },

  Albums: {
    path: '/albums/:user?/:name?',
    component: ClusterView,
    name: ROUTE_NAMES.Albums,
    props: (route: Route) => ({ rootTitle: t('memories', 'Albums') }),
  },

  Archive: {
    path: '/archive',
    component: Timeline,
    name: ROUTE_NAMES.Archive,
    props: (route: Route) => ({ rootTitle: t('memories', 'Archive') }),
  },

  ThisDay: {
    path: '/thisday',
    component: Timeline,
    name: ROUTE_NAMES.ThisDay,
    props: (route: Route) => ({ rootTitle: t('memories', 'On this day') }),
  },

  Recognize: {
    path: '/recognize/:user?/:name?',
    component: ClusterView,
    name: ROUTE_NAMES.Recognize,
    props: (route: Route) => ({ rootTitle: t('memories', 'People') }),
  },

  FaceRecognition: {
    path: '/facerecognition/:user?/:name?',
    component: ClusterView,
    name: ROUTE_NAMES.FaceRecognition,
    props: (route: Route) => ({ rootTitle: t('memories', 'People') }),
  },

  Places: {
    path: '/places/:name*',
    component: ClusterView,
    name: ROUTE_NAMES.Places,
    props: (route: Route) => ({ rootTitle: t('memories', 'Places') }),
  },

  Tags: {
    path: '/tags/:name*',
    component: ClusterView,
    name: ROUTE_NAMES.Tags,
    props: (route: Route) => ({ rootTitle: t('memories', 'Collaborative Tags') }),
  },

  EmbeddedTags: {
    path: '/embeddedtags/:path*',
    component: Timeline,
    name: ROUTE_NAMES.EmbeddedTags,
    props: (route: Route) => ({ rootTitle: t('memories', 'Embedded Tags') }),
  },

  FolderShare: {
    path: '/s/:token/:path*',
    component: Timeline,
    name: ROUTE_NAMES.FolderShare,
    props: (route: Route) => ({ rootTitle: t('memories', 'Shared Folder') }),
  },

  AlbumShare: {
    path: '/a/:token',
    component: Timeline,
    name: ROUTE_NAMES.AlbumShare,
    props: (route: Route) => ({ rootTitle: t('memories', 'Shared Album') }),
  },

  Map: {
    path: '/map',
    component: SplitTimeline,
    name: ROUTE_NAMES.Map,
    props: (route: Route) => ({ rootTitle: t('memories', 'Map') }),
  },

  Explore: {
    path: '/explore',
    component: Explore,
    name: ROUTE_NAMES.Explore,
    props: (route: Route) => ({ rootTitle: t('memories', 'Explore') }),
  },

  NxSetup: {
    path: '/nxsetup',
    component: NativeXSetup,
    name: ROUTE_NAMES.NxSetup,
    props: (route: Route) => ({ rootTitle: t('memories', 'Setup') }),
  },
};

Vue.use(Router);

export default new Router({
  mode: 'history',
  // if index.php is in the url AND we got this far, then it's working:
  // let's keep using index.php in the url
  base: generateUrl('/apps/memories'),
  linkActiveClass: 'active',
  routes: Object.values(routes),
});

// Define global route checkers
// Injected through globals.d.ts
export type GlobalRouteCheckers = {
  [key in `routeIs${RouteId}`]: boolean;
} & {
  // Extra, special route checkers
  routeIsPublic: boolean;
  routeIsPeople: boolean;
  routeIsRecognizeUnassigned: boolean;
  routeIsPlacesUnassigned: boolean;
  routeIsCluster: boolean;
};

// Implement getters for route checkers
function defineRouteChecker(key: keyof GlobalRouteCheckers, condition: (route?: Route) => boolean) {
  Object.defineProperty(Vue.prototype, key, {
    get() {
      return condition(this.$route);
    },
  });
}

// Build basic route checkers
for (const [key, value] of Object.entries(routes)) {
  const key_ = key as RouteId;
  defineRouteChecker(`routeIs${key_}`, (route) => route?.name === value.name);
}

// Extra route checkers
defineRouteChecker('routeIsPublic', (route) => route?.name?.endsWith('-share') ?? false);
defineRouteChecker('routeIsPeople', (route) =>
  [routes.Recognize.name, routes.FaceRecognition.name].includes(route?.name ?? ''),
);
defineRouteChecker(
  'routeIsRecognizeUnassigned',
  (route) => route?.name === routes.Recognize.name && route!.params.name === c.FACE_NULL,
);
defineRouteChecker(
  'routeIsPlacesUnassigned',
  (route) => route?.name === routes.Places.name && route!.params.name === c.PLACES_NULL,
);
defineRouteChecker('routeIsCluster', (route) =>
  [
    routes.Albums.name,
    routes.Recognize.name,
    routes.FaceRecognition.name,
    routes.Places.name,
    routes.Tags.name,
  ].includes(route?.name ?? ''),
);
