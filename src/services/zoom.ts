/** Row height presets (px) indexed by config.grid_zoom */
export const DESKTOP_ROW_HEIGHTS = [120, 160, 200, 260, 340];
export const MOBILE_ROW_HEIGHTS = [80, 100, 120, 150, 190];

/**
 * Row height presets (px) for list view, indexed by config.grid_zoom.
 * Baseline for a row showing just the filename + meta line (no tags row);
 * roughly follows DESKTOP_ROW_HEIGHTS' scale.
 */
export const LIST_ROW_HEIGHTS = [40, 52, 64, 84, 108];

/**
 * Extra height (px, at 100% zoom -- scaled like everything else via
 * zoomScale) reserved for a list row's 3rd (tags) line, on top of
 * LIST_ROW_HEIGHTS. Applied per-row, only to rows that actually render a
 * tags line, so most rows stay compact and only the ones that need the
 * extra space get it.
 */
export const LIST_TAGS_EXTRA_HEIGHT = 22;

export const ZOOM_MIN = 0;
export const ZOOM_MAX = DESKTOP_ROW_HEIGHTS.length - 1;

/** Zoom level that corresponds to 100%, i.e. the default frame/tile size */
export const ZOOM_DEFAULT = 2;

/** Clamp a zoom level to the valid [ZOOM_MIN, ZOOM_MAX] range */
export function clampZoom(level: number): number {
  return Math.min(Math.max(level, ZOOM_MIN), ZOOM_MAX);
}

/** Photo tile row height (px) for a zoom level, for matching other grids (Folders/Albums/Tags) to it */
export function rowHeight(level: number, mobile: boolean): number {
  return (mobile ? MOBILE_ROW_HEIGHTS : DESKTOP_ROW_HEIGHTS)[clampZoom(level)];
}

/** List view row height (px) for a zoom level. Pass `hasTags` for rows that render a tags line so they get the extra room. */
export function listRowHeight(level: number, hasTags: boolean = false): number {
  const base = LIST_ROW_HEIGHTS[clampZoom(level)];
  return hasTags ? base + Math.round(LIST_TAGS_EXTRA_HEIGHT * zoomScale(level)) : base;
}

/** Scale factor for a zoom level relative to ZOOM_DEFAULT (which is 1.0 / 100%) */
export function zoomScale(level: number): number {
  return DESKTOP_ROW_HEIGHTS[clampZoom(level)] / DESKTOP_ROW_HEIGHTS[ZOOM_DEFAULT];
}

/** Percentage label for a zoom level, e.g. for the View Options menu */
export function zoomLabel(level: number): string {
  return `${Math.round(zoomScale(level) * 100)}%`;
}
