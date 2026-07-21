<template>
  <div class="filter-component">
    <form @submit.prevent>
      <!-- Rating range -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Rating') }}</label>
        <div class="rating-row">
          <span class="rating-caption">{{ t('memories', 'At least') }}</span>
          <RatingStars :rating="filters.minRating" :size="18" @update:rating="onMinRatingChange" />
          <NcButton v-if="filters.minRating > 0" type="tertiary-no-background" :aria-label="t('memories', 'Clear minimum rating')" @click="onMinRatingChange(0)">
            <template #icon><CloseIcon :size="14" /></template>
          </NcButton>
        </div>
        <div class="rating-row">
          <span class="rating-caption">{{ t('memories', 'At most') }}</span>
          <RatingStars :rating="filters.maxRating" :size="18" @update:rating="onMaxRatingChange" />
          <NcButton v-if="filters.maxRating > 0" type="tertiary-no-background" :aria-label="t('memories', 'Clear maximum rating')" @click="onMaxRatingChange(0)">
            <template #icon><CloseIcon :size="14" /></template>
          </NcButton>
        </div>
      </div>

      <!-- Favorites -->
      <div class="filter-section">
        <NcCheckboxRadioSwitch type="switch" :checked="filters.favorite" @update:checked="onFavoriteChange">
          {{ t('memories', 'Favorites only') }}
        </NcCheckboxRadioSwitch>
      </div>

      <!-- Filename / filepath -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Filename') }}</label>
        <NcTextField :value="filters.filename" @update:value="onFieldInput('filename', $event)" :label-outside="true" :placeholder="t('memories', 'e.g. IMG_2024 or a regex')" />
      </div>
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Folder path') }}</label>
        <NcTextField :value="filters.filepath" @update:value="onFieldInput('filepath', $event)" :label-outside="true" :placeholder="t('memories', 'e.g. Vacation/2024')" />
      </div>

      <!-- Date range -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Date range') }}</label>
        <div class="date-row">
          <input type="date" class="date-input" v-model="filters.dateStart" @change="emitFilterChange" :max="filters.dateEnd || undefined" />
          <span class="date-sep">–</span>
          <input type="date" class="date-input" v-model="filters.dateEnd" @change="emitFilterChange" :min="filters.dateStart || undefined" />
          <NcButton v-if="filters.dateStart || filters.dateEnd" type="tertiary-no-background" :aria-label="t('memories', 'Clear date range')" @click="clearDateRange">
            <template #icon><CloseIcon :size="14" /></template>
          </NcButton>
        </div>
        <div class="date-presets">
          <NcButton type="tertiary" @click="applyDatePreset('last7')">{{ t('memories', 'Last 7 days') }}</NcButton>
          <NcButton type="tertiary" @click="applyDatePreset('last30')">{{ t('memories', 'Last 30 days') }}</NcButton>
          <NcButton type="tertiary" @click="applyDatePreset('thisMonth')">{{ t('memories', 'This month') }}</NcButton>
          <NcButton type="tertiary" @click="applyDatePreset('thisYear')">{{ t('memories', 'This year') }}</NcButton>
        </div>
      </div>

      <!-- File type -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'File type') }}</label>
        <div class="filetype-row">
          <NcCheckboxRadioSwitch :checked="hasFileType('image/%')" @update:checked="toggleFileType('image/%', $event)">
            {{ t('memories', 'Photos') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch :checked="hasFileType('video/%')" @update:checked="toggleFileType('video/%', $event)">
            {{ t('memories', 'Videos') }}
          </NcCheckboxRadioSwitch>
        </div>
      </div>

      <!-- Camera -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Camera') }}</label>
        <div class="camera-row">
          <NcTextField :value="filters.camMake" @update:value="onFieldInput('camMake', $event)" :label-outside="true" :placeholder="t('memories', 'Make')" />
          <NcTextField :value="filters.camModel" @update:value="onFieldInput('camModel', $event)" :label-outside="true" :placeholder="t('memories', 'Model')" />
          <NcTextField :value="filters.camLens" @update:value="onFieldInput('camLens', $event)" :label-outside="true" :placeholder="t('memories', 'Lens')" />
        </div>
      </div>

      <!-- Tag / embedded tag / people / places expression builder -->
      <div class="filter-section">
        <label class="filter-label">{{ t('memories', 'Tags & People') }}</label>
        <FilterExpressionBuilder :model-value="filters.filterExpr" :disabled="disabled" @update:modelValue="onFilterExprChange" />
      </div>

      <!-- Filter Actions -->
      <div class="filter-actions">
        <NcButton type="secondary" @click="clearAllFilters" :disabled="!hasActiveFilters">
          {{ t('memories', 'Clear All') }}
        </NcButton>
      </div>
    </form>
  </div>
</template>

<script lang="ts">
import type { IFilters } from '@typings';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import RatingStars from '@components/common/RatingStars.vue';
import FilterExpressionBuilder from './FilterExpressionBuilder.vue';
import { defineComponent, type PropType } from 'vue';
import { emptyFilters, hasActiveFilters } from '@services/filters';

function toDateStr(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export default defineComponent({
  name: 'FilterComponent',

  components: {
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    RatingStars,
    CloseIcon,
    FilterExpressionBuilder,
  },

  props: {
    /** Whether the component is disabled */
    disabled: {
      type: Boolean,
      default: false,
    },
    /** Initial filter values */
    initialFilters: {
      type: Object as PropType<IFilters>,
      default: emptyFilters,
    },
  },

  emits: ['filter-change'],

  data: () => ({
    filters: emptyFilters(),
  }),

  computed: {
    hasActiveFilters(): boolean {
      return hasActiveFilters(this.filters);
    },
  },

  watch: {
    initialFilters: {
      handler(newFilters: IFilters) {
        this.filters = { ...emptyFilters(), ...newFilters };
      },
      deep: true,
      immediate: true,
    },
  },

  methods: {
    emitFilterChange() {
      utils.bus.emit('memories:filters:changed', { ...this.filters });
      this.$emit('filter-change', { ...this.filters });
    },

    onFieldInput(field: 'filename' | 'filepath' | 'camMake' | 'camModel' | 'camLens', value: string) {
      this.filters[field] = value;
      this.emitFilterChange();
    },

    onFilterExprChange(value: IFilters['filterExpr']) {
      this.filters.filterExpr = value;
      this.emitFilterChange();
    },

    onMinRatingChange(rating: number) {
      this.filters.minRating = rating;
      this.emitFilterChange();
    },

    onMaxRatingChange(rating: number) {
      this.filters.maxRating = rating;
      this.emitFilterChange();
    },

    onFavoriteChange(checked: boolean) {
      this.filters.favorite = checked;
      this.emitFilterChange();
    },

    hasFileType(pattern: string): boolean {
      return this.filters.fileType.includes(pattern);
    },

    toggleFileType(pattern: string, checked: boolean) {
      this.filters.fileType = checked ? [...this.filters.fileType, pattern] : this.filters.fileType.filter((p) => p !== pattern);
      this.emitFilterChange();
    },

    clearDateRange() {
      this.filters.dateStart = '';
      this.filters.dateEnd = '';
      this.emitFilterChange();
    },

    applyDatePreset(preset: 'last7' | 'last30' | 'thisMonth' | 'thisYear') {
      const now = new Date();
      let start: Date;
      const end = now;

      switch (preset) {
        case 'last7':
          start = new Date(now);
          start.setDate(start.getDate() - 6);
          break;
        case 'last30':
          start = new Date(now);
          start.setDate(start.getDate() - 29);
          break;
        case 'thisMonth':
          start = new Date(now.getFullYear(), now.getMonth(), 1);
          break;
        case 'thisYear':
          start = new Date(now.getFullYear(), 0, 1);
          break;
      }

      this.filters.dateStart = toDateStr(start);
      this.filters.dateEnd = toDateStr(end);
      this.emitFilterChange();
    },

    clearAllFilters() {
      this.filters = emptyFilters();
      this.emitFilterChange();
    },

    t,
  },
});
</script>

<style lang="scss" scoped>
.filter-component {
  padding: 16px;
  min-width: 320px;
  max-width: 420px;
  max-height: 70vh;
  overflow-y: auto;

  form {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
}

.filter-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.filter-label {
  font-weight: 600;
  font-size: 14px;
  color: var(--color-text-darker);
}

.rating-row {
  display: flex;
  align-items: center;
  gap: 8px;

  .rating-caption {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    min-width: 64px;
  }
}

.date-row {
  display: flex;
  align-items: center;
  gap: 6px;

  .date-input {
    flex: 1;
    min-width: 0;
    padding: 6px 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
  }

  .date-sep {
    color: var(--color-text-maxcontrast);
  }
}

.date-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.filetype-row,
.camera-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.camera-row > * {
  flex: 1 1 100px;
}

.filter-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
