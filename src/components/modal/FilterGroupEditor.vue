<template>
  <div class="filter-group-editor" :class="{ nested: depth > 0 }">
    <div class="group-header" v-if="showOpSelector">
      <span class="op-label">{{ t('memories', 'Match') }}</span>
      <NcSelect
        class="op-select"
        :model-value="opOption"
        @update:modelValue="onOpChange"
        :options="opOptions"
        :clearable="false"
        :disabled="disabled"
        :append-to-body="false"
        label="label"
        track-by="value"
      />
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

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';

import FilterConditionRow from './FilterConditionRow.vue';

import { translate as t } from '@services/l10n';
import { asRow, rowToNode, unwrapNot, defaultRow, emptyGroup, type FilterRow } from '@services/filter-expression';
import type { FilterGroup, FilterNode } from '@typings';

type IOpOption = { value: 'and' | 'or'; label: string };

type IChildDescriptor = { kind: 'row'; row: FilterRow; index: number } | { kind: 'group'; group: FilterGroup; negated: boolean; index: number };

export default defineComponent({
  name: 'FilterGroupEditor',

  components: {
    NcSelect,
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
    opOptions(): IOpOption[] {
      return [
        { value: 'and', label: t('memories', 'AND') },
        { value: 'or', label: t('memories', 'OR') },
      ];
    },

    opOption(): IOpOption {
      return this.opOptions.find((o) => o.value === this.modelValue.op) ?? this.opOptions[0];
    },

    /** Only meaningful (and only shown) once there's something to combine */
    showOpSelector(): boolean {
      return this.modelValue.children.length > 1 || !!this.newRowDraft || !!this.newGroupDraft;
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
    onOpChange(option: IOpOption | null) {
      if (!option) return;
      this.emitUpdate(this.modelValue.children, option.value);
    },

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
    min-width: 80px;
    width: 80px;
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
