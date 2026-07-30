import { defineComponent } from 'vue';

import ViewPrefs from '@mixins/ViewPrefs';
import { listScopeTargetFor, getListScopeDef, type ListScopeTarget, type SortDir } from '@services/sort-group-defs';
import type { ListScope } from '@services/list-scope';

interface ListScopeLabels {
  title: string;
  itemToggle: {
    folder: string;
    album: string;
    tag: string;
  };
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

      showItemToggle(): boolean {
        return this.scopeKey != null && this.itemToggleLabel != null;
      },

      itemToggleLabel(): string | null {
        switch (this.routeId) {
          case 'Folders':
          case 'FolderShare':
            return this.t('memories', labels.itemToggle.folder);
          case 'Albums':
          case 'AlbumShare':
            return this.t('memories', labels.itemToggle.album);
          case 'Tags':
          case 'EmbeddedTags':
            return this.t('memories', labels.itemToggle.tag);
          default:
            return null;
        }
      },

      itemToggleOn(): boolean {
        return scope.isEnabled(this.effectiveTarget, this.scopeKey);
      },
    },

    created() {
      scope.init();
    },

    methods: {
      toggleItemScope(checked: boolean): void {
        if (this.effectiveTarget == null || this.scopeKey == null) return;

        if (checked) {
          scope.enable(this.effectiveTarget, this.scopeKey, this.setting);
        } else {
          scope.disable(this.effectiveTarget, this.scopeKey);
        }
      },

      write(value: T): void {
        if (this.effectiveTarget == null) return;

        if (this.itemToggleOn && this.scopeKey != null) {
          scope.setItem(this.effectiveTarget, this.scopeKey, value);
        } else {
          scope.setView(this.effectiveTarget, value);
        }
      },
    },
  });
}
