import { translate as t } from '@services/l10n';

import type { SortKey, GroupKey, SortDir, ListScopeDef } from '@services/sort-group-defs';

import SortAlphaIcon from 'vue-material-design-icons/SortAlphabeticalVariant.vue';
import FileSizeIcon from 'vue-material-design-icons/Harddisk.vue';
import UpdatedIcon from 'vue-material-design-icons/ClockOutline.vue';
import CreatedIcon from 'vue-material-design-icons/CalendarOutline.vue';
import CountIcon from 'vue-material-design-icons/Counter.vue';
import GroupDayIcon from 'vue-material-design-icons/CalendarTodayOutline.vue';
import GroupWeekIcon from 'vue-material-design-icons/CalendarWeekOutline.vue';
import GroupMonthIcon from 'vue-material-design-icons/CalendarMonthOutline.vue';
import GroupYearIcon from 'vue-material-design-icons/CalendarBlankOutline.vue';

import SortAlphaAscIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SortAlphaDescIcon from 'vue-material-design-icons/SortAlphabeticalDescending.vue';
import SortNumericAscIcon from 'vue-material-design-icons/SortNumericAscending.vue';
import SortNumericDescIcon from 'vue-material-design-icons/SortNumericDescending.vue';
import SortClockAscIcon from 'vue-material-design-icons/SortClockAscendingOutline.vue';
import SortClockDescIcon from 'vue-material-design-icons/SortClockDescendingOutline.vue';
import SortCalendarAscIcon from 'vue-material-design-icons/SortCalendarAscending.vue';
import SortCalendarDescIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

/**
 * Icons and labels shared by every Sort by / Group by panel -- the primary
 * content's (`SortByAction.vue`) and the Folders/EmbeddedTags tile rows'
 * (`ViewModeAction.vue`), which render the same option lists against
 * different targets.
 */

/** Icon per Sort by key, shown next to each option in a Sort by panel. */
const SORT_KEY_ICONS: Record<SortKey, unknown> = {
  name: SortAlphaIcon,
  size: FileSizeIcon,
  updated: UpdatedIcon,
  created: CreatedIcon,
  count: CountIcon,
};

/** Combined key+direction icon for a "Sort by" trigger -- mirrors the type distinctions above (alpha/numeric/clock/calendar) instead of a generic up/down arrow. */
const SORT_KEY_DIR_ICONS: Record<SortKey, { asc: unknown; desc: unknown }> = {
  name: { asc: SortAlphaAscIcon, desc: SortAlphaDescIcon },
  size: { asc: SortNumericAscIcon, desc: SortNumericDescIcon },
  updated: { asc: SortClockAscIcon, desc: SortClockDescIcon },
  created: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  count: { asc: SortNumericAscIcon, desc: SortNumericDescIcon },
};

export const GROUP_KEY_LABELS: Record<Exclude<GroupKey, 'none'>, string> = {
  day: 'Day',
  week: 'Week',
  month: 'Month',
  year: 'Year',
  name: 'Name',
};

/** Icon per Group by key (excluding 'none', which always uses NoGroupIcon directly). */
const GROUP_KEY_ICONS: Record<Exclude<GroupKey, 'none'>, unknown> = {
  day: GroupDayIcon,
  week: GroupWeekIcon,
  month: GroupMonthIcon,
  year: GroupYearIcon,
  name: SortAlphaIcon,
};

/** Combined key+direction icon for a "Group by" trigger -- same idea as SORT_KEY_DIR_ICONS. */
const GROUP_KEY_DIR_ICONS: Record<Exclude<GroupKey, 'none'>, { asc: unknown; desc: unknown }> = {
  day: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  week: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  month: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  year: { asc: SortCalendarAscIcon, desc: SortCalendarDescIcon },
  name: { asc: SortAlphaAscIcon, desc: SortAlphaDescIcon },
};

export function sortKeyIcon(key: SortKey) {
  return SORT_KEY_ICONS[key];
}

export function sortTriggerIconFor(setting: { key: SortKey; dir: SortDir }) {
  return SORT_KEY_DIR_ICONS[setting.key][setting.dir];
}

export function groupKeyIcon(key: Exclude<GroupKey, 'none'>) {
  return GROUP_KEY_ICONS[key];
}

export function groupTriggerIconFor(setting: { key: GroupKey; dir: SortDir }) {
  if (setting.key === 'none') return null;
  return GROUP_KEY_DIR_ICONS[setting.key as Exclude<GroupKey, 'none'>][setting.dir];
}

/** The Group by options a target offers, translated. 'none' is offered separately (it always leads the list). */
export function groupKeyOptsFor(def: ListScopeDef): { key: Exclude<GroupKey, 'none'>; label: string }[] {
  return def.groupKeys.map((key) => ({ key, label: t('memories', GROUP_KEY_LABELS[key]) }));
}

/** Label for a Group by setting, including the implicit 'none'. */
export function groupLabelFor(setting: { key: GroupKey }): string {
  if (setting.key === 'none') return t('memories', 'None');
  return t('memories', GROUP_KEY_LABELS[setting.key as Exclude<GroupKey, 'none'>]);
}
