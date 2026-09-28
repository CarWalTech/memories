<template>
  <SwipeRefresh
    class="memories-timeline container no-user-select"
    ref="container"
    match=".recycler"
    :refresh="softRefreshSync"
    :allowSwipe="allowSwipe"
    :state="state"
  >
    <!-- Static top matter -->
    <TopMatter ref="topmatter" :refreshing="!!loading" />

    <!-- No content found and nothing is loading -->
    <EmptyContent v-if="showEmpty" @reset-filters="resetFilters" :has-filters="hasFilters" />

    <!-- Top overlay showing date -->
    <TimelineTopOverlay
      ref="topOverlay"
      :heads="heads"
      :container="refs.container?.$el"
      :recycler="refs.recycler?.$el"
    />

    <!-- Main recycler view for rows -->
    <RecycleScroller
      ref="recycler"
      class="recycler hide-scrollbar"
      tabindex="1"
      :class="{ empty }"
      :items="list"
      :emit-update="true"
      :buffer="800"
      :skipHover="true"
      key-field="id"
      size-field="size"
      type-field="type"
      :updateInterval="100"
      @update="scrollChangeRecycler"
    >
      <template #before>
        <!-- Dynamic top matter, e.g. album or view name -->
        <div class="recycler-before" ref="recyclerBefore">
          <!-- Gap for mobile header -->
          <div class="mobile-header-top-gap"></div>

          <!-- Route-specific top matter -->
          <DynamicTopMatter
            ref="dtm"
            :selection-manager="refs.selectionManager"
            @load="refs.scrollerManager.adjust()"
          />
        </div>
      </template>

      <template v-slot="{ item, index }">
        <RowHead
          v-if="item.type === 0 && !item.collapsed"
          :item="item"
          :zoom-scale="photoZoomScale"
          @click="refs.selectionManager.selectHead(item)"
        />

        <template v-else>
          <Photo
            class="photo top-left"
            v-for="photo of item.photos"
            :key="photo.key"
            :style="{
              height: `${photo.dispH}px`,
              width: `${photo.dispW}px`,
              transform: `translate(${photo.dispX}px, ${photo.dispY}px)`,
            }"
            :data="photo"
            :day="item.day"
            :image-display-shape="effectiveShape"
            :image-props="effectiveImageProps"
            :zoom-scale="photoZoomScale"
            @select="refs.selectionManager.clickSelectionIcon(photo, $event, index)"
            @pointerdown="refs.selectionManager.clickPhoto(photo, $event, index)"
            @touchstart="refs.selectionManager.touchstartPhoto(photo, $event, index)"
            @touchend="refs.selectionManager.touchendPhoto(photo, $event, index)"
            @touchmove="refs.selectionManager.touchmovePhoto(photo, $event, index)"
          />
        </template>
      </template>
    </RecycleScroller>

    <!-- Managers -->
    <ScrollerManager
      ref="scrollerManager"
      v-show="!showEmpty"
      :rows="list"
      :fullHeight="scrollerHeight"
      :recycler="refs.recycler"
      :recyclerBefore="refs.recyclerBefore"
      @interactend="loadScrollView"
      @scroll="
        currentScroll = $event.current;
        refs.topOverlay?.refresh();
      "
    />

    <SelectionManager
      ref="selectionManager"
      :heads="heads"
      :rows="list"
      :isreverse="isGroupReverse"
      :recycler="refs.recycler?.$el"
      :scrollerManager="refs.scrollerManager"
      :fetchDay="fetchDay"
      @updateLoading="updateLoading"
    />
  </SwipeRefresh>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Route } from 'vue-router';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { getLayout } from '@services/layout';
import { DESKTOP_ROW_HEIGHTS, MOBILE_ROW_HEIGHTS, listRowHeight, clampZoom, zoomScale } from '@services/zoom';
import { autoScanFolderOnNavigate } from '@services/index-refresh';

import ViewPrefs from '@mixins/ViewPrefs';
import RowHead from '@components/frame/RowHead.vue';
import Photo from '@components/frame/Photo.vue';
import ScrollerManager from '@components/ScrollerManager.vue';
import SelectionManager from '@components/SelectionManager.vue';
import Viewer from '@components/viewer/Viewer.vue';
import SwipeRefresh from './modal/SwipeRefresh.vue';

import EmptyContent from './EmptyContent.vue';
import TopMatter from '@components/top-matter/TopMatter.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import TimelineTopOverlay from './modal/TimelineTopOverlay.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import { API, DaysFilterType } from '@services/API';
import { emptyFilters, hasActiveFilters } from '@services/filters';
import { sortScope, groupScope } from '@services/list-scope';
import { buildSegments, resegment } from '@services/segments';
import { listScopeTargetFor, type ListScopeTarget, type SortSetting, type GroupSetting } from '@services/sort-group-defs';

import type { IDay, IHeadRow, IPhoto, IRow, ISegment, IFilters } from '@typings';

const SCROLL_LOAD_DELAY = 100; // Delay in loading data when scrolling
const ROW_NUM_LPAD = 16; // Number of rows to load before and after viewport

