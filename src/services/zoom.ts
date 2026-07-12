import { translate as t } from '@services/l10n';

/** Row height presets (px) indexed by config.grid_zoom */
export const DESKTOP_ROW_HEIGHTS = [120, 160, 200, 260, 340];
export const MOBILE_ROW_HEIGHTS = [80, 100, 120, 150, 190];

export const ZOOM_MIN = 0;
export const ZOOM_MAX = DESKTOP_ROW_HEIGHTS.length - 1;

/** Clamp a zoom level to the valid [ZOOM_MIN, ZOOM_MAX] range */
export function clampZoom(level: number): number {
  return Math.min(Math.max(level, ZOOM_MIN), ZOOM_MAX);
}

/** Human-readable label for a zoom level, e.g. for the View Options menu */
export function zoomLabel(level: number): string {
  const labels = [
    t('memories', 'Small'),
    t('memories', 'Medium'),
    t('memories', 'Large'),
    t('memories', 'X-Large'),
    t('memories', 'XX-Large'),
  ];

  return labels[clampZoom(level)];
}
