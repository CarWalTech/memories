declare module '*.svg' {
  const content: string;
  export default content;
}

// Vue components can be imported with .vue extension
declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}

// External components cannot be imported with .vue extension
// Typed as `any` (not DefineComponent) because these ship as compiled .mjs/.cjs,
// so Volar can't introspect their slots and would otherwise type them as `{}`,
// flagging every named slot (e.g. #icon) as nonexistent.
declare module '@nextcloud/vue/dist/Components/*.js' {
  const component: any;
  export default component;
}

declare module 'vue-virtual-scroller';

declare module 'plyr/dist/plyr.mjs';