export default defineComponent({
  name: 'Timeline',

  components: {
    RowHead,
    Photo,
    EmptyContent,
    TopMatter,
    DynamicTopMatter,
    TimelineTopOverlay,
    SelectionManager,
    ScrollerManager,
    Viewer,
    SwipeRefresh,
  },

  mixins: [ViewPrefs],

  emits: {
    daysLoaded: (stats: { count: number }) => true,
  },

  data: () => ({
    /** Loading days response */
    loading: 0,
    /** Main list of rows */
    list: [] as IRow[],
    /** Dynamic top matter has standalone content */
    dtmContent: false,
    /** Computed number of columns */
    numCols: 0,
    /** Ordered header rows for dayId key  */
    heads: new Map<number, IHeadRow>(),
    /** Current list (days response) was loaded from cache */
    daysIsCache: false,

    /** Current filters */
    filters: emptyFilters() as IFilters,

    /** Size of outer container [w, h] */
    containerSize: [0, 0] as [number, number],
    /** Computed row height */
    rowHeight: 100,
    /** Computed row width */
    rowWidth: 100,

    /** Current start index */
    currentStart: 0,
    /** Current end index */
    currentEnd: 0,
    /** Current physical scroll position */
    currentScroll: 0,
    /** Resize observer on the outer container */
    resizeObserver: null as ResizeObserver | null,
    /** Resize observer on the recycler's #before slot (e.g. FolderGrid) */
    beforeResizeObserver: null as ResizeObserver | null,
    /** Resizing timer */
    resizeTimer: null as number | null,
    /** Coalesces same-tick onUserConfigChanged calls into one recompute */
    userConfigChangedQueued: false,
    /** Height of the scroller */
    scrollerHeight: 100,

    /** All segments by id (see services/segments.ts) */
    segments: new Map<string, ISegment>(),
    /** Segments of each head, in display order */
    daySegments: new Map<number, ISegment[]>(),
    /** Real backend dayId -> the bucket (head) id it belongs to */
    realToBucket: new Map<number, number>(),

    /** Set of segment ids for which images loaded */
    loadedSegs: new Set<string>(),
    /** Set of segment ids for which image size is calculated */
    sizedSegs: new Set<string>(),
    /** Segments to load in the next call */
    fetchSegQueue: [] as string[],
    /** Timer to load day call */
    fetchDayTimer: null as number | null,
    /** In-flight day requests, so navigating away can abort them */
    fetchAborters: new Set<AbortController>(),

    /** State for request cancellations */
    state: Math.random(),
  }),

  mounted() {
    // Trigger initial state load
    this.routeChange(this.$route);

    // Start resize observer on container
    if (this.refs.container?.$el) {
      this.resizeObserver = new ResizeObserver(() => this.handleResizeWithDelay());
      this.resizeObserver.observe(this.refs.container.$el);
    }

    // Watch the #before slot's own size (e.g. FolderGrid growing/shrinking,
    // or switching between grid and list layout). Its height can change for
    // reasons that don't go through fetchDays() -- a reactive view-mode
    // toggle re-renders it immediately, and bus-driven folder mutations
    // (delete/rename) mutate it directly -- so without this, the recycler
    // keeps positioning items using a stale #before height and scrolling
    // can appear to stop working (items rendered at the wrong offsets, or
    // none at all once the real scroll position no longer maps to any of
    // them). updateVisibleItems() is a cheap re-measure, safe to call on
    // every observed change.
    if (this.refs.recyclerBefore instanceof Element) {
      this.beforeResizeObserver = new ResizeObserver(() => this.refs.recycler?.updateVisibleItems(false));
      this.beforeResizeObserver.observe(this.refs.recyclerBefore);
    }
  },

  unmounted() {
    this.resizeObserver?.disconnect();
    this.beforeResizeObserver?.disconnect();
  },

  watch: {
    async $route(to: Route, from?: Route) {
      await this.routeChange(to, from);
    },
  },

  created() {
    sortScope.init();
    groupScope.init();
    utils.bus.on('memories:user-config-changed', this.onUserConfigChanged);
    utils.bus.on('files:file:created', this.softRefresh);
    utils.bus.on('memories:window:resize', this.handleResizeWithDelay);
    utils.bus.on('memories:timeline:fetch-day', this.fetchDay);
    utils.bus.on('memories:timeline:deleted', this.deleteFromViewWithAnimation);
    utils.bus.on('memories:timeline:soft-refresh', this.softRefresh);
    utils.bus.on('memories:timeline:hard-refresh', this.refresh);
    utils.bus.on('memories:filters:changed', this.onFiltersChanged);
  },

  beforeDestroy() {
    utils.bus.off('memories:user-config-changed', this.onUserConfigChanged);
    utils.bus.off('files:file:created', this.softRefresh);
    utils.bus.off('memories:window:resize', this.handleResizeWithDelay);
    utils.bus.off('memories:timeline:fetch-day', this.fetchDay);
    utils.bus.off('memories:timeline:deleted', this.deleteFromViewWithAnimation);
    utils.bus.off('memories:timeline:soft-refresh', this.softRefresh);
    utils.bus.off('memories:timeline:hard-refresh', this.refresh);
    utils.bus.off('memories:filters:changed', this.onFiltersChanged);
    this.resetState();
    this.state = 0;
  },

  computed: {
    refs() {
      return this.$refs as {
        container?: InstanceType<typeof SwipeRefresh>;
        topmatter?: InstanceType<typeof TopMatter>;
        dtm?: InstanceType<typeof DynamicTopMatter>;
        topOverlay?: InstanceType<typeof TimelineTopOverlay>;
        recycler?: VueRecyclerType;
        recyclerBefore?: HTMLDivElement;
        selectionManager: InstanceType<typeof SelectionManager>;
        scrollerManager: InstanceType<typeof ScrollerManager>;
      };
    },

    routeHasNative(): boolean {
      return this.routeIsBase && nativex.has();
    },

    /** Scale factor for Photo.vue's overlay text/rating/tags, so content grows/shrinks along with the row/tile at each zoom level. */
    photoZoomScale(): number {
      return zoomScale(this.config.grid_zoom);
    },

    /** Which Sort by / Group by target governs this route (see @services/sort-group-defs). */
    listScopeTarget(): ListScopeTarget | null {
      return listScopeTargetFor(this.routeId, this.$route);
    },

    effectiveGroupSetting(): GroupSetting {
      return groupScope.resolve(this.listScopeTarget, this.scopeKey);
    },

    effectiveSortSetting(): SortSetting {
      return sortScope.resolve(this.listScopeTarget, this.scopeKey);
    },

    /** Whether day/week/month/year headers should be rendered. */
    isGrouped(): boolean {
      return this.effectiveGroupSetting.key !== 'none';
    },

    /**
     * Backend bucket granularity -- 'none' still buckets/paginates by day
     * internally (identical to 'day'), just without a visible header; see
     * isGrouped/RowHead's `v-if` in the template. Timeline never offers
     * 'name' grouping (see TIMELINE_FAMILY_DEF), so it's excluded here too.
     */
    backendGroupMode(): 'day' | 'week' | 'month' | 'year' {
      const key = this.effectiveGroupSetting.key;
      return key === 'none' || key === 'name' ? 'day' : key;
    },

    /** Whether the day-bucket list itself is in reverse (oldest-first) order. */
    isGroupReverse(): boolean {
      return this.isGrouped && this.effectiveGroupSetting.dir === 'asc';
    },

    /** Whether photos within each bucket are in reverse (ascending) order. */
    isSortReverse(): boolean {
      return this.effectiveSortSetting.dir === 'asc';
    },

    /** Nothing to show here */
    empty(): boolean {
      return !this.list.length && !this.dtmContent;
    },

    /** Whether any filters are applied */
    hasFilters(): boolean {
      return hasActiveFilters(this.filters);
    },

    /** Show the empty content box and hide the scrollbar */
    showEmpty(): boolean {
      return !this.loading && this.empty;
    },

    /** Whether to allow swipe refresh */
    allowSwipe(): boolean {
      return !this.loading && this.currentScroll === 0;
    },
  },

  methods: {
    async routeChange(to: Route, from?: Route) {
      // Always do a hard refresh if the path changes
      if (from?.path !== to.path) {
        await this.refresh();

        // Focus on the recycler (e.g. after navigation click)
        this.refs.recycler?.$el.focus();

        // Opportunistically pick up files that exist on disk but aren't
        // indexed yet (e.g. dropped onto external storage outside
        // Nextcloud's own write path). Fire-and-forget: never block
        // navigation on this, and it soft-refreshes the timeline itself
        // if it finds anything.
        if (this.routeIsFolders && !utils.isFoldersRootPicker(this.config.folders_path, this.$route.params.path)) {
          autoScanFolderOnNavigate(utils.getFolderRoutePath(this.config.folders_path));
        }

        // Embedded tags aren't tied to a single browsed folder (a photo
        // anywhere in the account can carry one, see EmbeddedTagsBackend),
        // so there's no equivalent single-folder path to scan here -- go
        // recursive over the configured root instead. Same rate-limited,
        // untracked-only scan as Folders above: catches newly-tagged files
        // that never went through Nextcloud's own write path without
        // waiting on the periodic index job.
        if (this.routeIsEmbeddedTags) {
          autoScanFolderOnNavigate(this.config.folders_path, true);
        }
      }

      // Do a soft refresh if the query changes
      else if (JSON.stringify(from.query) !== JSON.stringify(to.query)) {
        await this.softRefreshSync();
      }

      // Check if viewer is supposed to be open
      if (from?.hash !== to.hash && !_m.viewer.isOpen && utils.fragment.viewer) {
        // Open viewer
        const [dayidStr, key] = utils.fragment.viewer.args;
        const dayid = parseInt(dayidStr);
        if (isNaN(dayid) || !key) return;

        // Get day
        const day = this.heads.get(dayid)?.day;
        if (day && !day.detail) {
          const state = this.state;
          await this.fetchDay(dayid, true);
          if (state !== this.state) return;
        }

        // Find photo
        const photo = day?.detail?.find((p) => p.key === key);
        if (!photo) return;

        // Scroll to photo if initializing
        if (!from) {
          const index = this.list.findIndex((r) => r.day.dayid === dayid && r.photos?.includes(photo));
          if (index !== -1) {
            this.refs.recycler?.scrollToItem(index);
          }
        }

        _m.viewer.openDynamic(photo, this);
      }
    },

    updateLoading(delta: number): void {
      this.loading = Math.max(0, this.loading + delta);
    },

    isMobile() {
      return this.containerSize[0] <= 768;
    },

    isMobileLayout() {
      return this.containerSize[0] <= 600;
    },

    allowBreakout() {
      return _m.window.innerWidth <= 600 && this.effectiveShape === 'dynamic';
    },

    /** Create new state */
    async createState() {
      // Wait for one tick before doing anything
      await this.$nextTick();

      // Watch the static top matter's own size, not just the outer container.
      // NcBreadcrumbs/NcBreadcrumb are lazy-loaded via dynamic import(), so on
      // a cold cache the top matter can still grow (e.g. once the breadcrumb
      // row actually renders) after we measure it below. Without this, that
      // later growth never triggers a recompute, and the recycler (photos +
      // the dynamic top matter grid) stays sized as if the header were
      // shorter, ending up rendered too high and overlapping/clipped under it.
      if (this.refs.topmatter?.$el instanceof Element) {
        this.resizeObserver?.observe(this.refs.topmatter.$el);
      }

      // Fit to window
      this.recomputeSizes();

      // Timeline recycler init
      this.refs.recycler?.$el.addEventListener('scroll', this.scrollPositionChange, { passive: true });

      // Get data
      await this.fetchDays();
    },

    /** Reset all state */
    async resetState() {
      this.refs.selectionManager.clear();
      this.refs.scrollerManager.reset();
      this.loading = 0;
      this.list = [];
      this.dtmContent = false;
      this.heads = new Map();
      this.currentStart = 0;
      this.currentEnd = 0;
      this.state = Math.random();
      this.segments = new Map();
      this.daySegments = new Map();
      this.realToBucket = new Map();
      this.loadedSegs.clear();
      this.sizedSegs.clear();
      this.fetchSegQueue = [];
      this.abortFetches();
      window.clearTimeout(this.fetchDayTimer ?? 0);
      window.clearTimeout(this.resizeTimer ?? 0);
    },

    /**
     * Abort any in-flight day requests.
     *
     * Without this a response for the view the user just left still lands,
     * writing its photos into CacheStorage under a key derived from the
     * CURRENT query (getQuery() having since changed), and burning bandwidth
     * that the new view needs.
     */
    abortFetches() {
      for (const aborter of this.fetchAborters) aborter.abort();
      this.fetchAborters.clear();
    },

    /** Recreate everything */
    async refresh() {
      await this.resetState();
      await this.createState();
    },

    /**
     * Fetch and re-process days (debounced call)
     * Debouncing is necessary due to a large number of calls, e.g.
     * when changing the configuration
     */
    softRefresh() {
      this._softRefreshInternal(false);
    },

    /**
     * A user config setting changed -- force a grid recompute (e.g. zoom
     * level or square-thumbs, neither of which affects the container's own
     * size, so recomputeSizes() would otherwise no-op) in addition to the
     * regular data refresh.
     *
     * Some UI actions (e.g. switching view mode) update more than one
     * setting at once, which fires this once per setting synchronously --
     * coalesce same-tick calls into a single recompute via a microtask so
     * one user action never pays for the work twice.
     */
    onUserConfigChanged() {
      if (this.userConfigChangedQueued) return;
      this.userConfigChangedQueued = true;
      Promise.resolve().then(() => {
        this.userConfigChangedQueued = false;
        this.recomputeSizes(true);
        this.softRefresh();
      });
    },

    /** Fetch and re-process days (sync can be awaited) */
    async softRefreshSync() {
      await this._softRefreshInternal(true);
    },

    /**
     * Fetch and re-process days (can be awaited if sync).
     * Do not pass this function as a callback directly.
     */
    async _softRefreshInternal(sync: boolean) {
      this.refs.selectionManager.clear();
      this.fetchSegQueue = []; // reset queue

      // Fetch days
      if (sync) {
        await this.fetchDays(true);
      } else {
        utils.setRenewingTimeout(this, '_softRefreshInternalTimer', () => this.fetchDays(true), 30);
      }
    },

    /** Do resize after some time */
    handleResizeWithDelay() {
      utils.setRenewingTimeout(this, 'resizeTimer', this.recomputeSizes, 100);
    },

    /** Row height preset (px) for the current zoom level, clamped to the valid range */
    desktopRowHeight(): number {
      return DESKTOP_ROW_HEIGHTS[clampZoom(this.config.grid_zoom)];
    },

    /** Row height preset (px) for the current zoom level, mobile variant */
    mobileRowHeight(): number {
      return MOBILE_ROW_HEIGHTS[clampZoom(this.config.grid_zoom)];
    },

    /**
     * Recompute static sizes of containers.
     * @param force recompute the grid even if the container size itself
     *              hasn't changed -- needed when only the zoom level or
     *              square-thumbs setting changed (e.g. from the View Options
     *              menu), since neither affects the container's own size.
     */
    recomputeSizes(force: boolean = false) {
      // Get the container element
      const container = this.refs.container?.$el;
      if (!container) return;

      // Size of outer container
      const height = container.clientHeight;
      const width = container.clientWidth;
      this.containerSize = [width, height];

      // Scroller spans the container height
      this.scrollerHeight = height;

      // Static top matter to exclude from recycler height
      const topmatter = this.refs.topmatter;
      const tmHeight = topmatter?.$el?.clientHeight || 0;

      // Recycler height
      const recycler = this.refs.recycler!;
      const targetHeight = height - tmHeight - 4;
      const targetWidth = this.isMobile() ? width : width - 40;
      const heightChanged = recycler.$el.clientHeight !== targetHeight;
      const widthChanged = this.rowWidth !== targetWidth;

      if (heightChanged) {
        recycler.$el.style.height = targetHeight + 'px';
      }

      if (widthChanged) {
        this.rowWidth = targetWidth;
      }

      if (!force && !heightChanged && !widthChanged) {
        // If the target size is the same, nothing else could have
        // possibly changed either, so just skip
        return;
      }

      if (this.effectiveShape === 'list') {
        // List
        this.numCols = 1;
        this.rowHeight = listRowHeight(this.config.grid_zoom);
      } else if (this.isMobileLayout()) {
        // Mobile
        const rowHeight = this.mobileRowHeight();
        this.numCols = Math.max(3, Math.floor(this.rowWidth / rowHeight));
        this.rowHeight = Math.floor(this.rowWidth / this.numCols);
      } else {
        // Desktop
        const rowHeight = this.desktopRowHeight();
        if (this.effectiveShape === 'square' || this.effectiveShape === 'grid') {
          this.numCols = Math.max(3, Math.floor(this.rowWidth / rowHeight));
          this.rowHeight = Math.floor(this.rowWidth / this.numCols);
        } else {
          // As a heuristic, assume all images are 4:3 landscape
          this.rowHeight = rowHeight;
          this.numCols = Math.ceil(this.rowWidth / ((this.rowHeight * 4) / 3));
        }
      }

      // Reflow if there are elements (this isn't an init call)
      // An init call reaches here when the top matter size changes
      if (this.list.length > 0) {
        // At this point we're sure the size has changed, so we need
        // to invalidate everything related to sizes
        this.sizedSegs.clear();
        this.refs.scrollerManager.adjust();

        // Explicitly request a scroll event
        this.loadScrollView();
      }
    },

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: Event) {
      this.refs.scrollerManager.recyclerScrolled(event ?? null);
    },

    /** Trigger when recycler view changes (for callback) */
    scrollChangeRecycler(startIndex: number, endIndex: number) {
      return this.scrollChange(startIndex, endIndex);
    },

    /** Trigger when recycler view changes to refresh view */
    scrollChange(startIndex: number, endIndex: number, force = false) {
      if (startIndex === this.currentStart && endIndex === this.currentEnd && !force) {
        return;
      }

      // Reset placeholder state for rows including padding
      const rmin = Math.max(0, startIndex - ROW_NUM_LPAD);
      const rmax = Math.min(this.list.length, endIndex + ROW_NUM_LPAD);
      for (let i = rmin; i < rmax; i++) {
        const row = this.list[i];
        if (!row) {
          continue;
        }

        // Initialize photos and add placeholders
        if (row.pct && !row.photos?.length) {
          row.photos = new Array(row.pct);
          for (let j = 0; j < row.pct; j++) {
            // Any row that has placeholders has ONLY placeholders
            // so we can calculate the display width
            row.photos[j] = {
              flag: this.c.FLAG_PLACEHOLDER,
              fileid: Math.random(),
              dayid: row.dayId,
              dispW: utils.roundHalf(this.rowWidth / this.numCols),
              dispX: utils.roundHalf((j * this.rowWidth) / this.numCols),
              dispH: this.rowHeight,
              dispY: 0,
            };
          }
        }

        // No need for the fake count regardless of what happened above
        delete row.pct;
      }

      // We only need to debounce loads if the user is dragging the scrollbar
      const scrolling = this.refs.scrollerManager.interacting;

      // Make sure we don't do this too often
      this.currentStart = startIndex;
      this.currentEnd = endIndex;

      // Check if we can do this immediately
      const delay = force || !scrolling ? 0 : SCROLL_LOAD_DELAY;

      // Debounce; only execute the newest call after delay
      utils.setRenewingTimeout(this, '_scrollChangeTimer', this.loadScrollView, delay);
    },

    /** Load image data for given view (index based) */
    loadScrollView(startIndex?: number, endIndex?: number) {
      // Default values if not defined
      startIndex ??= this.currentStart;
      endIndex ??= this.currentEnd;

      // Check if any side needs a padding.
      // Whenever less than half rows are loaded, we need to pad with full
      // rows on that side. This ensures we have minimal reflows.
      const rmin = Math.max(0, startIndex - ROW_NUM_LPAD / 2);
      const rmax = Math.min(this.list.length - 1, endIndex + ROW_NUM_LPAD / 2);
      const notsized = (r: IRow) => r && !this.sizedSegs.has(r.segId);

      // Check at the start
      if (this.list.slice(rmin, startIndex).some(notsized)) {
        startIndex -= ROW_NUM_LPAD;
      }

      // Check at the end
      if (this.list.slice(endIndex + 1, rmax + 1).some(notsized)) {
        endIndex += ROW_NUM_LPAD;
      }

      // Make sure start and end valid
      startIndex = Math.max(0, startIndex);
      endIndex = Math.min(this.list.length - 1, endIndex);

      // Fetch all visible segments. Every row already knows which segment
      // owns it (stamped in processDays), so the visible range maps to the
      // chunks that need fetching without any offset arithmetic here.
      for (let i = startIndex; i <= endIndex; i++) {
        const item = this.list[i];
        if (!item) continue;
        if (this.loadedSegs.has(item.segId)) {
          if (!this.sizedSegs.has(item.segId)) {
            // Just quietly reflow without refetching
            const seg = this.segments.get(item.segId);
            if (seg?.photos) this.processSegment(seg, seg.photos);
          }
          continue;
        }

        this.fetchSegment(item.segId);
      }
    },

    /** Get query string for API calls */
    getQuery() {
      const query: { [key in DaysFilterType]?: string } = {};
      const set = (filter: DaysFilterType, value: string = '1') => (query[filter] = value);

      // Advanced tab's boolean expression (see FilterExpression.php) is
      // always sent when present, and SUPERSEDES every Basic-tab flat filter
      // below -- so every one of those is skipped entirely in that case, to
      // stay symmetric with the same gate in
      // DaysController::getTransformations() and the PHP verify pass in
      // TimelineQueryDays (postProcessDaysWithFilters()/getDay()).
      if (this.filters.filterExpr) {
        set(DaysFilterType.FILTER_EXPR, encodeURIComponent(JSON.stringify(this.filters.filterExpr)));
      } else {
        // Rating range
        if (this.filters.minRating > 0) {
          set(DaysFilterType.RATING, this.filters.minRating.toString());
        }
        if (this.filters.maxRating > 0) {
          set(DaysFilterType.MAX_RATING, this.filters.maxRating.toString());
        }

        // Filename / filepath
        if (this.filters.filename) {
          set(DaysFilterType.FILENAME, this.filters.filename);
          if (this.filters.filenameRegex) {
            set(DaysFilterType.FILENAME_REGEX);
          }
        }
        if (this.filters.filepath) {
          set(DaysFilterType.FILEPATH, this.filters.filepath);
          if (this.filters.filepathRegex) {
            set(DaysFilterType.FILEPATH_REGEX);
          }
        }

        // Date range (plain "YYYY-MM-DD", resolved to a dayId range server-side)
        if (this.filters.dateStart) {
          set(DaysFilterType.DATE_START, this.filters.dateStart);
        }
        if (this.filters.dateEnd) {
          set(DaysFilterType.DATE_END, this.filters.dateEnd);
        }

        // File type
        if (this.filters.fileType.length > 0) {
          set(DaysFilterType.FILE_TYPE, this.filters.fileType.map((p) => encodeURIComponent(p)).join(','));
        }

        // File extension
        if (this.filters.extensions.length > 0) {
          set(DaysFilterType.EXTENSION, this.filters.extensions.map((e) => encodeURIComponent(e)).join(','));
        }

        // Camera
        if (this.filters.camMake) {
          set(DaysFilterType.CAM_MAKE, this.filters.camMake);
        }
        if (this.filters.camModel) {
          set(DaysFilterType.CAM_MODEL, this.filters.camModel);
        }
        if (this.filters.camLens) {
          set(DaysFilterType.CAM_LENS, this.filters.camLens);
        }

        // Simple all-or-nothing (AND) tag pickers
        if (this.filters.tags.length > 0) {
          set(DaysFilterType.COLLAB_TAG_IDS, this.filters.tags.map((t) => encodeURIComponent(t)).join(','));
        }
        if (this.filters.embeddedTags.length > 0) {
          set(DaysFilterType.EMBEDDED_TAGS, this.filters.embeddedTags.map((t) => encodeURIComponent(t)).join(','));
        }

        // Untagged toggles
        if (this.filters.untaggedCollab) {
          set(DaysFilterType.UNTAGGED_COLLAB);
        }
        if (this.filters.untaggedEmbedded) {
          set(DaysFilterType.UNTAGGED_EMBEDDED);
        }

        // Favorites filter (from the filter panel; distinct from routeIsFavorites below,
        // which is the dedicated "/favorites" view -- both just set the same flag)
        if (this.filters.favorite) {
          set(DaysFilterType.FAVORITES);
        }
      }

      // Collaborative Tags Image Property -- only ask the backend for the extra
      // per-photo tag names (a real JOIN, unlike embedded tags) when the overlay
      // is actually on and collaborative tags are enabled for this user
      if (this.effectiveImageProps.show_collab_tags_in_gallery && this.config.systemtags_enabled) {
        set(DaysFilterType.COLLAB_TAGS);
      }

      // Favorites
      if (this.routeIsFavorites) {
        set(DaysFilterType.FAVORITES);
      }

      // Videos
      if (this.routeIsVideos) {
        set(DaysFilterType.VIDEOS);
      }

      // Folder
      if (this.routeIsFolders || this.routeIsFolderShare) {
        // At the Folders-view root picker (multiple roots configured, no
        // folder chosen yet) there's no single folder to query photos for --
        // the root tiles alone are the content. Throwing here is caught by
        // fetchDays' "likely invalid route" handling, which runs after the
        // dynamic top matter (the root tiles) has already refreshed.
        if (this.routeIsFolders && utils.isFoldersRootPicker(this.config.folders_path, this.$route.params.path)) {
          throw new Error('Folders root picker: no folder selected');
        }

        const path = utils.getFolderRoutePath(this.config.folders_path);
        set(DaysFilterType.FOLDER, path);
        if (this.$route.query.recursive) {
          set(DaysFilterType.RECURSIVE);
        }
      }

      // Embedded tags (folder-style browsing)
      if (this.routeIsEmbeddedTags) {
        // Active flag gates ClustersBackend\Manager's dispatch to EmbeddedTagsBackend;
        // the path is sent separately so an empty (root) path still counts as active
        // and correctly yields zero timeline photos (only child tags live at the root).
        set(DaysFilterType.EMBEDDED_TAG_ACTIVE);

        let path: string[] | string = this.$route.params.path || [];
        if (typeof path === 'string') {
          path = path.split('/');
        }
        set(DaysFilterType.EMBEDDED_TAG_PATH, path.filter(Boolean).join('/'));

        if (this.$route.query.recursive) {
          set(DaysFilterType.RECURSIVE);
        }

        // Unlike a folder, a tag isn't tied to any one location -- a photo
        // anywhere in the account can carry it, and the tag dictionary itself
        // is built from every indexed photo regardless of the configured
        // timeline path. So this must search the whole account too (same as
        // "All Photos"), or photos outside the timeline path would make an
        // otherwise non-empty tag look empty here.
        set(DaysFilterType.ALL_PHOTOS);
      }

      // All Photos: dedicated signal, not the folder+recursive mechanism --
      // deliberately distinct from Folders-view browsing so configured path
      // exclusions only ever apply here, never to an ordinary folder browse.
      if (this.routeIsAllPhotos) {
        set(DaysFilterType.ALL_PHOTOS);
      }

      // Archive
      if (this.routeIsArchive) {
        set(DaysFilterType.ARCHIVE);
      }

      // Albums
      const { user, name } = this.$route.params;
      if (this.routeIsAlbums) {
        if (!user || !name) {
          throw new Error('Invalid album route');
        }
        set(DaysFilterType.ALBUM, `${user}/${name}`);
      }

      // People
      if (this.routeIsPeople) {
        if (!user || !name) {
          throw new Error('Invalid face route');
        }

        // name is "recognize" or "facerecognition"
        const filter = <DaysFilterType>this.$route.name;
        set(filter, `${user}/${name}`);

        // Face rect
        if (this.config.show_face_rect || this.routeIsRecognizeUnassigned) {
          set(DaysFilterType.FACE_RECT);
        }
      }

      // Places
      if (this.routeIsPlaces) {
        if (name?.includes('-')) {
          const id = name.split('-', 1)[0];
          set(DaysFilterType.PLACE, id);
        } else if (name === this.c.PLACES_NULL) {
          set(DaysFilterType.PLACE, this.c.PLACES_NULL);
        } else {
          throw new Error('Invalid place route');
        }
      }

      // Tags
      if (this.routeIsTags) {
        if (!name) {
          throw new Error('Invalid tag route');
        }
        set(DaysFilterType.TAG, name);
      }

      // Map Bounds
      if (this.routeIsMap) {
        const bounds = <string>this.$route.query.b;
        if (!bounds) {
          throw new Error('Missing map bounds');
        }

        set(DaysFilterType.MAP_BOUNDS, bounds);
      }

      // Group by / Sort by
      if ('day' !== this.backendGroupMode) {
        set(DaysFilterType.GROUP_MODE, this.backendGroupMode);
      }
      if (this.isGroupReverse) {
        set(DaysFilterType.GROUP_REVERSE);
      }
      if ('created' !== this.effectiveSortSetting.key) {
        set(DaysFilterType.SORT_KEY, this.effectiveSortSetting.key);
      }
      if (this.isSortReverse) {
        set(DaysFilterType.SORT_REVERSE);
      }

      return query;
    },

    /**
     * Refresh the dynamic top matter (subfolder/subtag grid).
     *
     * Deliberately NOT awaited by fetchDays before it requests photos: on a
     * folder page this runs a preview query per subfolder, and on an embedded
     * tag page a whole-account scan -- neither of which the photo list depends
     * on. Serializing them meant no photo appeared until the grid resolved.
     */
    async refreshTopMatter() {
      try {
        this.updateLoading(1);
        const state = this.state;
        const res = await this.refs.dtm?.refresh();
        if (this.state !== state) return;
        this.dtmContent = res ?? false;

        // The dynamic top matter (e.g. subfolder/subtag grid) just changed the
        // height of the recycler's #before slot. vue-virtual-scroller doesn't
        // know that happened -- it only re-measures #before on its own
        // scroll/resize/items-changed triggers -- so without this, photo rows
        // get positioned using the stale (too small) previous height and
        // visually overlap/clip the bottom of the top matter until something
        // else (a scroll, a resize, navigating away and back) happens to
        // trigger a recalculation.
        await this.$nextTick();
        this.refs.recycler?.updateVisibleItems(false);
      } catch (e) {
        // Never reject: the photo list is fetched concurrently and must not be
        // taken down with the grid. Previously a throw here aborted fetchDays
        // entirely, so a failing folder/tag listing meant no photos at all.
        if (!utils.isNetworkError(e)) console.error('Failed to refresh top matter', e);
        this.dtmContent = false;
      } finally {
        this.updateLoading(-1);
      }
    },

    /** Fetch timeline main call */
    async fetchDays(noCache = false) {
      // Kick off the top matter concurrently -- see refreshTopMatter(). Its
      // own state guard makes it safe to leave running; we only await it at
      // the end so callers still get a promise that resolves once the whole
      // view has settled.
      const topMatter = this.refreshTopMatter();

      // Get URL an cache identifier
      let url: string;
      try {
        url = API.Q(API.DAYS(), this.getQuery());
      } catch (err) {
        // Likely invalid route; just quit doing anything
        return await topMatter;
      }

      // URL for cached data
      const cacheUrl = <string>this.$route.name + url;

      // Try cache first
      let cache: IDay[] | null = null;

      try {
        this.updateLoading(1);
        const startState = this.state;

        let data: IDay[] = [];
        if (this.routeIsThisDay) {
          data = await dav.getOnThisDayData();
        } else if (dav.isSingleItem()) {
          data = await dav.getSingleItemData();
          setTimeout(() => _m.viewer.open(data[0]!.detail![0]), 0);
        } else {
          // Try the cache
          if (!noCache) {
            try {
              if ((cache = await utils.getCachedData(cacheUrl))) {
                if (this.routeHasNative) {
                  cache = nativex.mergeDays(cache, await nativex.getLocalDays());
                }

                await this.processDays(cache, true);
                this.updateLoading(-1);
              }
            } catch {
              console.warn(`Failed to process days cache: ${cacheUrl}`);
              cache = null;
            }
          }

          // Get from network
          const res = await axios.get<IDay[]>(url);
          if (res.status !== 200) throw res; // don't cache this
          data = res.data;
        }

        // Put back into cache
        utils.cacheData(cacheUrl, data);

        // Extend with native days
        if (this.routeHasNative) {
          data = nativex.mergeDays(data, await nativex.getLocalDays());
        }

        // Make sure we're still on the same page
        if (this.state !== startState) return;
        await this.processDays(data, false);
      } catch (e) {
        if (!utils.isNetworkError(e)) {
          showError(e?.response?.data?.message ?? e.message);
          console.error(e);
        }
      } finally {
        // If cache is set here, loading was already decremented
        if (!cache) this.updateLoading(-1);

        // Settle the concurrently-started top matter refresh, so callers
        // awaiting fetchDays still get a fully settled view. Safe to await
        // here because refreshTopMatter never rejects.
        await topMatter;
      }
    },

    /**
     * Process the data for days call including folders
     * @param data Days data
     * @param cache Whether the data was from cache
     */
    async processDays(data: IDay[], cache: boolean) {
      if (!data || !this.state) return;

      const list: typeof this.list = [];
      const heads: typeof this.heads = new Map();

      // Store the preloads in a separate map.
      // This is required since otherwise the inner detail objects
      // do not become reactive (which happens only after assignment).
      const preloads = new Map<number, IPhoto[]>();

      // Fold the raw days response into the buckets that get a header and the
      // segments that get fetched and laid out (see services/segments.ts):
      // consecutive small days are merged (Group by: None), and any bucket
      // holding more than CHUNK_THRESHOLD photos is split so it streams in as
      // the user scrolls rather than arriving all at once.
      //
      // Merging is skipped for ThisDay (whose "days" are deliberately
      // non-consecutive, one per year) and native builds (whose local-photo
      // merging keys directly off real dayids -- see fetchSegExpire).
      // Preloaded .detail is dropped for merged buckets -- fetchSegment() will
      // fetch them fresh, which is simpler than gluing partial preloads from
      // several real days together and only affects the very first screen's
      // paint speed, not correctness.
      const built = buildSegments(data, {
        isGrouped: this.isGrouped,
        pagable: this.canPageDays(),
        mergeable: !this.isGrouped && !this.routeIsThisDay && !this.routeHasNative,
      });
      const buckets = built.buckets;

      this.realToBucket = built.realToBucket;
      const segments = new Map<string, ISegment>();
      const daySegments = new Map<number, ISegment[]>();
      for (const seg of built.segments) {
        segments.set(seg.id, seg);
        if (!daySegments.has(seg.dayId)) daySegments.set(seg.dayId, []);
        daySegments.get(seg.dayId)!.push(seg);
      }

      let prevDay: IDay | null = null;
      for (const day of buckets) {
        // Initialization
        day.rows = [];

        // Nothing here
        if (day.count === 0) {
          continue;
        }

        // Store the preloads
        if (day.detail) {
          preloads.set(day.dayid, day.detail);
          delete day.detail;
        }

        // Create header for this day. It carries the id of the day's first
        // segment so that "is this row's data loaded/sized" works uniformly
        // for headers and photo rows alike (see loadScrollView).
        const head: IHeadRow = {
          id: `${day.dayid}-head`,
          num: -1,
          size: this.isGrouped ? Math.round(40 * this.photoZoomScale) : 0,
          type: 0, // head
          selected: false,
          dayId: day.dayid,
          segId: daySegments.get(day.dayid)?.[0]?.id ?? `${day.dayid}:0`,
          day: day,
        };

        if (this.isGrouped) {
          // Set the bucket granularity to change the header title
          head.groupMode = this.backendGroupMode;
        } else {
          // Group by: None -- header exists for bucket bookkeeping (dayId/day) only
          head.collapsed = true;
        }

        // Special headers
        if (this.routeIsThisDay && (!prevDay || Math.abs(prevDay.dayid - day.dayid) > 30)) {
          // thisday view with new year title -- always shown, regardless of Group by
          head.size = Math.round(67 * this.photoZoomScale);
          head.super = utils.getFromNowStr(utils.dayIdToDate(day.dayid), { padding: 10 });
          head.collapsed = false;
        }

        // Add header to list
        heads.set(day.dayid, head);
        list.push(head);

        // Dummy rows for placeholders, per segment. Each segment owns a
        // contiguous slice of day.rows, so the row a given scroll position
        // falls on already knows which segment must be fetched -- no offset
        // arithmetic happens at scroll time.
        for (const seg of daySegments.get(day.dayid) ?? []) {
          let nrows = Math.ceil(seg.count / this.numCols);

          // Check if already loaded - we can learn. Keyed by segment id, which
          // is stable across refreshes for as long as the day's size is.
          const prevRows = this.segments.get(seg.id)?.rows;
          nrows = prevRows?.length || nrows;

          // Add rows
          for (let i = 0; i < nrows; i++) {
            const row = this.addRow(day, seg, day.rows.length, i);
            list.push(row);

            // Add placeholder count
            const leftNum = seg.count - i * this.numCols;
            row.pct = Math.max(0, Math.min(this.numCols, leftNum));
            row.photos = [];

            // Learn from existing row
            if (prevRows && i < prevRows.length && !prevRows[i].pct) {
              row.size = prevRows[i].size;
              row.photos = prevRows[i].photos;
              delete row.pct;
            }
          }
        }

        // Continue processing
        prevDay = day;
      }

      // Store globally
      this.list = list;
      this.heads = heads;
      this.segments = segments;
      this.daySegments = daySegments;
      this.loadedSegs.clear();
      this.sizedSegs.clear();

      // Mark if the data was from cache
      this.daysIsCache = cache;

      // Iterate the preload map
      // Now the inner detail objects are reactive
      for (let [dayId, photos] of preloads) {
        photos = this.preprocessDay(dayId, photos);
        this.processDay(dayId, photos);
      }

      // Notify parent components about stats
      this.$emit('daysLoaded', {
        count: data.reduce((acc, day) => acc + day.count, 0),
      });

      // Fix view height variable
      await this.refs.scrollerManager.reflow();
      this.scrollPositionChange();

      // Trigger a view refresh. This will load any new placeholders too.
      this.scrollChange(this.currentStart, this.currentEnd, true);
    },

    /**
     * Whether day contents may be requested in windows for this view.
     *
     * The server refuses to page whenever it applies an authoritative PHP
     * filter pass over the SQL result (embedded tags, filter expressions),
     * and we refuse for native builds, where local-photo merging assumes it
     * is given a complete day. In both cases days are still SPLIT INTO
     * SEGMENTS -- only the fetch stays whole, so the giant single layout pass
     * is avoided even when the download can't be.
     */
    canPageDays(): boolean {
      return !this.routeHasNative && !this.routeIsThisDay && !dav.isSingleItem();
    },

    /** API url for Day call */
    getDayUrl(dayIds: number[], chunk?: { offset: number; limit: number }) {
      const query = this.getQuery();

      // If any day in the fetch list has local images we need to fetch
      // the remote hidden images for the merging to happen correctly
      if (this.routeHasNative) {
        if (dayIds.some((id) => this.heads.get(id)?.day?.haslocal)) {
          query[DaysFilterType.HIDDEN] = '1';
        }
      }

      // Expand any bucket ids to the real dayIds merged into them (see
      // services/segments.ts) -- the backend only knows real dayids. A no-op
      // for grouped modes/ThisDay/native, where every id is already real.
      const realDayIds = dayIds.flatMap((id) => this.daySegments.get(id)?.[0]?.realDayIds ?? [id]);

      // Window within the day, for chunked segments only. Omitted entirely
      // for unchunked ones, so their URL (and cache key) stays byte-identical
      // to what it was before chunking existed.
      if (chunk) {
        query[DaysFilterType.CHUNK_OFFSET] = String(chunk.offset);
        query[DaysFilterType.CHUNK_LIMIT] = String(chunk.limit);
      }

      return API.Q(API.DAY(realDayIds.join(',')), query);
    },

    /** API url for a segment */
    getSegmentUrl(seg: ISegment) {
      return this.getDayUrl([seg.dayId], seg.chunked ? { offset: seg.offset, limit: seg.limit! } : undefined);
    },

    /**
     * CacheStorage key for a Day call. This is NOT the request URL: it is
     * prefixed with the route name, since two routes may produce an identical
     * getQuery() and must not share cached day contents.
     */
    getDayCacheKey(dayIds: number[], chunk?: { offset: number; limit: number }) {
      return <string>this.$route.name + this.getDayUrl(dayIds, chunk);
    },

    /** CacheStorage key for a segment */
    getSegmentCacheKey(seg: ISegment) {
      return <string>this.$route.name + this.getSegmentUrl(seg);
    },

    /**
     * Fetch image data for an entire day.
     *
     * Kept for callers that genuinely need a whole day resident -- the viewer's
     * neighbouring-day prefetch and deep links, which index into day.detail.
     */
    async fetchDay(dayId: number, now = false) {
      const segs = this.daySegments.get(dayId);
      if (!segs?.length) return;

      // Segments that already have their photos need nothing, even when the
      // caller asked for an immediate fetch -- otherwise opening the viewer
      // inside a large day would re-download every chunk already on screen.
      const pending = segs.filter((seg) => !seg.photos);
      await Promise.all(pending.map((seg) => this.fetchSegment(seg.id, now)));
    },

    /** Fetch image data for one segment */
    async fetchSegment(segId: string, now = false) {
      if (!now && this.loadedSegs.has(segId)) return;

      // Get segment and head to ensure it still exists / is valid
      const seg = this.segments.get(segId);
      if (!seg) return;
      const head = this.heads.get(seg.dayId);
      if (!head) return;

      // Do this in advance to prevent duplicate requests
      this.loadedSegs.add(segId);
      this.sizedSegs.add(segId);

      // Look for cache. The route name is part of the key because two routes
      // can produce an identical getQuery() (e.g. ThisDay vs the base
      // timeline), and a day cached under one must not be served to the other.
      // Same keying as the days list cache in fetchDays().
      const cacheUrl = this.getSegmentCacheKey(seg);
      try {
        let cache = await utils.getCachedData<IPhoto[]>(cacheUrl);
        if (cache) {
          // Cache only contains remote images; update from local too
          if (this.routeHasNative && head.day?.haslocal) {
            nativex.mergeDay(cache, await nativex.getLocalDay(seg.dayId));
          }

          // Process the cache
          cache = this.preprocessDay(seg.dayId, cache);

          // If this is a cached response and the list is not, then we don't
          // want to take any destructive actions like removing a day.
          //  1. If a day is removed then it will not be fetched again
          //  2. But it probably does exist on the server
          //  3. Since days could be fetched, the user probably is connected
          if (!this.daysIsCache && !cache.length) {
            throw new Error('Skipping empty cache because view is fresh');
          }

          this.processSegment(seg, cache);
        }
      } catch (e) {
        console.warn(`Failed or skipped processing day cache: ${cacheUrl}`, e);
      }

      // Aggregate fetch requests
      this.fetchSegQueue.push(segId);

      // If the queue has gotten large enough, just expire immediately
      // This is to prevent a large number of requests from being queued
      now ||= this.fetchSegQueue.length >= 16;
      now ||= this.fetchSegQueue.reduce((sum, id) => sum + (this.segments.get(id)?.count ?? 0), 0) > 256;

      // Process immediately
      if (now) return await this.fetchDayExpire();

      // Defer for aggregation
      this.fetchDayTimer ??= window.setTimeout(() => {
        this.fetchDayTimer = null;
        this.fetchDayExpire();
      }, 150);
    },

    async fetchDayExpire() {
      if (this.fetchSegQueue.length === 0) return;

      const queued = this.fetchSegQueue
        .map((id) => this.segments.get(id))
        .filter((seg): seg is ISegment => !!seg);
      this.fetchSegQueue = [];

      // Chunked segments are never merged with anything else: a multi-day
      // response is binned back by each photo's own dayid, which cannot tell
      // two windows of the SAME day apart. Everything else still shares one
      // request, exactly as before chunking existed.
      const batched = queued.filter((seg) => !seg.chunked);
      const solo = queued.filter((seg) => seg.chunked);

      const jobs: Promise<void>[] = [];
      if (batched.length) jobs.push(this.fetchSegBatch(batched));
      for (const seg of solo) jobs.push(this.fetchSegBatch([seg]));

      await Promise.all(jobs);
    },

    /**
     * Run one day request covering the given segments, and route the response
     * back onto them.
     *
     * @param segs Either any number of unchunked segments, or exactly one
     *             chunked segment (see fetchDayExpire).
     */
    async fetchSegBatch(segs: ISegment[]) {
      const chunked = segs.length === 1 && segs[0].chunked ? segs[0] : null;
      const dayIds = segs.map((seg) => seg.dayId);

      // Construct URL
      const url = chunked
        ? this.getSegmentUrl(chunked)
        : this.getDayUrl(dayIds);

      // Let navigation away cancel this. Without it the response still lands,
      // and still writes to CacheStorage under a key derived from a query
      // that has since changed.
      const aborter = new AbortController();
      this.fetchAborters.add(aborter);

      try {
        const startState = this.state;
        const res = await axios.get<IPhoto[]>(url, { signal: aborter.signal });
        if (res.status !== 200) throw res;
        const data = res.data;

        // Check if the state has changed
        if (this.state !== startState) return;

        if (chunked) {
          // The server declines to page whenever it post-filters in PHP, in
          // which case it returns the whole day and says so by omitting the
          // chunk header. Spread that response across every segment of the
          // day instead of treating it as this one window's contents.
          //
          // Measured on the RAW payload: preprocessDay() drops hidden files
          // and stacks RAW pairs, either of which can shorten a response
          // without it being short for paging purposes.
          const applied = res.headers?.['x-memories-chunk'] === `${chunked.offset}-${chunked.limit}`;
          if (!applied) {
            await this.processServerDay(chunked.dayId, data, url);
            return;
          }

          // A short window is the last one; a full window means there may be
          // more. Either way this is what corrects the day-list estimate.
          await this.processServerSegment(chunked, data, data.length < chunked.limit!);

          return;
        }

        // Bin the data into separate days (or buckets -- each photo's own
        // .dayid is always real, so map it back via the reverse lookup,
        // a no-op when nothing was merged). Already sorted in dayid DESC.
        const dayMap = new Map<number, IPhoto[]>();
        for (const dayId of dayIds) dayMap.set(dayId, []);
        for (const photo of data) {
          const bucketId = this.realToBucket.get(photo.dayid) ?? photo.dayid;
          dayMap.get(bucketId)?.push(photo);
        }

        for (const [dayId, photos] of dayMap) {
          await this.processServerDay(dayId, photos, this.getDayCacheKey([dayId]));
        }
      } catch (e) {
        // Aborts are deliberate -- the view moved on. Leave the marks cleared
        // by the finally below and let the new view fetch what it needs.
        if (!axios.isCancel(e) && !utils.isNetworkError(e)) {
          showError(this.t('memories', 'Failed to load some photos'));
          console.error(e);
        }
      } finally {
        this.fetchAborters.delete(aborter);

        // The segments were optimistically marked as loaded/sized in
        // fetchSegment() to prevent duplicate requests while this one was in
        // flight. Anything that did NOT end up with photos failed, was
        // aborted, or was discarded as stale -- unmark those so they get
        // retried on the next scroll instead of being stuck as placeholders
        // forever. Done in a finally so no early return can skip it.
        for (const seg of segs) {
          if (seg.photos) continue;
          this.loadedSegs.delete(seg.id);
          this.sizedSegs.delete(seg.id);
        }
      }
    },

    /**
     * Handle a server response holding one segment's window of photos.
     *
     * @param seg     the segment fetched
     * @param raw     the raw photos from the server, before preprocessing
     * @param isFinal whether the server indicated this is the day's last window
     */
    async processServerSegment(seg: ISegment, raw: IPhoto[], isFinal: boolean) {
      utils.cacheData(this.getSegmentCacheKey(seg), raw);

      const photos = this.preprocessDay(seg.dayId, raw);
      if (this.segmentUnchanged(seg, photos)) return;

      this.processSegment(seg, photos, isFinal);
    },

    /**
     * Handle a server response holding an entire day, splitting it across the
     * day's segments.
     *
     * This is both the unchunked path and the fallback for when the server
     * declined to page a chunked request.
     */
    async processServerDay(dayId: number, raw: IPhoto[], cacheKey: string) {
      const segs = this.daySegments.get(dayId);
      if (!segs?.length) return;

      // Store cache asynchronously. Do this regardless of whether the state
      // has changed since the data is already fetched. This cannot be
      // combined with the processing below, because processSegment creates
      // circular references which cannot be stringified.
      //
      // The day is cached regardless of whether it is empty. Empty days might
      // be fetched e.g. on NativeX. In this case, empty caches will not be
      // processed if the view is fresh.
      if (segs.length === 1) utils.cacheData(cacheKey, raw);

      // Get local images if we are running in native environment.
      if (this.routeHasNative) {
        // Extra hooks for each day. Well this doesn't really belong here ...
        nativex.processFreshServerDay(dayId, raw);

        if (this.heads.get(dayId)?.day?.haslocal) {
          nativex.mergeDay(raw, await nativex.getLocalDay(dayId));
        }
      }

      // Slice the raw response by each segment's window. Slicing before
      // preprocessing (rather than after) keeps the boundaries aligned with
      // the offsets the server pages by, so a re-fetch of one segment lands on
      // exactly the same photos.
      for (const seg of segs) {
        const slice = segs.length === 1 ? raw : raw.slice(seg.offset, seg.offset + (seg.limit ?? raw.length));
        const photos = this.preprocessDay(dayId, slice);
        if (this.segmentUnchanged(seg, photos)) continue;

        this.processSegment(seg, photos, seg === segs[segs.length - 1]);
      }
    },

    /**
     * Whether a freshly fetched segment is identical to what is already
     * displayed, in which case the (expensive) relayout can be skipped and
     * only the reactive flags copied over.
     */
    segmentUnchanged(seg: ISegment, photos: IPhoto[]): boolean {
      const current = seg.photos;
      if (current?.length !== photos.length) return false;

      // Goes over the segment and checks each photo including the order with
      // the current list. If anything changes, we reprocess everything;
      // otherwise just copy over newer props that are reactive.
      return current.every((curr: IPhoto, i: number) => {
        const now = photos[i];
        if (curr.fileid !== now.fileid || curr.etag !== now.etag) return false;

        // copy over any properties that might have changed
        // this way we don't need to iterate again for this
        utils.convertFlags(now);
        utils.copyPhotoFlags(now, curr);

        return true;
      });
    },

    /**
     * Preprocess items from day response.
     * This should be called on all responses before doing any checks.
     *
     * 1. Removes hidden files from the response
     * 2. Performs stacking, e.g. for JPG+NEF pairs
     */
    preprocessDay(dayId: number, data: IPhoto[]): IPhoto[] {
      if (!data?.length) return [];

      // Set of basenames without extension
      const res1: IPhoto[] = [];
      const toStack = new Map<string, IPhoto[]>();
      const auids = new Set<string>();

      // First pass -- remove hidden and prepare
      for (const photo of data) {
        // Skip hidden files
        if (photo.ishidden) continue;
        if (photo.basename?.startsWith('.')) continue;

        // Skip identical duplicates
        if (this.config.dedup_identical && photo.auid) {
          if (auids.has(photo.auid)) continue;
          auids.add(photo.auid);
        }

        // Add to first pass result
        res1.push(photo);

        // Remove extension
        let basename = utils.removeExtension(photo.basename ?? String());
        if (!basename) continue; // huh?

        // Store RAW files for stacking
        if (this.config.stack_raw_files && photo.mimetype === this.c.MIME_RAW) {
          // Google's RAW naming is inconsistent and retarded.
          // We will handle this on a case-to-case basis, unless there's
          // a strong argument to always take the basename only upto the
          // first dot.
          // https://github.com/pulsejet/memories/issues/927
          // https://github.com/pulsejet/memories/issues/1006
          if (basename.includes('.ORIGINAL')) {
            // Consider basename only upto the first dot
            basename = basename.split('.', 1)[0];
          }

          // Store the RAW file for stacking with the usable basename
          const files = toStack.get(basename);
          if (!files) {
            toStack.set(basename, [photo]);
          } else {
            files.push(photo);
          }
        }
      }

      // Skip second pass unless needed
      if (!toStack.size) return res1;

      // File IDs that have been stacked
      const stacked = new Set<IPhoto>();

      // Second pass -- stack files
      for (const photo of res1) {
        if (photo.mimetype === this.c.MIME_RAW) {
          continue; // never stack over RAW
        }

        // Check if any RAW files can be stacked
        const basename = utils.removeExtension(photo.basename ?? String());
        const files = toStack.get(basename) ?? [];

        // If a second dot is present in the name, then split till the first dot
        // https://github.com/pulsejet/memories/issues/927
        // https://github.com/pulsejet/memories/issues/1006
        if (basename.includes('.')) {
          // Consider basename only upto the first dot
          const subname = basename.split('.', 1)[0];
          files.push(...(toStack.get(subname) ?? []));
        }

        if (!files.length) continue;

        // Stack on top of this file
        photo.stackraw = files;

        // Mark as stacked
        files.forEach((f) => stacked.add(f));
      }

      // Remove files that were stacked
      const res2 = res1.filter((p) => !stacked.has(p));

      return res2;
    },

    /**
     * Process items from a day response, for a whole day at once.
     *
     * Spreads the photos across the day's segments -- see processServerDay,
     * which does the same for network responses.
     */
    processDay(dayId: number, data: IPhoto[]) {
      const segs = this.daySegments.get(dayId);
      if (!segs?.length) return;

      for (const seg of segs) {
        const slice = segs.length === 1 ? data : data.slice(seg.offset, seg.offset + (seg.limit ?? data.length));
        this.processSegment(seg, slice, seg === segs[segs.length - 1]);
      }
    },

    /**
     * Lay out one segment's photos and splice its rows into the main list.
     *
     * Each segment is laid out independently, which is the whole point: a day
     * with thousands of photos costs one bounded layout pass per chunk as the
     * user scrolls, instead of one enormous pass the moment the day is
     * reached. The cost is a seam at every chunk boundary, which stretchLastRow
     * below turns into an ordinary full-width row.
     *
     * @param seg     the segment being processed
     * @param data    its photos, already preprocessed
     * @param isFinal whether this is known to be the day's last segment
     */
    processSegment(seg: ISegment, data: IPhoto[], isFinal?: boolean) {
      if (!data || !this.state) return;

      const head = this.heads.get(seg.dayId);
      if (!head) return;

      const day = head.day;
      this.loadedSegs.add(seg.id);
      this.sizedSegs.add(seg.id);

      // Convert server flags to bitflags
      data.forEach(utils.convertFlags);

      // Set and make reactive
      seg.photos = data;
      seg.count = data.length;
      day.rows ??= [];

      // A chunk coming back short means the day is smaller than the days list
      // estimated, so any segments planned past this one don't exist. Drop
      // them (and their placeholder rows) before laying this one out.
      if (isFinal && !seg.final) this.truncateDay(day, seg);

      // Roll the day's own totals up from its segments. day.count must NOT be
      // set from this one chunk: ScrollerManager sums it per tick for the
      // scrubber's photo counts, and the viewer derives its global indices
      // from it, so it has to keep meaning "photos in the whole day". While
      // partially loaded it stays at the days-list estimate.
      const segs = this.daySegments.get(seg.dayId) ?? [seg];
      const complete = segs.every((s) => s.photos);
      day.loadedCount = segs.reduce((acc, s) => acc + (s.photos?.length ?? 0), 0);
      if (complete) {
        day.count = day.loadedCount;
        // Only ever a complete day: the viewer and restoreDay both index into
        // detail assuming it holds everything.
        day.detail = segs.flatMap((s) => s.photos!);
      } else {
        delete day.detail;
      }

      // Reset this segment's rows including placeholders. Other segments'
      // rows are left completely alone.
      for (const row of seg.rows) {
        row.photos = [];
      }

      // Force all to square
      const squareMode = this.isMobileLayout() || this.effectiveShape !== 'dynamic';

      // Whether this segment ends mid-day. Such a seam has no header to
      // explain a "cut off" row, so fill it like an ungrouped bucket's last
      // row -- getLayout reflows the tail to find a near-full row and then
      // stretches away whatever gap remains.
      const midDay = segs.length > 1 && seg !== segs[segs.length - 1];

      // Create justified layout with correct params
      const justify = getLayout(
        data.map((p) => ({
          width: p.w || this.rowHeight,
          height: p.h || this.rowHeight,
          forceSquare: false,
        })),
        {
          rowWidth: this.rowWidth,
          rowHeight: this.rowHeight,
          squareMode: squareMode,
          uniformGrid: this.effectiveShape === 'grid',
          numCols: this.numCols,
          allowBreakout: this.allowBreakout(),
          listMode: this.effectiveShape === 'list',
          // Vary per segment: reusing the day's seed for every chunk would
          // replay the identical square-mode promotion pattern every
          // CHUNK_SIZE photos, which reads as visible repetition.
          seed: seg.dayId * 31 + seg.index,
          // Group by: None -- fill each bucket's last row instead of leaving
          // it short, since there's no header to explain a "cut off" row.
          // Grid opts out: it keeps every cell the same size, so a short final
          // row is expected there rather than something to stretch away.
          stretchLastRow: (midDay || !this.isGrouped) && this.effectiveShape !== 'grid',
        },
      );

      // Check if some rows were added
      let addedRows: IRow[] = [];

      // Recycler scroll top
      let scrollTop = this.refs.recycler!.$el.scrollTop;
      let needAdjust = false;

      // Get index and Y position of the row this segment starts after, in
      // O(n): the head for the day's first segment, else the last row of the
      // preceding segment. Segments always own a contiguous run of rows.
      const anchor = this.segmentAnchor(head, segs, seg);
      let anchorIdx = 0;
      let anchorY = 0;
      for (const row of this.list) {
        if (row === anchor) break;
        anchorIdx++;
        anchorY += row.size;
      }
      let rowIdx = anchorIdx + 1;
      let rowY = anchorY + anchor.size;

      // Number rows continuously across the day, so day.rows[row.num] is the
      // row itself and the row-change animation below can measure between them.
      const baseNum = segs.slice(0, segs.indexOf(seg)).reduce((acc, s) => acc + s.rows.length, 0);

      // Duplicate detection, e.g. for face rects
      const seen = new Map<number, number>();

      // Previous justified row
      let prevJustifyTop = justify[0]?.top || 0;

      // Add all rows
      let dataIdx = 0;
      let segRowIdx = 0;
      while (dataIdx < data.length) {
        // Check if we ran out of rows. Rows belonging to a later segment are
        // off limits: this segment may only grow into its own run.
        if (rowIdx >= this.list.length || this.list[rowIdx].type === 0 || this.list[rowIdx].segId !== seg.id) {
          const newRow = this.addRow(day, seg, baseNum + segRowIdx, segRowIdx);
          addedRows.push(newRow);
          this.list.splice(rowIdx, 0, newRow);

          // Scroll down if new row is above the current visible position
          if (rowY < scrollTop) {
            scrollTop += newRow.size;
          }
          needAdjust = true;
        }

        // Get row
        const row = this.list[rowIdx];

        // Go to the next row
        const jbox = justify[dataIdx];
        if (jbox.top !== prevJustifyTop) {
          prevJustifyTop = jbox.top;
          rowIdx++;
          segRowIdx++;
          rowY += row.size;
          continue;
        }

        // Set row height. In list mode this is per-row/per-photo: only rows
        // whose photo actually has embedded tags to show (and the toggle is
        // on) get the extra room for that 3rd line -- everything else stays
        // at the compact 2-line (filename + meta) baseline.
        let jH = utils.roundHalf(jbox.rowHeight || jbox.height);
        if (this.effectiveShape === 'list') {
          const photo = data[dataIdx];
          const exif = photo.imageInfo?.exif || photo.exif;
          const hasTags =
            (this.effectiveImageProps.metadata_in_gallery && utils.getTagsFromExif(exif).length > 0) ||
            (this.effectiveImageProps.show_collab_tags_in_gallery && (photo.systemtags?.length ?? 0) > 0);
          jH = listRowHeight(this.config.grid_zoom, hasTags);
        }
        const delta = jH - row.size;
        // If the difference is too small, it's not worth risking an adjustment
        // especially on square layouts on mobile. Also don't do this if animating.
        if (Math.abs(delta) > 0) {
          if (rowY < scrollTop) {
            scrollTop += delta;
          }
          needAdjust = true;
          row.size = jH;
        }

        // Add the photo to the row
        const photo = data[dataIdx];
        photo.d = day; // backref to day

        // Get aspect ratio
        const setPos = () => {
          photo.dispW = utils.roundHalf(jbox.width);
          photo.dispX = utils.roundHalf(jbox.left);
          photo.dispH = utils.roundHalf(jbox.height);
          photo.dispY = 0;
          photo.dispRowNum = row.num;
        };
        if (photo.dispW !== undefined) {
          // photo already displayed: animate
          window.setTimeout(setPos, 50);

          if (
            photo.dispRowNum !== undefined &&
            photo.dispRowNum !== row.num &&
            photo.dispRowNum >= 0 &&
            photo.dispRowNum < day.rows.length
          ) {
            // Row change animation
            const start = Math.min(photo.dispRowNum, row.num);
            const end = Math.max(photo.dispRowNum, row.num);
            const sizeDelta = day.rows.slice(start, end).reduce((acc, r) => {
              acc += r.size;
              return acc;
            }, 0);
            photo.dispY = sizeDelta * (photo.dispRowNum < row.num ? -1 : 1);
            photo.dispH = day.rows[photo.dispRowNum].size;
          }
        } else {
          setPos();
        }

        // Move to next index of photo
        dataIdx++;

        // Duplicate detection.
        // These may be valid, e.g. in face rects. All we need to have
        // is a unique Vue key for the v-for loop.
        const key = photo.faceid || photo.fileid;
        const val = seen.get(key);
        if (val) {
          photo.key = `${key}-${val}`;
          seen.set(key, val + 1);
        } else {
          photo.key = `${key}`;
          seen.set(key, 1);
        }

        // Add photo to row
        row.photos!.push(photo);
        delete row.pct;
      }

      // Restore selection day
      this.refs.selectionManager.restoreDay(day);

      // Rows that were removed
      const removedRows: IRow[] = [];
      let headRemoved = false;

      // The whole day turned out to be empty -- splice everything including
      // the header. Only the last remaining segment may do this, since the
      // header belongs to the day rather than to any one segment.
      if (day.loadedCount === 0 && complete) {
        removedRows.push(...this.list.splice(anchorIdx, 1));
        rowIdx = anchorIdx - 1;
        headRemoved = true;
        this.heads.delete(seg.dayId);
        this.daySegments.delete(seg.dayId);
        for (const s of segs) this.segments.delete(s.id);
      }

      // Get rid of any extra rows this segment no longer needs. Bounded to
      // this segment's own run: a following segment's rows are not ours to
      // remove, and neither is the next day's header.
      let spliceCount = 0;
      for (let i = rowIdx + 1; i < this.list.length && this.list[i].segId === seg.id; i++) {
        spliceCount++;
      }
      if (spliceCount > 0) {
        removedRows.push(...this.list.splice(rowIdx + 1, spliceCount));
      }

      // Update size delta for removed rows and remove from day
      for (const row of removedRows) {
        needAdjust = true;

        // Scroll up if if above visible range
        if (rowY < scrollTop) {
          scrollTop -= row.size;
        }

        // Remove from day and from its segment
        const idx = day.rows.indexOf(row);
        if (idx >= 0) day.rows.splice(idx, 1);
        const sidx = seg.rows.indexOf(row);
        if (sidx >= 0) seg.rows.splice(sidx, 1);
      }

      // Keep day.rows in display order: it is the concatenation of every
      // segment's rows, and selectHead/the delete path both walk it expecting
      // that order. Renumber so day.rows[row.num] === row holds.
      if (!headRemoved && segs.length > 1) {
        day.rows = segs.flatMap((s) => s.rows);
        day.rows.forEach((row, i) => (row.num = i));
      }

      // This will be true even if the head is being spliced
      // because one row is always removed in that case
      if (needAdjust) {
        if (headRemoved) {
          // If the head was removed, we need a reflow,
          // or adjust isn't going to work right
          this.refs.scrollerManager.reflow();
        } else {
          // Otherwise just adjust the ticks. adjust() coalesces through a
          // nextTick, so several chunks landing together still cost one pass.
          this.refs.scrollerManager.adjust();
        }

        // Scroll to new position
        this.refs.recycler!.$el.scrollTop = scrollTop;
      }
    },

    /**
     * The row a segment's own run starts immediately after: the day's header
     * for its first segment, otherwise the last row of the preceding segment
     * that actually has any.
     */
    segmentAnchor(head: IHeadRow, segs: ISegment[], seg: ISegment): IRow {
      for (let i = segs.indexOf(seg) - 1; i >= 0; i--) {
        const rows = segs[i].rows;
        if (rows.length) return rows[rows.length - 1];
      }
      return head;
    },

    /**
     * Drop the segments planned after `last`, along with their placeholder
     * rows. Called when a chunk comes back short, which means the day holds
     * fewer photos than the days list estimated.
     */
    truncateDay(day: IDay, last: ISegment) {
      const segs = this.daySegments.get(last.dayId);
      if (!segs) return;

      const keep = segs.slice(0, segs.indexOf(last) + 1);
      const drop = segs.slice(segs.indexOf(last) + 1);
      if (!drop.length) return;

      const dropped = new Set(drop.flatMap((s) => s.rows));
      if (dropped.size) {
        this.list = this.list.filter((row) => !dropped.has(row));
        day.rows = (day.rows ?? []).filter((row) => !dropped.has(row));
      }

      for (const s of drop) {
        this.segments.delete(s.id);
        this.loadedSegs.delete(s.id);
        this.sizedSegs.delete(s.id);
      }

      last.final = true;
      this.daySegments.set(last.dayId, keep);
    },

    /** Add and get a new blank photos row, owned by the given segment */
    addRow(day: IDay, seg: ISegment, num: number, segIdx: number): IRow {
      // Make sure rows exists
      day.rows ??= [];

      // Create new row. The id must be unique across the whole list (it is the
      // recycler's key), hence the segment offset -- two segments of the same
      // day both start counting their rows at zero.
      const row: IRow = {
        id: `${day.dayid}-${seg.offset}-${segIdx}`,
        num,
        photos: [],
        type: 1, // photos
        size: this.rowHeight,
        dayId: day.dayid,
        segId: seg.id,
        day: day,
      };

      // Add to day and to the segment that owns it
      day.rows.push(row);
      seg.rows.splice(segIdx, 0, row);

      return row;
    },

    /**
     * Delete elements from main view with some animation
     *
     * This is also going to update day.detail for you and make
     * a call to processDay so just pass it the list of ids to
     * delete and the days that were updated.
     *
     * @param delPhotos photos to delete
     */
    async deleteFromViewWithAnimation(delPhotos: IPhoto[]) {
      // Only keep photos with day
      delPhotos = delPhotos.filter((p) => p?.d);
      if (delPhotos.length === 0) return;

      // Get all days that need to be updatd
      const updatedDays = new Set<IDay>(delPhotos.map((p) => p.d!));
      const delPhotosSet = new Set(delPhotos);

      // Animate the deletion
      for (const photo of delPhotos) {
        photo.flag |= this.c.FLAG_LEAVING;
      }

      // wait for 200ms
      await new Promise((resolve) => setTimeout(resolve, 200));

      // clear selection at this point
      this.refs.selectionManager.deselect(delPhotos);

      // Reflow all touched days
      for (const day of updatedDays) {
        const segs = this.daySegments.get(day.dayid);
        if (!segs?.length) continue;

        if (day.detail) {
          // The whole day is resident, so re-derive every segment from the
          // surviving photos: a deletion shifts every offset after it, which
          // would otherwise leave the later segments describing the wrong
          // photos (and refetching under the wrong chunk key).
          const newDetail = day.detail.filter((p) => !delPhotosSet.has(p));
          this.resegmentDay(day, newDetail.length);
          this.processDay(day.dayid, newDetail);
          continue;
        }

        // Partially loaded: only the segments that actually lost a photo need
        // reflowing, and the day's offsets stay as the server sees them until
        // the next full refresh.
        for (const seg of segs) {
          if (!seg.photos?.some((p) => delPhotosSet.has(p))) continue;
          this.processSegment(seg, seg.photos.filter((p) => !delPhotosSet.has(p)));
        }
      }
    },

    /** Rebuild a day's segment list for a new photo count, preserving loaded ones */
    resegmentDay(day: IDay, count: number) {
      const segs = this.daySegments.get(day.dayid);
      if (!segs?.length) return;

      const next = resegment(segs, count);
      for (const seg of segs) {
        if (next.includes(seg)) continue;

        // Dropped -- remove its rows and forget it
        const dropped = new Set(seg.rows);
        this.list = this.list.filter((row) => !dropped.has(row));
        day.rows = (day.rows ?? []).filter((row) => !dropped.has(row));
        this.segments.delete(seg.id);
        this.loadedSegs.delete(seg.id);
        this.sizedSegs.delete(seg.id);
      }

      for (const seg of next) this.segments.set(seg.id, seg);
      this.daySegments.set(day.dayid, next);
    },

    onFiltersChanged(filters: IFilters) {
      this.filters = filters;
      this.refresh();
    },

    resetFilters() {
      this.filters = emptyFilters();
      this.refresh();
    },
  },
});
</script>

