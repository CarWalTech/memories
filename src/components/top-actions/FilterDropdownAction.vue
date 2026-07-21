<template>
  <NcActions :aria-label="t('memories', 'Filter photos')" :primary="hasActiveFilters">
    <template #icon>
      <FilterIcon :size="20" />
    </template>

    <FilterComponent :disabled="disabled" :initial-filters="currentFilters" @filter-change="onFilterChange" />
  </NcActions>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import { translate as t } from '@services/l10n';
import { bus } from '@services/utils';

import type { IFilters } from '@typings';
import { emptyFilters, hasActiveFilters } from '@services/filters';

import FilterIcon from 'vue-material-design-icons/FilterMenu.vue';
import FilterComponent from '@components/modal/FilterComponent.vue';

export default defineComponent({
  name: 'FilterDropdownAction',

  components: {
    FilterIcon,
    NcActions,
    FilterComponent,
  },

  props: {
    disabled: {
      type: Boolean,
      default: false,
    },
    initialFilters: {
      type: Object as PropType<IFilters>,
      default: emptyFilters,
    },
  },

  emits: {
    'filter-change': (filters: IFilters) => true,
  },

  data: () => ({
    currentFilters: emptyFilters(),
  }),

  mounted() {
    bus.on('memories:filters:changed', this.onFiltersChangedFromBus);
  },

  beforeUnmount() {
    bus.off('memories:filters:changed', this.onFiltersChangedFromBus);
  },

  computed: {
    hasActiveFilters(): boolean {
      return hasActiveFilters(this.currentFilters);
    },
  },

  methods: {
    onFiltersChangedFromBus(filters: IFilters) {
      this.currentFilters = { ...filters };
    },

    onFilterChange(filters: IFilters) {
      this.$emit('filter-change', filters);
    },

    t,
  },
});
</script>
