<template>
  <div class="filter-expression-builder">
    <FilterGroupEditor :model-value="rootGroup" :depth="0" :disabled="disabled" @update:modelValue="onRootUpdate" @remove="onRootRemove" />
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import FilterGroupEditor from './FilterGroupEditor.vue';
import { emptyGroup } from '@services/filter-expression';
import type { FilterGroup, FilterNode } from '@typings';

/**
 * Boolean AND/OR/NOT expression builder over tags/embedded tags/people/
 * places (see FilterExpression.php for the backend counterpart, and
 * @services/filter-expression for the row<->tree conversion helpers used
 * throughout this component family).
 *
 * FilterGroupEditor always works with a group (never a bare leaf or a
 * top-level NOT) so its own logic doesn't need special-casing for the root
 * -- this component is just the thin adapter between that and the `null |
 * FilterNode` shape IFilters actually stores, wrapping a bare
 * leaf/NOT root in a synthetic group for editing.
 *
 * Deliberately does NOT unwrap a 1-child group back to a bare leaf on the
 * way out: the op (and/or) the user picked would have nowhere to live on a
 * bare leaf, and since the op-selector can appear while there's still only
 * 1 *committed* child (a new condition's own row counts once its draft is
 * showing, before it has a value yet), unwrapping here discarded the op the
 * user had just picked, making the selector appear to snap back to AND. A
 * 1-child and/or group is functionally identical to a bare leaf for the
 * backend either way (see FilterExpression.php), so keeping it wrapped
 * costs nothing beyond a marginally more verbose JSON payload.
 */
export default defineComponent({
  name: 'FilterExpressionBuilder',

  components: {
    FilterGroupEditor,
  },

  props: {
    modelValue: {
      type: Object as PropType<FilterNode | null>,
      default: null,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:modelValue'],

  computed: {
    rootGroup(): FilterGroup {
      if (!this.modelValue) return emptyGroup();

      if ('group' === this.modelValue.kind && ('and' === this.modelValue.op || 'or' === this.modelValue.op)) {
        return this.modelValue;
      }

      // A bare leaf, or a root-level NOT -- wrap in a synthetic 1-child AND
      // for editing; onRootUpdate() unwraps it back out if it's still alone.
      return { kind: 'group', op: 'and', children: [this.modelValue] };
    },
  },

  methods: {
    onRootUpdate(group: FilterGroup) {
      this.$emit('update:modelValue', group);
    },

    onRootRemove() {
      this.$emit('update:modelValue', null);
    },
  },
});
</script>

<style lang="scss" scoped>
.filter-expression-builder {
  width: 100%;
}
</style>
