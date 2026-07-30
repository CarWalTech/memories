import type { FilterField, FilterGroup, FilterLeaf, FilterNode, FilterOp } from '@typings';

/**
 * The filter expression builder UI works in two tiers:
 *  - A "row" is one user-facing condition. Its shape depends on the field's
 *    family (see fieldKind()):
 *     - "multi" (tag/embeddedTag/recognize/facerecognition/place/extension/
 *       fileType): a single field, one or more values, a match mode
 *       (all/any/none), and a NOT toggle -- e.g. "tag is Sunset OR Beach".
 *     - "text" (filename/filepath/camMake/camModel/camLens): a single field,
 *       a string op (contains/notContains/startsWith/endsWith/equals/regex),
 *       one value, and a NOT toggle.
 *     - "range" (rating/date): a single field and up to two bounds (gte/lte),
 *       combined with AND when both are set, and a NOT toggle.
 *     - "bool" (favorite): a single field and a true/false value.
 *  - A "group" is arbitrary nesting of rows and sub-groups with its own
 *    AND/OR operator, for combining different fields together (e.g.
 *    "tag:Sunset OR person:Alice").
 *
 * These functions convert between a row's UI state and the raw FilterNode
 * tree the backend expects (see FilterExpression.php), so a FilterNode that
 * happens to match one of the row shapes can be edited as a single compact
 * row instead of a generic nested group.
 */
export type FilterRowMode = 'all' | 'any' | 'none';

export type FilterMultiRow = {
  kind: 'multi';
  field: FilterField;
  values: string[];
  mode: FilterRowMode;
  negated: boolean;
};

export type FilterTextRow = {
  kind: 'text';
  field: FilterField;
  op: FilterOp;
  value: string;
  negated: boolean;
};

export type FilterRangeRow = {
  kind: 'range';
  field: FilterField;
  /** '' = unbounded */
  gte: string;
  /** '' = unbounded */
  lte: string;
  negated: boolean;
};

export type FilterBoolRow = {
  kind: 'bool';
  field: FilterField;
  value: boolean;
};

export type FilterRow = FilterMultiRow | FilterTextRow | FilterRangeRow | FilterBoolRow;

const MULTI_FIELDS: FilterField[] = ['tag', 'embeddedTag', 'recognize', 'facerecognition', 'place', 'extension', 'fileType'];
const TEXT_FIELDS: FilterField[] = ['filename', 'filepath', 'camMake', 'camModel', 'camLens'];
const RANGE_FIELDS: FilterField[] = ['rating', 'date'];
const BOOL_FIELDS: FilterField[] = ['favorite'];

export type FilterFieldKind = 'multi' | 'text' | 'range' | 'bool';

export function fieldKind(field: FilterField): FilterFieldKind {
  if (RANGE_FIELDS.includes(field)) return 'range';
  if (TEXT_FIELDS.includes(field)) return 'text';
  if (BOOL_FIELDS.includes(field)) return 'bool';
  return 'multi';
}

/** Regex is only offered for filename/filepath, not the free-text camera fields. */
export function textOpsFor(field: FilterField): FilterOp[] {
  const base: FilterOp[] = ['contains', 'notContains', 'startsWith', 'endsWith', 'equals'];
  return 'filename' === field || 'filepath' === field ? [...base, 'regex'] : base;
}

export function defaultRow(field: FilterField = 'tag'): FilterRow {
  switch (fieldKind(field)) {
    case 'text':
      return { kind: 'text', field, op: 'contains', value: '', negated: false };
    case 'range':
      return { kind: 'range', field, gte: '', lte: '', negated: false };
    case 'bool':
      return { kind: 'bool', field, value: true };
    default:
      return { kind: 'multi', field, values: [], mode: 'all', negated: false };
  }
}

/** The field of the (possibly NOT-wrapped, possibly grouped) node's first leaf, or null if indeterminate. */
function leafFieldOf(node: FilterNode): FilterField | null {
  let n = node;
  if ('group' === n.kind && 'not' === n.op) {
    n = n.children[0];
  }
  if ('leaf' === n.kind) {
    return n.field;
  }
  if ('group' === n.kind && ('and' === n.op || 'or' === n.op) && n.children.length > 0) {
    const first = n.children[0];
    return 'leaf' === first.kind ? first.field : null;
  }
  return null;
}

/**
 * If $node matches a row shape, return its row representation; otherwise
 * null (the caller should fall back to rendering it as a generic group).
 */
