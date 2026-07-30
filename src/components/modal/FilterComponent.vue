<template>
  <div class="filter-component">
    <!-- Basic / Advanced tabs -->
    <div class="filter-tabs">
      <button type="button" class="tab" :class="{ active: 'basic' === activeTab }" @click="activeTab = 'basic'">
        {{ t('memories', 'Basic') }}
      </button>
      <button type="button" class="tab" :class="{ active: 'advanced' === activeTab }" @click="activeTab = 'advanced'">
        {{ t('memories', 'Advanced') }}
      </button>
    </div>

    <form @submit.prevent>
      <template v-if="'basic' === activeTab">
        <!-- Shown whenever an Advanced expression is active: it supersedes every control below -->
        <div v-if="filters.filterExpr" class="advanced-override-banner">
          <span>{{ t('memories', 'An Advanced filter is active and overrides these controls.') }}</span>
          <NcButton type="tertiary-no-background" @click="clearFilterExpr" :disabled="disabled">
            {{ t('memories', 'Clear') }}
          </NcButton>
        </div>

        <!-- Filename / filepath -->
        <div class="filter-section">
          <label class="filter-label">{{ t('memories', 'Filename') }}</label>
          <div class="text-with-toggle-row">
            <NcTextField
              :value="filters.filename"
              @update:value="onFieldInput('filename', $event)"
              :label-outside="true"
              :placeholder="t('memories', 'e.g. IMG_2024')"
            />
            <NcCheckboxRadioSwitch type="switch" :checked="filters.filenameRegex" @update:checked="onRegexToggleChange('filenameRegex', $event)">
              {{ t('memories', 'Regex') }}
            </NcCheckboxRadioSwitch>
          </div>
        </div>
        <div class="filter-section">
          <label class="filter-label">{{ t('memories', 'Folder path') }}</label>
          <div class="text-with-toggle-row">
            <NcTextField
              :value="filters.filepath"
              @update:value="onFieldInput('filepath', $event)"
              :label-outside="true"
              :placeholder="t('memories', 'e.g. Vacation/2024')"
            />
            <NcCheckboxRadioSwitch type="switch" :checked="filters.filepathRegex" @update:checked="onRegexToggleChange('filepathRegex', $event)">
              {{ t('memories', 'Regex') }}
            </NcCheckboxRadioSwitch>
          </div>
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
            <NcActions :aria-label="t('memories', 'Date presets')" :container="false">
              <template #icon><DotsHorizontalIcon :size="20" /></template>
              <NcActionButton @click="applyDatePreset('last7')">{{ t('memories', 'Last 7 days') }}</NcActionButton>
              <NcActionButton @click="applyDatePreset('last30')">{{ t('memories', 'Last 30 days') }}</NcActionButton>
              <NcActionButton @click="applyDatePreset('thisMonth')">{{ t('memories', 'This month') }}</NcActionButton>
              <NcActionButton @click="applyDatePreset('thisYear')">{{ t('memories', 'This year') }}</NcActionButton>
            </NcActions>
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
            <NcCheckboxRadioSwitch :checked="filters.favorite" @update:checked="onFavoriteChange">
              {{ t('memories', 'Favorites only') }}
            </NcCheckboxRadioSwitch>
          </div>
        </div>

        <!-- File extension -->
        <div class="filter-section">
          <label class="filter-label">{{ t('memories', 'File extension') }}</label>
          <ClusterValuePicker field="extension" :multiple="true" :model-value="filters.extensions" @update:modelValue="onExtensionsChange" :disabled="disabled" />
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

        <!-- Rating range -->
        <div class="filter-section">
          <label class="filter-label">{{ t('memories', 'Rating') }}</label>
          <div class="rating-row">
            <select class="rating-select" v-model.number="filters.minRating" @change="emitFilterChange">
              <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <span class="rating-sep">{{ t('memories', 'to') }}</span>
            <select class="rating-select" v-model.number="filters.maxRating" @change="emitFilterChange">
              <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <!-- Simple all-or-nothing tag pickers + untagged toggles -->
        <div v-if="systemtagsEnabled" class="filter-section">
          <label class="filter-label">{{ t('memories', 'Collaborative tags') }}</label>
          <ClusterValuePicker field="tag" :multiple="true" :model-value="filters.tags" @update:modelValue="onTagsChange" :disabled="disabled || filters.untaggedCollab" />
          <NcCheckboxRadioSwitch type="switch" :checked="filters.untaggedCollab" @update:checked="onUntaggedCollabChange">
            {{ t('memories', 'No collaborative tags') }}
          </NcCheckboxRadioSwitch>
        </div>
        <div v-if="embeddedTagsEnabled" class="filter-section">
          <label class="filter-label">{{ t('memories', 'Embedded tags') }}</label>
          <ClusterValuePicker
            field="embeddedTag"
            :multiple="true"
            :model-value="filters.embeddedTags"
            @update:modelValue="onEmbeddedTagsChange"
            :disabled="disabled || filters.untaggedEmbedded"
          />
          <NcCheckboxRadioSwitch type="switch" :checked="filters.untaggedEmbedded" @update:checked="onUntaggedEmbeddedChange">
            {{ t('memories', 'No embedded tags') }}
          </NcCheckboxRadioSwitch>
        </div>
      </template>

      <template v-else>
        <!-- Advanced boolean expression builder over every filterable field -->
        <div class="filter-section">
          <div class="filter-label-row">
            <label class="filter-label">{{ t('memories', 'Advanced filter') }}</label>
            <NcButton v-if="filters.filterExpr" type="tertiary-no-background" @click="clearFilterExpr" :disabled="disabled">
              {{ t('memories', 'Clear') }}
            </NcButton>
          </div>
          <FilterExpressionBuilder :model-value="filters.filterExpr" :disabled="disabled" @update:modelValue="onFilterExprChange" />
        </div>
      </template>

      <!-- Saved filters -->
      <div class="filter-section">
        <div class="filter-label-row">
          <label class="filter-label">{{ t('memories', 'Saved filters') }}</label>
          <NcButton type="tertiary-no-background" @click="saveCurrentFilter" :disabled="!hasActiveFilters">
            {{ t('memories', 'Save current') }}
          </NcButton>
        </div>
        <ul v-if="savedFilters.length > 0" class="saved-filters-list">
          <li v-for="saved in savedFilters" :key="saved.id" class="saved-filter-item">
            <button type="button" class="saved-filter-name" @click="applySavedFilter(saved)">{{ saved.name }}</button>
            <NcButton type="tertiary-no-background" :aria-label="t('memories', 'Delete saved filter')" @click="deleteSavedFilter(saved)">
              <template #icon><CloseIcon :size="14" /></template>
            </NcButton>
          </li>
        </ul>
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
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import FilterExpressionBuilder from './FilterExpressionBuilder.vue';
import ClusterValuePicker from './ClusterValuePicker.vue';
import { defineComponent, type PropType } from 'vue';
import { emptyFilters, hasActiveFilters } from '@services/filters';
import savedFiltersService, { type SavedFilter } from '@services/saved-filters';
import config from '@services/static-config';

