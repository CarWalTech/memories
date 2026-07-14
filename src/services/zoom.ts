/** Row height presets (px) indexed by config.grid_zoom */
export const DESKTOP_ROW_HEIGHTS = [120, 160, 200, 260, 340];
export const MOBILE_ROW_HEIGHTS = [80, 100, 120, 150, 190];

/** Row height (px) used for list view, regardless of zoom level */
export const LIST_ROW_HEIGHT = 64;

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

/** Scale factor for a zoom level relative to ZOOM_DEFAULT (which is 1.0 / 100%) */
export function zoomScale(level: number): number {
  return DESKTOP_ROW_HEIGHTS[clampZoom(level)] / DESKTOP_ROW_HEIGHTS[ZOOM_DEFAULT];
}

/** Percentage label for a zoom level, e.g. for the View Options menu */
export function zoomLabel(level: number): string {
  return `${Math.round(zoomScale(level) * 100)}%`;
}
