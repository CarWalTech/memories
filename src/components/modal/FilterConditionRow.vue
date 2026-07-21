<template>
  <div class="filter-condition-row">
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

    <ClusterValuePicker class="value-picker" :field="modelValue.field" :multiple="true" :model-value="modelValue.values" @update:modelValue="onValuesChange" :disabled="disabled" />

    <NcSelect
      v-if="modelValue.values.length > 1"
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

    <NcCheckboxRadioSwitch class="not-toggle" type="switch" :checked="modelValue.negated" @update:checked="onNegatedChange" :disabled="disabled">
      {{ t('memories', 'NOT') }}
    </NcCheckboxRadioSwitch>

    <NcButton type="tertiary-no-background" :aria-label="t('memories', 'Remove condition')" @click="$emit('remove')" :disabled="disabled">
      <template #icon>
        <CloseIcon :size="16" />
      </template>
    </NcButton>
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

  computed: {
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
        if ('recognize' === value) return config.getSync('recognize_enabled');
        if ('facerecognition' === value) return config.getSync('facerecognition_enabled');
        if ('place' === value) return config.getSync('places_gis') > 0;
        return true; // embeddedTag always available
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
      return this.fieldOptions.find((o) => o.value === this.modelValue.field) ?? null;
    },

    modeOption(): IModeOption {
      return this.modeOptions.find((o) => o.value === this.modelValue.mode) ?? this.modeOptions[0];
    },
  },

  methods: {
    onFieldChange(option: IFieldOption | null) {
      if (!option) return;
      // Changing field invalidates previously selected values (they belong to a different picker)
      this.$emit('update:modelValue', { ...this.modelValue, field: option.value, values: [] });
    },

    onValuesChange(values: string[]) {
      this.$emit('update:modelValue', { ...this.modelValue, values });
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
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  padding: 4px 0;

  .field-select {
    min-width: 140px;
    flex: 1 1 140px;
  }

  .value-picker {
    min-width: 160px;
    flex: 2 1 160px;
  }

  .mode-select {
    min-width: 130px;
    flex: 1 1 130px;
  }

  .not-toggle {
    flex: 0 0 auto;
  }
}
</style>
