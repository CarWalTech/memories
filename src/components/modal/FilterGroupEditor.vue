<template>
  <div class="filter-group-editor" :class="{ nested: depth > 0 }">
    <div class="group-header" v-if="showOpSelector">
      <span class="op-label">{{ t('memories', 'Match') }}</span>
      <select class="op-select" v-model="opValue" :disabled="disabled">
        <option value="and">{{ t('memories', 'AND') }}</option>
        <option value="or">{{ t('memories', 'OR') }}</option>
      </select>
    </div>

    <div class="group-children">
      <div v-for="desc in childDescriptors" :key="desc.index" class="child-row">
        <FilterConditionRow
          v-if="'row' === desc.kind"
          :model-value="desc.row"
          @update:modelValue="(row) => updateChild(desc.index, rowToNode(row))"
          @remove="removeChild(desc.index)"
          :disabled="disabled"
        />
        <div v-else class="nested-group-wrapper">
          <NcCheckboxRadioSwitch
            class="not-toggle"
            type="switch"
            :checked="desc.negated"
            @update:checked="setChildNegated(desc.index, desc.group, $event)"
            :disabled="disabled"
          >
            {{ t('memories', 'NOT') }}
          </NcCheckboxRadioSwitch>
          <FilterGroupEditor
            :model-value="desc.group"
            :depth="depth + 1"
            :disabled="disabled"
            removable
            @update:modelValue="(g) => updateChild(desc.index, desc.negated ? notWrap(g) : g)"
            @remove="removeChild(desc.index)"
          />
        </div>
      </div>

      <div v-if="newRowDraft" class="child-row">
        <FilterConditionRow :model-value="newRowDraft" @update:modelValue="onDraftRowChange" @remove="onDraftRowRemove" :disabled="disabled" />
      </div>

      <div v-if="newGroupDraft" class="child-row nested-group-wrapper">
        <FilterGroupEditor
          :model-value="newGroupDraft"
          :depth="depth + 1"
          :disabled="disabled"
          removable
          @update:modelValue="onDraftGroupChange"
          @remove="onDraftGroupRemove"
        />
      </div>
    </div>

    <div class="group-actions">
      <NcButton type="tertiary" @click="addRow" :disabled="disabled">
        {{ t('memories', '+ Add condition') }}
      </NcButton>
      <NcButton type="tertiary" @click="addGroup" :disabled="disabled">
        {{ t('memories', '+ Add group') }}
      </NcButton>
      <NcButton v-if="removable" type="tertiary-no-background" @click="$emit('remove')" :disabled="disabled">
        {{ t('memories', 'Remove group') }}
      </NcButton>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';

import FilterConditionRow from './FilterConditionRow.vue';

import { translate as t } from '@services/l10n';
import { asRow, rowToNode, unwrapNot, defaultRow, emptyGroup, type FilterRow } from '@services/filter-expression';
import type { FilterGroup, FilterNode } from '@typings';

type IChildDescriptor = { kind: 'row'; row: FilterRow; index: number } | { kind: 'group'; group: FilterGroup; negated: boolean; index: number };

