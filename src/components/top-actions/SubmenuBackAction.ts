import { defineComponent, type CreateElement, type RenderContext, type VNode } from 'vue';

import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';

import { translate as t } from '@services/l10n';

import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue';

/**
 * The "< Back" header every drill-down sub-panel opens with, plus its
 * separator. Emits `back`.
 *
 * Functional (and returning an *array* of vnodes) on purpose: `NcActions`
 * drops its slot children straight into its own `<ul>`, so each one has to be
 * an `<li class="action">` in its own right -- an ordinary single-root SFC
 * can't contribute two siblings. A functional component is expanded into the
 * parent's children before `NcActions` ever sees them, so what it receives are
 * still plain `NcActionButton`/`NcActionSeparator` vnodes and its own
 * inline-promotion and semantic-type detection behave exactly as they do for
 * hand-written markup.
 */
export default defineComponent({
  functional: true,

  render(h: CreateElement, ctx: RenderContext): any {
    const back = ctx.listeners.back as ((...args: any[]) => void) | undefined;

    return [
      h(
        NcActionButton,
        {
          props: { ariaLabel: t('memories', 'Back') },
          on: { click: () => back?.() },
          scopedSlots: { icon: () => h(ArrowLeftIcon, { props: { size: 20 } }) as VNode },
        },
        [t('memories', 'Back')],
      ),
      h(NcActionSeparator),
    ];
  },
});
