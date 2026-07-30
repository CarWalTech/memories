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
    :append-to-body="false"
    :taggable="taggable"
    :create-option="createOption"
    @option:created="onOptionCreated"
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
 *   fileType               -> mimetype pattern, e.g. "image/%" (fixed vocabulary, no dav call)
 *   extension              -> lowercase extension without the dot, e.g. "jpg"
 *                              (free text, no fixed vocabulary -- taggable)
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
      return 'extension' === this.field ? t('memories', 'Type an extension…') : t('memories', 'Select a value…');
    },

    /** No fixed vocabulary exists for extensions -- let the user type arbitrary values. */
    taggable(): boolean {
      return 'extension' === this.field;
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
        const normalize = (o: IOption): IOption =>
          'extension' === this.field ? { value: o.value.replace(/^\.+/, '').toLowerCase(), label: o.label } : o;

        if (this.multiple) {
          this.$emit('update:modelValue', ((val as IOption[]) ?? []).map((o) => normalize(o).value));
        } else {
          const o = val as IOption | null;
          this.$emit('update:modelValue', o ? normalize(o).value : null);
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
          case 'fileType': {
            // Fixed vocabulary, no dav call -- mirrors the Basic tab's Photos/Videos toggles.
            this.options = [
              { value: 'image/%', label: t('memories', 'Photos') },
              { value: 'video/%', label: t('memories', 'Videos') },
            ];
            break;
          }
          case 'extension': {
            // No fixed vocabulary -- purely taggable, nothing to preload.
            this.options = [];
            break;
          }
        }
      } catch (e) {
        console.error('Failed to load filter options for field', this.field, e);
      } finally {
        this.loading = false;
      }
    },

    /**
     * vue-select's default createOption() only returns a proper {label,value}
     * object if an option of that shape already exists in the list -- for a
     * taggable field that starts empty (extension has no fixed vocabulary),
     * it falls back to returning the raw typed string instead, which then
     * breaks the `selected` setter's `o.value` access above. Always return
     * the correct shape ourselves instead of relying on that fallback.
     */
    createOption(text: string): IOption {
      const value = 'extension' === this.field ? text.trim().replace(/^\.+/, '').toLowerCase() : text.trim();
      return { value, label: text };
    },

    /** Keep a newly-typed tag in `options` so it stays selectable/visible if the dropdown reopens. */
    onOptionCreated(option: IOption) {
      if (!this.options.some((o) => o.value === option.value)) {
        this.options = [...this.options, option];
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
