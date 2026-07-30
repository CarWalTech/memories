<template>
  <div class="filter-condition-row">
    <div class="row-top">
      <NcSelect
        class="field-select"
        :model-value="fieldOption"
        @update:modelValue="onFieldChange"
        :options="fieldOptions"
        :clearable="false"
        :disabled="disabled"
        :append-to-body="false"
        label="label"
        track-by="value"
      />

      <NcCheckboxRadioSwitch v-if="'bool' !== currentRow.kind" class="not-toggle" type="switch" :checked="currentRow.negated" @update:checked="onNegatedChange" :disabled="disabled">
        {{ t('memories', 'NOT') }}
      </NcCheckboxRadioSwitch>

      <NcButton type="tertiary-no-background" :aria-label="t('memories', 'Remove condition')" @click="$emit('remove')" :disabled="disabled">
        <template #icon>
          <CloseIcon :size="16" />
        </template>
      </NcButton>
    </div>

    <!-- Set-membership fields: tag/embeddedTag/recognize/facerecognition/place/extension/fileType -->
    <template v-if="'multi' === currentRow.kind">
      <ClusterValuePicker class="value-picker" :field="effectiveField" :multiple="true" :model-value="currentRow.values" @update:modelValue="onMultiValuesChange" :disabled="disabled" />

      <NcSelect
        v-if="currentRow.values.length > 1"
        class="mode-select"
        :model-value="modeOption"
        @update:modelValue="onModeChange"
        :options="modeOptions"
        :clearable="false"
        :disabled="disabled"
        :append-to-body="false"
        label="label"
        track-by="value"
      />
    </template>

    <!-- Text fields: filename/filepath/camMake/camModel/camLens -->
    <div v-else-if="'text' === currentRow.kind" class="text-row">
      <NcSelect
        class="op-select"
        :model-value="textOpOption"
        @update:modelValue="onTextOpChange"
        :options="textOpOptions"
        :clearable="false"
        :disabled="disabled"
        :append-to-body="false"
        label="label"
        track-by="value"
      />
      <NcTextField class="text-value" :value="currentRow.value" @update:value="onTextValueChange" :label-outside="true" :placeholder="t('memories', 'Value')" :disabled="disabled" />
    </div>

    <!-- Range fields: rating/date -->
    <div v-else-if="'range' === currentRow.kind" class="range-row">
      <template v-if="'rating' === effectiveField">
        <select class="range-input" :value="currentRow.gte" @change="onRangeBoundEvent('gte', $event)" :disabled="disabled">
          <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <span class="range-sep">{{ t('memories', 'to') }}</span>
        <select class="range-input" :value="currentRow.lte" @change="onRangeBoundEvent('lte', $event)" :disabled="disabled">
          <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
      </template>
      <template v-else>
        <input type="date" class="range-input" :value="currentRow.gte" @change="onRangeBoundEvent('gte', $event)" :disabled="disabled" />
        <span class="range-sep">{{ t('memories', 'to') }}</span>
        <input type="date" class="range-input" :value="currentRow.lte" @change="onRangeBoundEvent('lte', $event)" :disabled="disabled" />
      </template>
    </div>

    <!-- Boolean field: favorite -->
    <div v-else-if="'bool' === currentRow.kind" class="bool-row">
      <NcCheckboxRadioSwitch type="switch" :checked="currentRow.value" @update:checked="onBoolValueChange" :disabled="disabled">
        {{ t('memories', 'Favorite') }}
      </NcCheckboxRadioSwitch>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js';
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';
import CloseIcon from 'vue-material-design-icons/Close.vue';

import ClusterValuePicker from './ClusterValuePicker.vue';

import config from '@services/static-config';
import { translate as t } from '@services/l10n';
import type { FilterField, FilterOp } from '@typings';
import {
  fieldKind,
  textOpsFor,
  defaultRow,
  type FilterRow,
  type FilterRowMode,
  type FilterMultiRow,
  type FilterTextRow,
  type FilterRangeRow,
  type FilterBoolRow,
} from '@services/filter-expression';

