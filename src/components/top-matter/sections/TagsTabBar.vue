<template>
  <div class="tags-tab-bar">
    <button type="button" class="tab" :class="{ active: routeIsTags }" @click="switchTo('Tags')">
      {{ t('memories', 'Collaborative') }}
    </button>
    <button type="button" class="tab" :class="{ active: routeIsEmbeddedTags }" @click="switchTo('EmbeddedTags')">
      {{ t('memories', 'Embedded') }}
    </button>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'TagsTabBar',

  methods: {
    switchTo(route: 'Tags' | 'EmbeddedTags'): void {
      const name = _m.routes[route].name;
      if (this.$route.name === name) return;
      this.$router.push({ name });
    },
  },
});
</script>

<style lang="scss" scoped>
.tags-tab-bar {
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
</style>
