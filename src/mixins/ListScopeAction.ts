import { defineComponent } from 'vue';

import ViewPrefs from '@mixins/ViewPrefs';
import { listScopeTargetFor, getListScopeDef, type ListScopeTarget, type SortDir } from '@services/sort-group-defs';
import { writeScoped, type ListScope } from '@services/list-scope';

interface ListScopeLabels {
  title: string;
}

/**
 * Shared Sort by scope logic, used by `SortByAction.vue` for its primary
 * (route-derived) target. Group by, and the Folders/EmbeddedTags "subfolders"
 * / "sub-tags" tile-row sort+group, all live as nested drill-down panels
 * inside that same component rather than their own `listScopeAction()`
 * instance -- composing multiple of these mixins in one component would
 * double-mix `ViewPrefs` (double-subscribing the `UserConfig` bus listener)
 * and collide on shared computed/method names, so SortByAction.vue talks to
 * `groupScope` directly for those instead.
 *
 * Whether a write lands on the view-type default or on this specific
 * folder/album/tag isn't decided here -- `writeScoped` reads the one
 * "Remember display style" flag that governs every per-view preference (see
 * `view-prefs.ts`), so there is nothing per-scope to toggle.
 *
 * Mixes in `ViewPrefs` directly (rather than requiring consumers to compose
 * it alongside) so the `UserConfig` bus listener isn't double-subscribed --
 * same rationale as `ViewPrefs.ts` itself.
 */
export default function listScopeAction<T extends { key: string; dir: SortDir }>(scope: ListScope<T>, labels: ListScopeLabels) {
  return defineComponent({
    mixins: [ViewPrefs],

    computed: {
      effectiveTarget(): ListScopeTarget | null {
        return listScopeTargetFor(this.routeId, this.$route);
      },

      def() {
        return getListScopeDef(this.effectiveTarget);
      },

      setting(): T {
        return scope.resolve(this.effectiveTarget, this.scopeKey);
      },

      /**
       * Recognize/FaceRecognition/Places cluster lists have no sortable/groupable
       * backend fields, so Sort by/Group by would be inert there -- hidden on
       * those *list* pages only (an opened person/place still sorts/groups like
       * any other photo grid).
       */
      applicable(): boolean {
        const isClusterListRoute = this.routeId === 'Recognize' || this.routeId === 'FaceRecognition' || this.routeId === 'Places';
        return !isClusterListRoute || !!this.$route.params.name;
      },

      titleLabel(): string {
        return this.t('memories', labels.title);
      },
    },

    created() {
      scope.init();
    },

    methods: {
      write(value: T): void {
        writeScoped(scope, this.effectiveTarget, this.scopeKey, value);
      },
    },
  });
}
