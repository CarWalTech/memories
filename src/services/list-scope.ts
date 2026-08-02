import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import staticConfig from '@services/static-config';

import {
  getListScopeDef,
  routeIdForTarget,
  type ListScopeTarget,
  type SortSetting,
  type GroupSetting,
  type SortDir,
} from '@services/sort-group-defs';
import { isRemembered } from '@services/view-mode-scope';

export type { ListScopeTarget, SortSetting, GroupSetting };
export { listScopeTargetFor } from '@services/sort-group-defs';
export { routeIdForName, scopeKeyFor } from '@services/view-mode-scope';

interface ScopeData<T> {
  /** Per-target base, e.g. "every Albums list page defaults to sorting by name". */
  view: Partial<Record<ListScopeTarget, T>>;
  /**
   * Per-specific-item override; key is `${target}:${scopeKey}`. Only consulted
   * while that view is remembered (see `isRemembered`) -- unlike the master
   * `view_mode_scope` store, presence of a key here is NOT itself the
   * toggle-on state, so a value left behind by a since-forgotten view can't
   * silently keep winning.
   */
  item: Record<string, T>;
}

const emptyData = <T,>(): ScopeData<T> => ({ view: {}, item: {} });

/**
 * Generic scoped setting store, structurally identical to `ViewModeScope`
 * (view-type default + optional per-item override, persisted as one JSON
 * blob), but parameterized so it can back both Sort by (`sortScope`) and
 * Group by (`groupScope`) as two independent instances/config keys.
 */
export class ListScope<T extends { key: string; dir: SortDir }> {
  private data = Vue.observable<ScopeData<T>>(emptyData<T>());
  private initialized = false;

  constructor(
    private configKey: 'sort_scope' | 'group_scope',
    private remoteName: string,
    private getDefault: (target: ListScopeTarget | null) => T,
  ) {}

  public async init() {
    if (this.initialized) return;
    this.initialized = true;

    const raw = await staticConfig.get(this.configKey);
    try {
      const parsed = JSON.parse(raw || '{}');
      this.data.view = parsed.view ?? {};
      this.data.item = parsed.item ?? {};
    } catch {
      // ignore malformed blob, start fresh
    }
  }

  public resolve(target: ListScopeTarget | null, scopeKey: string | null): T {
    if (target == null) return this.getDefault(target);

    if (scopeKey != null && isRemembered(routeIdForTarget(target), scopeKey)) {
      const itemValue = this.data.item[`${target}:${scopeKey}`];
      if (itemValue != null) return itemValue;
    }

    return this.data.view[target] ?? this.getDefault(target);
  }

  public setView(target: ListScopeTarget, value: T) {
    Vue.set(this.data.view, target, value);
    this.persist();
  }

  public clearItem(target: ListScopeTarget, scopeKey: string) {
    Vue.delete(this.data.item, `${target}:${scopeKey}`);
    this.persist();
  }

  public setItem(target: ListScopeTarget, scopeKey: string, value: T) {
    Vue.set(this.data.item, `${target}:${scopeKey}`, value);
    this.persist();
  }

  private persist() {
    const value = JSON.stringify(this.data);
    staticConfig.setLs(this.configKey, value);
    axios.put(API.CONFIG(this.remoteName), { value }).catch(() => {
      // best-effort; local reactive state already reflects the change
    });
    bus.emit('memories:user-config-changed', { setting: this.configKey, value });
  }
}

export const sortScope = new ListScope<SortSetting>('sort_scope', 'sortScope', (target) => getListScopeDef(target).defaultSort);
export const groupScope = new ListScope<GroupSetting>('group_scope', 'groupScope', (target) => getListScopeDef(target).defaultGroup);

/**
 * Write a scoped setting to the right layer: a remembered folder/album/tag
 * keeps its own value, everything else moves that view-type's default.
 * Shared by every Sort by / Group by write path (primary content and the
 * Folders/EmbeddedTags tile rows alike).
 */
export function writeScoped<T extends { key: string; dir: SortDir }>(
  scope: ListScope<T>,
  target: ListScopeTarget | null,
  scopeKey: string | null,
  value: T,
): void {
  if (target == null) return;

  if (scopeKey != null && isRemembered(routeIdForTarget(target), scopeKey)) {
    scope.setItem(target, scopeKey, value);
  } else {
    scope.setView(target, value);
  }
}
