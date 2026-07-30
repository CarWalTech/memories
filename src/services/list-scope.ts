import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import staticConfig from '@services/static-config';

import { getListScopeDef, type ListScopeTarget, type SortSetting, type GroupSetting, type SortDir } from '@services/sort-group-defs';

export type { ListScopeTarget, SortSetting, GroupSetting };
export { listScopeTargetFor } from '@services/sort-group-defs';
export { routeIdForName, scopeKeyFor } from '@services/view-mode-scope';

interface ScopeData<T> {
  /** Per-target base, e.g. "every Albums list page defaults to sorting by name". */
  view: Partial<Record<ListScopeTarget, T>>;
  /** Per-specific-item override; key is `${target}:${scopeKey}`. Presence of a key IS the toggle-on state. */
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

  public isEnabled(target: ListScopeTarget | null, scopeKey: string | null): boolean {
    if (target == null || scopeKey == null) return false;
    return this.data.item[`${target}:${scopeKey}`] != null;
  }

  public resolve(target: ListScopeTarget | null, scopeKey: string | null): T {
    if (target == null) return this.getDefault(target);

    if (scopeKey != null) {
      const itemValue = this.data.item[`${target}:${scopeKey}`];
      if (itemValue != null) return itemValue;
    }

    return this.data.view[target] ?? this.getDefault(target);
  }

  public setView(target: ListScopeTarget, value: T) {
    Vue.set(this.data.view, target, value);
    this.persist();
  }

  public enable(target: ListScopeTarget, scopeKey: string, initialValue: T) {
    Vue.set(this.data.item, `${target}:${scopeKey}`, initialValue);
    this.persist();
  }

  public disable(target: ListScopeTarget, scopeKey: string) {
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
