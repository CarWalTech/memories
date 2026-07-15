import type { GroupSetting } from '@services/sort-group-defs';

export interface NameGroup<T> {
  /** null means "ungrouped" -- no header should be rendered for this bucket. */
  label: string | null;
  items: T[];
}

/**
 * Bucket key is the literal first character of the name (case-insensitive for
 * letters, no special-casing for punctuation/symbols -- see sort-group-defs.ts).
 */
function firstCharBucket(name: string): string {
  const ch = (name ?? '').charAt(0);
  return ch ? ch.toLocaleUpperCase() : '#';
}

/**
 * Partition an already-sorted, named list (clusters, folders, ...) into
 * "Group by: Name" buckets. Items keep whatever order they arrived in within
 * each bucket (i.e. the caller's Sort by order is preserved) -- only the
 * buckets themselves are ordered alphabetically, reversed when
 * `group.dir === 'desc'`.
 */
export function groupByName<T extends { name: string }>(items: T[], group: GroupSetting): NameGroup<T>[] {
  if (group.key !== 'name') {
    return [{ label: null, items }];
  }

  const buckets = new Map<string, T[]>();
  for (const item of items) {
    const label = firstCharBucket(item.name);
    const bucket = buckets.get(label);
    if (bucket) {
      bucket.push(item);
    } else {
      buckets.set(label, [item]);
    }
  }

  const labels = [...buckets.keys()].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
  if (group.dir === 'desc') labels.reverse();

  return labels.map((label) => ({ label, items: buckets.get(label)! }));
}
