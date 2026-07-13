import Vue from 'vue';
import type { Component } from 'vue';

/** A single navigation destination available to the current user (sidebar + mobile bottom bar). */
export interface AvailableNavItem {
  name: string; // matches vue-router route `name`
  title: string;
  /** Shorter label for the space-constrained mobile bottom bar; falls back to `title` if unset. */
  shortTitle?: string;
  icon: Component;
  /** Other route names that should also highlight this nav entry as active. */
  activeNames?: string[];
}

/**
 * Reactive singleton populated by App.vue's refreshNav(), which already computes the
 * config/permission-filtered list of nav items for the sidebar. Reusing it here avoids
 * duplicating that filtering logic in MobileNav.vue / Settings.vue.
 */
export const availableNavState = Vue.observable({
  items: [] as AvailableNavItem[],
});

/** This item cannot be removed from the mobile bottom bar. */
export const REQUIRED_NAV_ITEM = 'explore';

/**
 * Guarantees `REQUIRED_NAV_ITEM` is present, appending it if a user's stored
 * selection predates this rule (or was otherwise left without it).
 */
export function withRequiredNavItem(items: AvailableNavItem[]): AvailableNavItem[] {
  if (items.some((item) => item.name === REQUIRED_NAV_ITEM)) {
    return items;
  }

  const required = availableNavState.items.find((item) => item.name === REQUIRED_NAV_ITEM);
  return required ? [...items, required] : items;
}