function toDateStr(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

type ITextField = 'filename' | 'filepath' | 'camMake' | 'camModel' | 'camLens';

export default defineComponent({
  name: 'FilterComponent',

  components: {
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    NcActions,
    NcActionButton,
    CloseIcon,
    DotsHorizontalIcon,
    FilterExpressionBuilder,
    ClusterValuePicker,
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
    activeTab: 'basic' as 'basic' | 'advanced',
  }),

  computed: {
    hasActiveFilters(): boolean {
      return hasActiveFilters(this.filters);
    },

    ratingOptions(): { value: number; label: string }[] {
      return [0, 1, 2, 3, 4, 5].map((n) => ({ value: n, label: 0 === n ? t('memories', 'Any') : '★'.repeat(n) }));
    },

    savedFilters(): SavedFilter[] {
      return savedFiltersService.list;
    },

    systemtagsEnabled(): boolean {
      return config.getSync('systemtags_enabled');
    },

    embeddedTagsEnabled(): boolean {
      return config.getSync('embeddedtags_enabled');
    },
  },

  created() {
    savedFiltersService.init();
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

    onFieldInput(field: ITextField, value: string) {
      this.filters[field] = value;
      this.emitFilterChange();
    },

    onRegexToggleChange(field: 'filenameRegex' | 'filepathRegex', checked: boolean) {
      this.filters[field] = checked;
      this.emitFilterChange();
    },

    onFilterExprChange(value: IFilters['filterExpr']) {
      this.filters.filterExpr = value;
      this.emitFilterChange();
    },

    clearFilterExpr() {
      this.filters.filterExpr = null;
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

    onExtensionsChange(values: string[]) {
      // ClusterValuePicker already normalizes each value (strip dot, lowercase) for field="extension"
      this.filters.extensions = values;
      this.emitFilterChange();
    },

    onTagsChange(values: string[]) {
      this.filters.tags = values;
      if (values.length > 0) {
        this.filters.untaggedCollab = false;
      }
      this.emitFilterChange();
    },

    onEmbeddedTagsChange(values: string[]) {
      this.filters.embeddedTags = values;
      if (values.length > 0) {
        this.filters.untaggedEmbedded = false;
      }
      this.emitFilterChange();
    },

    onUntaggedCollabChange(checked: boolean) {
      this.filters.untaggedCollab = checked;
      if (checked) {
        this.filters.tags = [];
      }
      this.emitFilterChange();
    },

    onUntaggedEmbeddedChange(checked: boolean) {
      this.filters.untaggedEmbedded = checked;
      if (checked) {
        this.filters.embeddedTags = [];
      }
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

    async saveCurrentFilter() {
      const name = (await utils.prompt({
        title: t('memories', 'Save filter'),
        message: t('memories', 'Save the current filters under this name'),
        name: t('memories', 'Name'),
      }))?.trim();

      if (!name) return;

      savedFiltersService.save(name, { ...this.filters });
    },

    applySavedFilter(saved: SavedFilter) {
      this.filters = { ...emptyFilters(), ...saved.filters };
      this.emitFilterChange();
    },

    async deleteSavedFilter(saved: SavedFilter) {
      const confirmed = await utils.confirmDestructive({
        title: t('memories', 'Delete saved filter'),
        message: t('memories', 'Are you sure you want to delete "{name}"?', { name: saved.name }),
        confirm: t('memories', 'Delete'),
      });

      if (!confirmed) return;

      savedFiltersService.remove(saved.id);
    },

    t,
  },
});
</script>

<style lang="scss" scoped>
.filter-component {
  padding: 8px 12px;
  min-width: 340px;
  max-width: 480px;
  max-height: 70vh;
  overflow-y: auto;

  // Shrinks the default height of every NcSelect/NcButton/NcTextField/
  // NcCheckboxRadioSwitch inside the panel (same technique Searchbar.vue
  // uses) -- this panel packs far more controls per row than these
  // components' touch-friendly default size assumes.
  --default-clickable-area: 34px;

  form {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
}

// Mirrors TagsTabBar.vue's .tags-tab-bar/.tab styling for visual consistency
// with the app's other tabbed view (Collaborative/Embedded tags).
.filter-tabs {
  display: flex;
  justify-content: center;
  gap: 4px;
  padding: 0 8px 8px;
  border-bottom: 1px solid var(--color-border);

  .tab {
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 12px;
    font-size: 0.95em;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    border-radius: 0;

    &:hover {
      color: var(--color-main-text);
    }

    &.active {
      color: var(--color-main-text);
      border-bottom-color: var(--color-primary-element);
    }
  }
}

.advanced-override-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 8px;
  border-radius: var(--border-radius);
  background: var(--color-warning, var(--color-background-hover));
  color: var(--color-main-text);
  font-size: 12px;
}

.filter-section {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-label {
  font-weight: 600;
  font-size: 13px;
  color: var(--color-text-darker);
}

.filter-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.text-with-toggle-row {
  display: flex;
  align-items: center;
  gap: 6px;

  > *:first-child {
    flex: 1;
    min-width: 0;
  }
}

.rating-row {
  display: flex;
  align-items: center;
  gap: 6px;

  .rating-select {
    flex: 1;
    min-width: 0;
    padding: 4px 6px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
  }

  .rating-sep {
    color: var(--color-text-maxcontrast);
    font-size: 12px;
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

.filetype-row,
.camera-row {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.camera-row > * {
  flex: 1 1 100px;
}

.saved-filters-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.saved-filter-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
}

.saved-filter-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
  padding: 4px 6px;
  border: none;
  border-radius: var(--border-radius);
  background: transparent;
  color: var(--color-main-text);
  cursor: pointer;

  &:hover {
    background: var(--color-background-hover);
  }
}

.filter-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 4px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border);
}
</style>
