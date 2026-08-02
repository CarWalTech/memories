import viewModeScope, { isRemembered, type RouteId, type ImageDisplayShape } from '@services/view-mode-scope';
import { sortScope, groupScope } from '@services/list-scope';
import imageProps, { type ImagePropsBundle } from '@services/image-props';
import { listScopeTargetFor, type ListScopeTarget } from '@services/sort-group-defs';

export { isRemembered };

/**
 * Orchestrates the single "Remember display style" toggle across every store
 * that holds a per-view preference. The stores stay independent (each owns its
 * own config blob and knows nothing about the others) -- this is the one module
 * that fans a toggle out across all of them, so it is also the only place that
 * imports all four.
 */

/** The extra, independently-sortable tile row some routes show alongside their main content. */
function tilesTargetFor(routeId: RouteId | null): ListScopeTarget | null {
  if (routeId === 'Folders') return 'FoldersTiles';
  if (routeId === 'EmbeddedTags') return 'EmbeddedTagsTiles';
  return null;
}

/** Every Sort/Group target whose settings belong to this route's page. */
function targetsFor(routeId: RouteId | null, route: { params: Record<string, any> }): ListScopeTarget[] {
  return [listScopeTargetFor(routeId, route), tilesTargetFor(routeId)].filter((t): t is ListScopeTarget => t != null);
}

/**
 * Start remembering this folder/album/tag. Seeds every store's item entry from
 * the values currently in effect, so ticking the box freezes what the user is
 * looking at rather than silently leaving it to drift with the view-type
 * defaults later.
 */
export function rememberViewPrefs(
  routeId: RouteId,
  scopeKey: string,
  route: { params: Record<string, any> },
  current: { shape: ImageDisplayShape; imageProps: ImagePropsBundle },
): void {
  // Must come first: every other store gates its item lookups on this flag.
  viewModeScope.enable(routeId, scopeKey, current.shape);

  for (const target of targetsFor(routeId, route)) {
    sortScope.setItem(target, scopeKey, sortScope.resolve(target, scopeKey));
    groupScope.setItem(target, scopeKey, groupScope.resolve(target, scopeKey));
  }

  imageProps.setItemBundle(routeId, scopeKey, current.imageProps);
}

/**
 * Stop remembering this folder/album/tag: it goes back to following its
 * view-type defaults, and its stored values are dropped rather than left
 * behind to reappear if it is ever remembered again.
 */
export function forgetViewPrefs(routeId: RouteId, scopeKey: string, route: { params: Record<string, any> }): void {
  for (const target of targetsFor(routeId, route)) {
    sortScope.clearItem(target, scopeKey);
    groupScope.clearItem(target, scopeKey);
  }

  imageProps.clearItem(routeId, scopeKey);

  // Last: clearing the master flag is what actually flips the view back.
  viewModeScope.disable(routeId, scopeKey);
}
