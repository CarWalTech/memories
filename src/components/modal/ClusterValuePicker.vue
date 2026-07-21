<template>
  <NcSelect
    class="cluster-value-picker"
    v-model="selected"
    :options="options"
    :loading="loading"
    :multiple="multiple"
    :placeholder="placeholder"
    :disabled="disabled"
    :keep-open="true"
    label="label"
    track-by="value"
  />
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js';

import * as dav from '@services/dav';
import { translate as t } from '@services/l10n';

import type { FilterField } from '@typings';

type IOption = { value: string; label: string };

/**
 * Generic leaf-value picker for the filter expression builder: given a
 * FilterField, loads the matching cluster list (Memories' own "clusters
 * actually used on some photo" endpoints, via @services/dav -- not
 * NcSelectTags' raw systemtags list, which would include tags never applied
 * to any photo) and emits the exact leaf `value` string format
 * FilterExpression.php expects for that field.
 *
 * value format per field (must match FilterExpression.php exactly):
 *   tag / place            -> String(cluster_id)              (numeric id)
 *   embeddedTag            -> String(cluster_id)                (flattened path,
 *                              already what EmbeddedTagsBackend::getClusterId() returns)
 *   recognize / facerecognition -> "<user_id>/<cluster_id>"    (id or name; either
 *                              is accepted server-side, see FilterExpression.php)
 */
export default defineComponent({
  name: 'ClusterValuePicker',

  components: { NcSelect },

  props: {
    field: {
      type: String as PropType<FilterField>,
      required: true,
    },
    /** Single value string, or array of value strings when multiple */
    modelValue: {
      type: [String, Array] as PropType<string | string[]>,
      default: null,
    },
    multiple: {
      type: Boolean,
      default: false,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:modelValue'],

  data: () => ({
    options: [] as IOption[],
    loading: false,
  }),

  computed: {
    placeholder(): string {
      return t('memories', 'Select a value…');
    },

    /** Bridge between NcSelect's option objects and the plain value string(s) this component exposes */
    selected: {
      get(): IOption | IOption[] | null {
        const byValue = new Map(this.options.map((o) => [o.value, o]));
        if (this.multiple) {
          return ((this.modelValue as string[]) ?? []).map((v) => byValue.get(v) ?? { value: v, label: v });
        }
        if (!this.modelValue) return null;
        return byValue.get(this.modelValue as string) ?? { value: this.modelValue as string, label: this.modelValue as string };
      },
      set(val: IOption | IOption[] | null) {
        if (this.multiple) {
          this.$emit('update:modelValue', ((val as IOption[]) ?? []).map((o) => o.value));
        } else {
          this.$emit('update:modelValue', (val as IOption | null)?.value ?? null);
        }
      },
    },
  },

  watch: {
    field: {
      immediate: true,
      handler() {
        this.loadOptions();
      },
    },
  },

  methods: {
    async loadOptions() {
      this.loading = true;
      this.options = [];

      try {
        switch (this.field) {
          case 'tag': {
            const tags = await dav.getTags();
            this.options = tags.map((c) => ({ value: String(c.cluster_id), label: c.display_name ?? c.name }));
            break;
          }
          case 'embeddedTag': {
            const tags = await dav.getEmbeddedTagClusters();
            this.options = tags.map((c) => ({ value: String(c.cluster_id), label: c.display_name ?? c.name }));
            break;
          }
          case 'recognize':
          case 'facerecognition': {
            const faces = await dav.getFaceList(this.field);
            this.options = faces.map((f) => ({ value: `${f.user_id}/${f.cluster_id}`, label: f.display_name ?? f.name }));
            break;
          }
          case 'place': {
            const places = await dav.getPlaces({ covers: 0 });
            this.options = places.map((c) => ({ value: String(c.cluster_id), label: c.display_name ?? c.name }));
            break;
          }
        }
      } catch (e) {
        console.error('Failed to load filter options for field', this.field, e);
      } finally {
        this.loading = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.cluster-value-picker {
  width: 100%;

  :deep(.vs__dropdown-menu) {
    max-height: 200px;
  }
}
</style>
