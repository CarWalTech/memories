<template>
  <NcActions :title="t('memories', 'Sorting order')" :forceMenu="true">
    <template #icon>
      <template v-if="isDateSort">
        <SortDateDIcon v-if="isDescending" :size="20" />
        <SortDateAIcon v-else :size="20" />
      </template>
      <template v-else-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.NAME">
        <SlotAlphabeticalDIcon v-if="isDescending" :size="20" />
        <SlotAlphabeticalAIcon v-else :size="20" />
      </template>
      <template v-else>
        <SortIcon :size="20" />
      </template>
    </template>

    <NcActionRadio
      name="sort"
      :aria-label="t('memories', 'Last updated')"
      :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.LAST_UPDATE)"
      @change="changeSort(c.ALBUM_SORT_FLAGS.LAST_UPDATE)"
      close-after-click
    >
      {{ t('memories', 'Last updated') }}
    </NcActionRadio>

    <NcActionRadio
      name="sort"
      :aria-label="t('memories', 'Creation date')"
      :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.CREATED)"
      @change="changeSort(c.ALBUM_SORT_FLAGS.CREATED)"
      close-after-click
    >
      {{ t('memories', 'Creation date') }}
    </NcActionRadio>

    <NcActionRadio
      name="sort"
      :aria-label="t('memories', 'Album name')"
      :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.NAME)"
      @change="changeSort(c.ALBUM_SORT_FLAGS.NAME)"
      close-after-click
    >
      {{ t('memories', 'Album name') }}
    </NcActionRadio>

    <NcActionSeparator />

    <NcActionRadio
      name="sort-dir"
      :aria-label="isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending')"
      :checked="!isDescending"
      @change="setDescending(false)"
      close-after-click
    >
      {{ isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending') }}
    </NcActionRadio>

    <NcActionRadio
      name="sort-dir"
      :aria-label="isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending')"
      :checked="isDescending"
      @change="setDescending(true)"
      close-after-click
    >
      {{ isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending') }}
    </NcActionRadio>
  </NcActions>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionRadio from '@nextcloud/vue/dist/Components/NcActionRadio.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';

import SortIcon from 'vue-material-design-icons/SortVariant.vue';
import SlotAlphabeticalAIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SlotAlphabeticalDIcon from 'vue-material-design-icons/SortAlphabeticalDescending.vue';
import SortDateAIcon from 'vue-material-design-icons/SortCalendarAscending.vue';
import SortDateDIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

export default defineComponent({
  name: 'SortAlbumsOptionsAction',

  components: {
    NcActions,
    NcActionRadio,
    NcActionSeparator,

    SortIcon,
    SlotAlphabeticalAIcon,
    SlotAlphabeticalDIcon,
    SortDateAIcon,
    SortDateDIcon,
  },

  mixins: [UserConfig],

  computed: {
    isDateSort(): boolean {
      return (
        !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.CREATED) ||
        !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.LAST_UPDATE)
      );
    },

    isDescending(): boolean {
      return !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.DESCENDING);
    },
  },

  methods: {
    /** Set sort choice */
    changeSort(flag: number) {
      const dir = this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.DESCENDING;
      this.config.album_list_sort = flag | dir;
      this.updateSetting('album_list_sort');
    },

    /** Set sort direction */
    setDescending(val: boolean) {
      if (val) {
        this.config.album_list_sort |= this.c.ALBUM_SORT_FLAGS.DESCENDING;
      } else {
        this.config.album_list_sort &= ~this.c.ALBUM_SORT_FLAGS.DESCENDING;
      }
      this.updateSetting('album_list_sort');
    },
  },
});
</script>
