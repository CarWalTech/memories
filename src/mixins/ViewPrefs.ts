import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import viewModeScope, { routeIdForName, scopeKeyFor, type ImageDisplayShape } from '@services/view-mode-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';

import type { RouteId } from '../route-ids';

/**
 * Resolves the effective View Mode + Image Properties for the current
 * route, once per container component (not per tile -- see Photo.vue,
 * Folder.vue, Cluster.vue, which receive the result as props instead of
 * resolving this themselves).
 *
 * Includes `UserConfig` itself (rather than being combined alongside it)
 * so consuming components only need `mixins: [ViewPrefs]` -- mixing in
 * `UserConfig` from two places would double-subscribe its bus listener.
 */
export default defineComponent({
  name: 'ViewPrefs',

  mixins: [UserConfig],

  created() {
    // Fire-and-forget: reactive stores resolve to sensible defaults until loaded.
    viewModeScope.init();
    imageProps.init();
  },

  computed: {
    routeId(): RouteId | null {
      return routeIdForName(this.$route.name);
    },

    scopeKey(): string | null {
      return scopeKeyFor(this.routeId, this.$route.params, this.$route.query, this.config.folders_path);
    },

    effectiveShape(): ImageDisplayShape {
      return viewModeScope.resolve(this.routeId, this.scopeKey);
    },

    effectiveImageProps(): ImagePropsBundle {
      return imageProps.resolve(this.effectiveShape, this.config);
    },
  },
});
