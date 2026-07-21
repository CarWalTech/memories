import type { FilterField, FilterGroup, FilterLeaf, FilterNode } from '@typings';

/**
 * The filter expression builder UI works in two tiers:
 *  - A "row" is one user-facing condition: a single field, one or more
 *    values of that field, a match mode (all/any/none), and a NOT toggle.
 *    Most real filters are rows -- e.g. "tag is Sunset OR Beach".
 *  - A "group" is arbitrary nesting of rows and sub-groups with its own
 *    AND/OR operator, for combining different fields together (e.g.
 *    "tag:Sunset OR person:Alice").
 *
 * These two functions convert between a row's UI state and the raw
 * FilterNode tree the backend expects (see FilterExpression.php), so a
 * FilterNode that happens to match the row shape (a bare leaf, or an
 * and/or/not-of-or group of leaves all sharing one field) can be edited as
 * a single compact row instead of a generic nested group.
 */
export type FilterRowMode = 'all' | 'any' | 'none';

export type FilterRow = {
  field: FilterField;
  values: string[];
  mode: FilterRowMode;
  negated: boolean;
};

export function defaultRow(field: FilterField = 'tag'): FilterRow {
  return { field, values: [], mode: 'all', negated: false };
}

/**
 * If $node matches the row shape, return its row representation; otherwise
 * null (the caller should fall back to rendering it as a generic group).
 */
export function asRow(node: FilterNode): FilterRow | null {
  let n = node;
  let negated = false;

  if (n.kind === 'group' && n.op === 'not') {
    negated = true;
    n = n.children[0];
  }

  if (n.kind === 'leaf') {
    return { field: n.field, values: [n.value], mode: 'all', negated };
  }

  if (n.kind === 'group' && ('and' === n.op || 'or' === n.op)) {
    const children = n.children;
    if (children.length > 0 && children.every((c): c is FilterLeaf => 'leaf' === c.kind && c.field === (children[0] as FilterLeaf).field)) {
      const field = (children[0] as FilterLeaf).field;
      const values = children.map((c) => (c as FilterLeaf).value);

      // NOT(OR(...)) is the "none of these" preset -- surface it as mode
      // 'none' with negated=false, rather than as a double-negatable row.
      if (negated && 'or' === n.op) {
        return { field, values, mode: 'none', negated: false };
      }

      return { field, values, mode: 'and' === n.op ? 'all' : 'any', negated };
    }
  }

  return null;
}

/** Inverse of asRow(). Returns null if the row has no values yet (incomplete, not ready to include in the tree). */
export function rowToNode(row: FilterRow): FilterNode | null {
  const leaves: FilterLeaf[] = row.values.filter(Boolean).map((value) => ({ kind: 'leaf', field: row.field, value }));
  if (0 === leaves.length) return null;

  let node: FilterNode = leaves.length === 1 ? leaves[0] : ({ kind: 'group', op: 'all' === row.mode ? 'and' : 'or', children: leaves } as FilterGroup);

  if ('none' === row.mode) {
    node = { kind: 'group', op: 'not', children: [node] };
  }
  if (row.negated) {
    node = { kind: 'group', op: 'not', children: [node] };
  }

  return node;
}

/** Strip a single wrapping NOT, if present, returning [innerNode, wasNegated]. */
export function unwrapNot(node: FilterNode): [FilterNode, boolean] {
  if ('group' === node.kind && 'not' === node.op) {
    return [node.children[0], true];
  }
  return [node, false];
}

export function emptyGroup(op: 'and' | 'or' = 'and'): FilterGroup {
  return { kind: 'group', op, children: [] };
}
