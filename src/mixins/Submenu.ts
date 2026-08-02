import { defineComponent } from 'vue';

/** Panel name every submenu returns to. */
export const ROOT_PANEL = 'root';

/**
 * Drill-down panel state for an `NcActions` dropdown.
 *
 * `NcActions` has no nested-menu support of its own, so a submenu is really
 * one dropdown swapping its whole contents: the root panel shows an entry
 * button (`is-menu`, for the chevron), and the sub-panel shows a Back header
 * (`SubmenuBackAction`) followed by its own items. Bind `@closed="closePanel"`
 * on the `NcActions` so reopening it always starts at the root.
 */
export default defineComponent({
  name: 'Submenu',

  data: () => ({
    panel: ROOT_PANEL as string,
  }),

  methods: {
    openPanel(name: string): void {
      this.panel = name;
    },

    closePanel(): void {
      this.panel = ROOT_PANEL;
    },
  },
});