export function asRow(node: FilterNode): FilterRow | null {
  const field = leafFieldOf(node);
  if (!field) return null;

  switch (fieldKind(field)) {
    case 'text':
      return asTextRow(node);
    case 'range':
      return asRangeRow(node);
    case 'bool':
      return asBoolRow(node);
    default:
      return asMultiRow(node);
  }
}

function asMultiRow(node: FilterNode): FilterMultiRow | null {
  let n = node;
  let negated = false;

  if (n.kind === 'group' && n.op === 'not') {
    negated = true;
    n = n.children[0];
  }

  if (n.kind === 'leaf') {
    return { kind: 'multi', field: n.field, values: [n.value], mode: 'all', negated };
  }

  if (n.kind === 'group' && ('and' === n.op || 'or' === n.op)) {
    const children = n.children;
    if (
      children.length > 0 &&
      children.every((c): c is FilterLeaf => 'leaf' === c.kind && !c.op && c.field === (children[0] as FilterLeaf).field)
    ) {
      const field = (children[0] as FilterLeaf).field;
      const values = children.map((c) => (c as FilterLeaf).value);

      // NOT(OR(...)) is the "none of these" preset -- surface it as mode
      // 'none' with negated=false, rather than as a double-negatable row.
      if (negated && 'or' === n.op) {
        return { kind: 'multi', field, values, mode: 'none', negated: false };
      }

      return { kind: 'multi', field, values, mode: 'and' === n.op ? 'all' : 'any', negated };
    }
  }

  return null;
}

function asTextRow(node: FilterNode): FilterTextRow | null {
  const [inner, negated] = unwrapNot(node);
  if ('leaf' !== inner.kind || !inner.op) return null;
  return { kind: 'text', field: inner.field, op: inner.op, value: inner.value, negated };
}

function asRangeRow(node: FilterNode): FilterRangeRow | null {
  const [inner, negated] = unwrapNot(node);

  if ('leaf' === inner.kind && inner.op) {
    return {
      kind: 'range',
      field: inner.field,
      gte: 'gte' === inner.op ? inner.value : '',
      lte: 'lte' === inner.op ? inner.value : '',
      negated,
    };
  }

  if ('group' === inner.kind && 'and' === inner.op && 2 === inner.children.length) {
    const [a, b] = inner.children;
    if ('leaf' === a.kind && 'leaf' === b.kind && a.field === b.field) {
      const gteLeaf = 'gte' === a.op ? a : 'gte' === b.op ? b : null;
      const lteLeaf = 'lte' === a.op ? a : 'lte' === b.op ? b : null;
      if (gteLeaf && lteLeaf) {
        return { kind: 'range', field: a.field, gte: gteLeaf.value, lte: lteLeaf.value, negated };
      }
    }
  }

  return null;
}

function asBoolRow(node: FilterNode): FilterBoolRow | null {
  const [inner, negated] = unwrapNot(node);
  if ('leaf' !== inner.kind) return null;
  const value = 'true' === inner.value;
  return { kind: 'bool', field: inner.field, value: negated ? !value : value };
}

/** Inverse of asRow(). Returns null if the row has no value(s) yet (incomplete, not ready to include in the tree). */
export function rowToNode(row: FilterRow): FilterNode | null {
  switch (row.kind) {
    case 'multi':
      return multiRowToNode(row);
    case 'text':
      return textRowToNode(row);
    case 'range':
      return rangeRowToNode(row);
    case 'bool':
      return boolRowToNode(row);
  }
}

function multiRowToNode(row: FilterMultiRow): FilterNode | null {
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

function textRowToNode(row: FilterTextRow): FilterNode | null {
  if (!row.value) return null;
  let node: FilterNode = { kind: 'leaf', field: row.field, op: row.op, value: row.value };
  if (row.negated) {
    node = { kind: 'group', op: 'not', children: [node] };
  }
  return node;
}

function rangeRowToNode(row: FilterRangeRow): FilterNode | null {
  const leaves: FilterLeaf[] = [];
  if ('' !== row.gte) leaves.push({ kind: 'leaf', field: row.field, op: 'gte', value: row.gte });
  if ('' !== row.lte) leaves.push({ kind: 'leaf', field: row.field, op: 'lte', value: row.lte });
  if (0 === leaves.length) return null;

  let node: FilterNode = leaves.length === 1 ? leaves[0] : ({ kind: 'group', op: 'and', children: leaves } as FilterGroup);
  if (row.negated) {
    node = { kind: 'group', op: 'not', children: [node] };
  }
  return node;
}

function boolRowToNode(row: FilterBoolRow): FilterNode {
  return { kind: 'leaf', field: row.field, value: row.value ? 'true' : 'false' };
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