<style lang="scss" scoped>
/** Main view */
.container {
  height: 100%;
  width: 100%;
  overflow: hidden;
  position: relative;

  @media (max-width: 768px) {
    // Get rid of padding on img-outer (1px on mobile)
    // Also need to make sure we don't end up with a scrollbar -- see below
    margin-left: -1px;
    width: calc(100% + 3px); // 1px extra here for sub-pixel rounding
  }
}

.recycler {
  will-change: scroll-position;
  contain: strict;
  height: 300px;
  width: 100%;
  transition: opacity 0.2s ease-in-out;

  :deep .vue-recycle-scroller__slot {
    contain: content;
  }

  :deep .vue-recycle-scroller__item-wrapper {
    contain: strict;
  }

  :deep .vue-recycle-scroller__item-view {
    contain: layout style;
  }

  &.empty {
    opacity: 0;
    transition: none;
    height: 0 !important;
  }

  &:focus {
    outline: none;
  }
}

.recycler .photo {
  contain: strict;
  display: block;
  cursor: pointer;
  height: 100%;
  transition:
    width 0.2s ease-in-out,
    height 0.2s ease-in-out,
    transform 0.2s ease-in-out; // reflow
}

/** Dynamic top matter */
.recycler-before {
  width: 100%;
}
</style>