type IFieldOption = { value: FilterField; label: string };
type IModeOption = { value: FilterRowMode; label: string };
type ITextOpOption = { value: FilterOp; label: string };

/** Whether a not-yet-committed row draft (see pendingField below) has enough to be worth keeping. */
function isRowEmpty(row: FilterRow): boolean {
  switch (row.kind) {
    case 'multi':
      return 0 === row.values.length;
    case 'text':
      return '' === row.value;
    case 'range':
      return '' === row.gte && '' === row.lte;
    case 'bool':
      return false;
  }
}

export default defineComponent({
  name: 'FilterConditionRow',

  components: {
    NcSelect,
    NcTextField,
    NcButton,
    NcCheckboxRadioSwitch,
    CloseIcon,
    ClusterValuePicker,
  },

  props: {
    modelValue: {
      type: Object as PropType<FilterRow>,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:modelValue', 'remove'],

  data: () => ({
    // Set while the user has picked a new field but the resulting row (of
    // possibly a completely different kind -- e.g. switching from "tag" to
    // "filename") doesn't have a value yet. A FilterNode can't represent an
    // empty condition (the backend rejects empty leaf values), so switching
    // field must not immediately emit an update -- an emitted empty row gets
    // treated as "remove this condition" by the parent, which would silently
    // delete the row being edited. Instead the field switch is held here (and
    // reflected in the UI via currentRow) until the draft has a real value,
    // at which point both change atomically in one emit.
    pendingField: null as FilterField | null,
    pendingDraft: null as FilterRow | null,
  }),

  computed: {
    effectiveField(): FilterField {
      return this.pendingField ?? this.modelValue.field;
    },

    currentRow(): FilterRow {
      return this.pendingDraft ?? this.modelValue;
    },

    fieldOptions(): IFieldOption[] {
      const all: IFieldOption[] = [
        { value: 'tag', label: t('memories', 'Collaborative Tag') },
        { value: 'embeddedTag', label: t('memories', 'Embedded Tag') },
        { value: 'recognize', label: t('memories', 'Person (Recognize)') },
        { value: 'facerecognition', label: t('memories', 'Person (Face Recognition)') },
        { value: 'place', label: t('memories', 'Place') },
        { value: 'extension', label: t('memories', 'File Extension') },
        { value: 'fileType', label: t('memories', 'File Type') },
        { value: 'rating', label: t('memories', 'Rating') },
        { value: 'date', label: t('memories', 'Date') },
        { value: 'favorite', label: t('memories', 'Favorite') },
        { value: 'filename', label: t('memories', 'Filename') },
        { value: 'filepath', label: t('memories', 'Folder Path') },
        { value: 'camMake', label: t('memories', 'Camera Make') },
        { value: 'camModel', label: t('memories', 'Camera Model') },
        { value: 'camLens', label: t('memories', 'Camera Lens') },
      ];

      return all.filter(({ value }) => {
        if ('tag' === value) return config.getSync('systemtags_enabled');
        if ('embeddedTag' === value) return config.getSync('embeddedtags_enabled');
        if ('recognize' === value) return config.getSync('recognize_enabled');
        if ('facerecognition' === value) return config.getSync('facerecognition_enabled');
        if ('place' === value) return config.getSync('places_gis') > 0;
        return true;
      });
    },

    modeOptions(): IModeOption[] {
      return [
        { value: 'all', label: t('memories', 'All of these') },
        { value: 'any', label: t('memories', 'Any of these') },
        { value: 'none', label: t('memories', 'None of these') },
      ];
    },

    textOpOptions(): ITextOpOption[] {
      const labels: Record<FilterOp, string> = {
        gte: '',
        lte: '',
        contains: t('memories', 'Contains'),
        notContains: t('memories', 'Does not contain'),
        startsWith: t('memories', 'Starts with'),
        endsWith: t('memories', 'Ends with'),
        equals: t('memories', 'Equals'),
        regex: t('memories', 'Matches regex'),
      };
      return textOpsFor(this.effectiveField).map((value) => ({ value, label: labels[value] }));
    },

    fieldOption(): IFieldOption | null {
      return this.fieldOptions.find((o) => o.value === this.effectiveField) ?? null;
    },

    modeOption(): IModeOption {
      const row = this.currentRow as FilterMultiRow;
      return this.modeOptions.find((o) => o.value === row.mode) ?? this.modeOptions[0];
    },

    textOpOption(): ITextOpOption | null {
      const row = this.currentRow as FilterTextRow;
      return this.textOpOptions.find((o) => o.value === row.op) ?? null;
    },

    ratingOptions(): { value: string; label: string }[] {
      return ['', '1', '2', '3', '4', '5'].map((v) => ({ value: v, label: '' === v ? t('memories', 'Any') : '★'.repeat(Number(v)) }));
    },
  },

  methods: {
    onFieldChange(option: IFieldOption | null) {
      if (!option || option.value === this.modelValue.field) return;
      // Don't emit yet -- see pendingField's comment. Build a fresh draft of
      // the new field's row kind; if it's already non-empty (e.g. the
      // boolean "favorite" field, which always has a value), commit at once.
      this.pendingField = option.value;
      this.pendingDraft = defaultRow(option.value);
      this.maybeCommit();
    },

    onMultiValuesChange(values: string[]) {
      this.updateRow({ ...(this.currentRow as FilterMultiRow), values });
    },

    onModeChange(option: IModeOption | null) {
      if (!option) return;
      this.updateRow({ ...(this.currentRow as FilterMultiRow), mode: option.value });
    },

    onTextOpChange(option: ITextOpOption | null) {
      if (!option) return;
      this.updateRow({ ...(this.currentRow as FilterTextRow), op: option.value });
    },

    onTextValueChange(value: string) {
      this.updateRow({ ...(this.currentRow as FilterTextRow), value });
    },

    onRangeBoundEvent(bound: 'gte' | 'lte', event: Event) {
      const value = (event.target as HTMLInputElement | HTMLSelectElement).value;
      this.updateRow({ ...(this.currentRow as FilterRangeRow), [bound]: value });
    },

    onBoolValueChange(value: boolean) {
      this.updateRow({ ...(this.currentRow as FilterBoolRow), value });
    },

    onNegatedChange(negated: boolean) {
      this.updateRow({ ...(this.currentRow as Exclude<FilterRow, FilterBoolRow>), negated } as FilterRow);
    },

    /** Route an edited row through the pending-draft buffer, or emit it directly if there's no field switch in progress. */
    updateRow(row: FilterRow) {
      if (this.pendingField) {
        this.pendingDraft = row;
        this.maybeCommit();
      } else {
        this.$emit('update:modelValue', row);
      }
    },

    maybeCommit() {
      if (!this.pendingDraft || isRowEmpty(this.pendingDraft)) return;
      const row = this.pendingDraft;
      this.pendingField = null;
      this.pendingDraft = null;
      this.$emit('update:modelValue', row);
    },

    t,
  },
});
</script>

<style lang="scss" scoped>
.filter-condition-row {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 2px 0;

  .row-top {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .field-select {
    min-width: 120px;
    flex: 1 1 auto;
  }

  .not-toggle {
    flex: 0 0 auto;
  }

  .value-picker {
    width: 100%;
  }

  .mode-select {
    width: 100%;
  }

  .text-row {
    display: flex;
    align-items: center;
    gap: 4px;

    .op-select {
      min-width: 140px;
      flex: 0 0 auto;
    }

    .text-value {
      flex: 1 1 auto;
    }
  }

  .range-row {
    display: flex;
    align-items: center;
    gap: 6px;

    .range-input {
      flex: 1;
      min-width: 0;
      padding: 4px 6px;
      border-radius: var(--border-radius);
      border: 1px solid var(--color-border-dark);
      background: var(--color-main-background);
      color: var(--color-main-text);
    }

    .range-sep {
      color: var(--color-text-maxcontrast);
      font-size: 12px;
    }
  }
}
</style>
