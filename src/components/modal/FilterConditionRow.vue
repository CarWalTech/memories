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

      <NcCheckboxRadioSwitch class="not-toggle" type="switch" :checked="modelValue.negated" @update:checked="onNegatedChange" :disabled="disabled">
        {{ t('memories', 'NOT') }}
      </NcCheckboxRadioSwitch>

      <NcButton type="tertiary-no-background" :aria-label="t('memories', 'Remove condition')" @click="$emit('remove')" :disabled="disabled">
        <template #icon>
          <CloseIcon :size="16" />
        </template>
      </NcButton>
    </div>

    <ClusterValuePicker class="value-picker" :field="effectiveField" :multiple="true" :model-value="effectiveValues" @update:modelValue="onValuesChange" :disabled="disabled" />

    <NcSelect
      v-if="effectiveValues.length > 1"
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
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';
import CloseIcon from 'vue-material-design-icons/Close.vue';

import ClusterValuePicker from './ClusterValuePicker.vue';

import config from '@services/static-config';
import { translate as t } from '@services/l10n';
import type { FilterField } from '@typings';
import type { FilterRow, FilterRowMode } from '@services/filter-expression';

type IFieldOption = { value: FilterField; label: string };
type IModeOption = { value: FilterRowMode; label: string };

export default defineComponent({
  name: 'FilterConditionRow',

  components: {
    NcSelect,
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
    // Set while the user has picked a new field but not yet chosen a value
    // for it. A FilterNode can't represent "field X, 0 values" (the backend
    // rejects empty leaf values), so switching field must not immediately
    // emit an update -- the previous fix for the AND/OR-reverts-to-AND bug
    // showed that an emitted 0-value row gets treated as "remove this
    // condition" by the parent, which silently deleted the row being edited.
    // Instead, the field switch is held here (and reflected in the UI via
    // effectiveField/effectiveValues) until a real value is chosen, at which
    // point both change atomically in one emit.
    pendingField: null as FilterField | null,
  }),

  computed: {
    effectiveField(): FilterField {
      return this.pendingField ?? this.modelValue.field;
    },

    effectiveValues(): string[] {
      return this.pendingField ? [] : this.modelValue.values;
    },

    fieldOptions(): IFieldOption[] {
      const all: IFieldOption[] = [
        { value: 'tag', label: t('memories', 'Collaborative Tag') },
        { value: 'embeddedTag', label: t('memories', 'Embedded Tag') },
        { value: 'recognize', label: t('memories', 'Person (Recognize)') },
        { value: 'facerecognition', label: t('memories', 'Person (Face Recognition)') },
        { value: 'place', label: t('memories', 'Place') },
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

    fieldOption(): IFieldOption | null {
      return this.fieldOptions.find((o) => o.value === this.effectiveField) ?? null;
    },

    modeOption(): IModeOption {
      return this.modeOptions.find((o) => o.value === this.modelValue.mode) ?? this.modeOptions[0];
    },
  },

  methods: {
    onFieldChange(option: IFieldOption | null) {
      if (!option || option.value === this.modelValue.field) return;
      // Don't emit yet -- see pendingField's comment. The value picker below
      // re-renders immediately (effectiveField changed), but the tree isn't
      // touched until onValuesChange() below actually has something to commit.
      this.pendingField = option.value;
    },

    onValuesChange(values: string[]) {
      if (this.pendingField && 0 === values.length) {
        return; // still nothing picked for the new field -- keep waiting
      }

      const field = this.pendingField ?? this.modelValue.field;
      this.pendingField = null;
      this.$emit('update:modelValue', { ...this.modelValue, field, values });
    },

    onModeChange(option: IModeOption | null) {
      if (!option) return;
      this.$emit('update:modelValue', { ...this.modelValue, mode: option.value });
    },

    onNegatedChange(negated: boolean) {
      this.$emit('update:modelValue', { ...this.modelValue, negated });
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
}
</style>