export default defineComponent({
  name: 'FilterGroupEditor',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
    FilterConditionRow,
  },

  props: {
    modelValue: {
      type: Object as PropType<FilterGroup>,
      required: true,
    },
    depth: {
      type: Number,
      default: 0,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
    /** Whether a "Remove group" button is shown (false for the true top-level root) */
    removable: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:modelValue', 'remove'],

  data: () => ({
    newRowDraft: null as FilterRow | null,
    newGroupDraft: null as FilterGroup | null,
  }),

  computed: {
    opValue: {
      get(): 'and' | 'or' {
        return this.modelValue.op as 'and' | 'or';
      },
      set(op: 'and' | 'or') {
        this.emitUpdate(this.modelValue.children, op);
      },
    },

    /**
     * Only meaningful (and only shown) once there are 2+ *total* conditions
     * to combine, counting an in-progress draft as one -- a draft counts
     * once it exists (children.length alone would miss it), but a draft
     * alone (children.length === 0) is still just 1 total item, not 2, so
     * this must sum them rather than OR the two conditions independently
     * (which showed the selector as soon as any draft opened, even for a
     * brand new first-ever condition with nothing yet to combine it with).
     */
    showOpSelector(): boolean {
      const draftCount = (this.newRowDraft ? 1 : 0) + (this.newGroupDraft ? 1 : 0);
      return this.modelValue.children.length + draftCount > 1;
    },

    childDescriptors(): IChildDescriptor[] {
      return this.modelValue.children.map((child, index) => {
        const row = asRow(child);
        if (row) {
          return { kind: 'row', row, index };
        }

        const [inner, negated] = unwrapNot(child);
        // asRow() already covers bare leaves and homogeneous and/or/not-of-or
        // groups, so reaching here means `inner` is a genuinely mixed group.
        return { kind: 'group', group: inner as FilterGroup, negated, index };
      });
    },
  },

  methods: {

    updateChild(index: number, newNode: FilterNode | null) {
      const children = [...this.modelValue.children];
      if (null === newNode) {
        children.splice(index, 1);
      } else {
        children[index] = newNode;
      }
      this.emitUpdate(children);
    },

    removeChild(index: number) {
      const children = [...this.modelValue.children];
      children.splice(index, 1);
      this.emitUpdate(children);
    },

    setChildNegated(index: number, group: FilterGroup, negated: boolean) {
      this.updateChild(index, negated ? this.notWrap(group) : group);
    },

    addRow() {
      this.newRowDraft = defaultRow(this.suggestedField());
    },

    addGroup() {
      this.newGroupDraft = emptyGroup();
    },

    onDraftRowChange(row: FilterRow) {
      const node = rowToNode(row);
      if (node) {
        this.commitChild(node);
        this.newRowDraft = null;
      } else {
        this.newRowDraft = row;
      }
    },

    onDraftRowRemove() {
      this.newRowDraft = null;
    },

    onDraftGroupChange(group: FilterGroup) {
      this.commitChild(group);
      this.newGroupDraft = null;
    },

    onDraftGroupRemove() {
      this.newGroupDraft = null;
    },

    commitChild(node: FilterNode) {
      this.emitUpdate([...this.modelValue.children, node]);
    },

    emitUpdate(children: FilterNode[], op?: 'and' | 'or') {
      if (0 === children.length) {
        this.$emit('remove');
        return;
      }

      op ??= this.modelValue.op as 'and' | 'or';
      this.$emit('update:modelValue', { kind: 'group', op, children });
    },

    notWrap(group: FilterGroup): FilterNode {
      return { kind: 'group', op: 'not', children: [group] };
    },

    suggestedField() {
      // Last row's field is the most likely one the user wants to add another of
      const lastRow = [...this.childDescriptors].reverse().find((d): d is Extract<IChildDescriptor, { kind: 'row' }> => 'row' === d.kind);
      return lastRow?.row.field ?? 'tag';
    },

    rowToNode,
    t,
  },
});
</script>

<style lang="scss" scoped>
.filter-group-editor {
  display: flex;
  flex-direction: column;
  gap: 4px;

  &.nested {
    padding: 4px 0 4px 8px;
    border-left: 2px solid var(--color-border);
  }
}

.group-header {
  display: flex;
  align-items: center;
  gap: 4px;

  .op-label {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
  }

  .op-select {
    width: 70px;
    padding: 4px 6px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
  }
}

.group-children {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nested-group-wrapper {
  display: flex;
  flex-direction: column;
  gap: 2px;

  .not-toggle {
    align-self: flex-start;
  }
}

.group-actions {
  display: flex;
  gap: 2px;
  flex-wrap: wrap;
}
</style>
