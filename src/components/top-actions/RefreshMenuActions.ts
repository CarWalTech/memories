import { defineComponent, type CreateElement, type PropType, type RenderContext, type VNode } from 'vue';

import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCaption from '@nextcloud/vue/dist/Components/NcActionCaption.js';
import NcActionSeparator from '@nextcloud/vue/dist/Components/NcActionSeparator.js';
import SubmenuBackAction from './SubmenuBackAction';

import { translate as t } from '@services/l10n';
import { requestFullIndexRefresh } from '@services/index-refresh';

import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

export interface RefreshSectionButton {
  label: string;
  onClick: () => void;
  disabled?: boolean;
}

export interface RefreshSection {
  /** Omit for an unlabeled section -- fine when there's only one scope, so nothing needs disambiguating. */
  caption?: string;
  buttons: RefreshSectionButton[];
}

/** Default sections: today's whole-library refresh, unlabeled since it's the only scope on offer. */
const DEFAULT_SECTIONS = (): RefreshSection[] => [
  {
    buttons: [
      { label: t('memories', 'Quick scan'), onClick: () => requestFullIndexRefresh('untracked') },
      { label: t('memories', 'Full reindex'), onClick: () => requestFullIndexRefresh('all') },
    ],
  },
];

/**
 * The whole Refresh menu -- entry button *and* sub-panel body -- for any
 * `NcActions` dropdown, driven by the parent's panel state (see the `Submenu`
 * mixin):
 *
 * - `panel === root`: the "Refresh" entry button, always `is-menu` so it
 *   carries the same chevron as every other drill-down in the app.
 * - `panel === name`: the Back header, then each of `sections` in order --
 *   a caption (if the section has one) followed by its buttons, with a
 *   separator between sections. A caller with more than one scope on offer
 *   (e.g. "this folder" vs "this folder + subfolders" in `FolderTopMatter`)
 *   passes a `caption` per section so scope is only ever named once, never
 *   repeated per-button -- that's what keeps two sections' buttons of the
 *   same name from reading as ambiguous.
 * - `panel === null`: flat mode -- just the sections, for a dropdown that is
 *   nothing but Refresh (see `RefreshOptionsAction.vue`).
 *
 * `sections` defaults to the single unlabeled whole-library pair, so callers
 * with only one scope (`RefreshOptionsAction`, `AlbumTopMatter`,
 * `EmbeddedTagTopMatter`) don't pass anything.
 *
 * Functional, returning an array of vnodes -- see `SubmenuBackAction` for why.
 */
export default defineComponent({
  functional: true,

  props: {
    /** The enclosing dropdown's current panel, or null for flat mode. */
    panel: {
      type: String,
      default: null,
    },
    /** Panel name this submenu owns. */
    name: {
      type: String,
      default: 'refresh',
    },
    /** Panel name the entry button is shown on. */
    root: {
      type: String,
      default: 'root',
    },
    sections: {
      type: Array as PropType<RefreshSection[]>,
      default: DEFAULT_SECTIONS,
    },
  },

  render(h: CreateElement, ctx: RenderContext): any {
    const { panel, name, root, sections } = ctx.props as {
      panel: string | null;
      name: string;
      root: string;
      sections: RefreshSection[];
    };

    if (panel !== null && panel === root) {
      return [
        h(
          NcActionButton,
          {
            props: { ariaLabel: t('memories', 'Refresh'), isMenu: true },
            on: { click: () => (ctx.listeners.open as (() => void) | undefined)?.() },
            scopedSlots: { icon: () => h(RefreshIcon, { props: { size: 20 } }) as VNode },
          },
          [t('memories', 'Refresh')],
        ),
      ];
    }

    if (panel !== null && panel !== name) return [];

    const nodes: VNode[] = [];

    if (panel !== null) {
      // SubmenuBackAction is itself functional, so this returns an array, not a single VNode -- spread it
      // rather than push, so the result is correct regardless of whether the caller's own template happens
      // to trigger Vue's deep (recursive) child normalization.
      const back = h(SubmenuBackAction, {
        on: { back: () => (ctx.listeners.back as (() => void) | undefined)?.() },
      }) as unknown as VNode | VNode[];
      nodes.push(...(Array.isArray(back) ? back : [back]));
    }

    sections.forEach((section, i) => {
      if (i > 0) nodes.push(h(NcActionSeparator));
      if (section.caption) nodes.push(h(NcActionCaption, { props: { name: section.caption } }));

      for (const button of section.buttons) {
        nodes.push(
          h(
            NcActionButton,
            { props: { closeAfterClick: true, disabled: !!button.disabled }, on: { click: button.onClick } },
            [button.label],
          ),
        );
      }
    });

    return nodes;
  },
});
