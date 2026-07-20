<template>
  <div class="p-outer-super">
    <div
      class="p-outer fill-block"
      :class="{
        selected: data.flag & c.FLAG_SELECTED,
        placeholder: data.flag & c.FLAG_PLACEHOLDER,
        leaving: data.flag & c.FLAG_LEAVING,
        error: data.flag & c.FLAG_LOAD_FAIL,
        'list-view': isListView,
      }"
      :style="{ '--zoom-scale': zoomScale }"
    >
      <!-- Compact list row layout -->
      <template v-if="isListView">
        <div
          class="list-select"
          v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
          @pointerdown.passive="$emit('select', $event)"
        >
          <CheckCircleIcon :size="18" />
        </div>

        <div
          class="list-main"
          @contextmenu="contextmenu"
          @pointerdown.passive="$emit('pointerdown', $event)"
          @touchstart.passive="$emit('touchstart', $event)"
          @touchmove="$emit('touchmove', $event)"
          @touchend.passive="$emit('touchend', $event)"
          @touchcancel.passive="$emit('touchend', $event)"
        >
          <div class="list-thumb">
            <XImg
              v-if="src"
              ref="ximg"
              draggable="false"
              class="ximg fill-block no-user-select"
              :class="[`memories-thumb-${data.key}`]"
              :src="src"
              :key="data.fileid"
              @load="load"
              @error="error"
            />
            <video
              ref="video"
              v-if="videoUrl"
              :src="videoUrl"
              preload="none"
              muted
              playsinline
              disableRemotePlayback
            ></video>
          </div>

          <div class="list-info">
            <div class="list-filename-row" v-if="showFilename || isRaw || showExtension">
              <span class="list-filename" v-if="showFilename">{{ listFilenameBase }}</span>
              <RawIcon v-if="isRaw" class="list-raw-badge" :size="14" />
              <span class="list-ext" v-else-if="showExtension">{{ listExtension }}</span>
            </div>
            <span class="list-meta" v-if="listMetaStr">{{ listMetaStr }}</span>
            <RatingTags v-if="showTags" class="list-rating-tags" :tags="currentTags" :compact="true" :max-tags="3" />
          </div>

          <div class="list-flags">
            <span class="video" v-if="data.flag & c.FLAG_IS_VIDEO">
              <span class="time" v-if="data.video_duration">{{ videoDuration }}</span>
              <VideoIcon :size="18" />
            </span>
            <span
              class="livephoto"
              v-if="data.liveid"
              @mouseenter.passive="playVideo"
              @mouseleave.passive="stopVideo"
              @touchstart.passive="touchVideo"
            >
              <LivePhotoIcon :size="18" :spin="liveState.waiting" :playing="liveState.playing" />
            </span>
            <StarIcon :size="18" v-if="data.flag & c.FLAG_IS_FAVORITE" />
            <LocalIcon :size="18" v-if="data.flag & c.FLAG_IS_LOCAL" />
            <span class="shared-by" v-if="showOwnerName && sharedBy">{{ sharedBy }}</span>
          </div>
        </div>
      </template>

      <!-- Regular grid/square tile layout -->
      <template v-else>
        <div
          class="select"
          v-once
          v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
          @pointerdown.passive="$emit('select', $event)"
        >
          <CheckCircleIcon :size="18" />
        </div>

        <div class="flag top-left">
          <RawIcon class="raw" v-if="isRaw" :size="28" />
          <span class="extension" v-else-if="showExtension">{{ listExtension }}</span>
        </div>

        <div class="flag top-right">
          <span class="rating-badge" v-if="showRating">
            <template v-for="n in 5">
              <StarIcon v-if="n <= currentRating" :key="`rating-filled-${n}`" :size="ratingBadgeSize" />
              <StarOutlineIcon v-else :key="`rating-outline-${n}`" :size="ratingBadgeSize" />
            </template>
          </span>
          <div class="video" v-if="data.flag & c.FLAG_IS_VIDEO">
            <span class="time" v-if="data.video_duration">{{ videoDuration }}</span>
            <VideoIcon :size="22" />
          </div>
          <div
            class="livephoto"
            v-if="data.liveid"
            @mouseenter.passive="playVideo"
            @mouseleave.passive="stopVideo"
            @touchstart.passive="touchVideo"
          >
            <LivePhotoIcon :size="22" :spin="liveState.waiting" :playing="liveState.playing" />
          </div>
        </div>

        <div class="flag bottom-right">
          <StarIcon :size="22" v-if="data.flag & c.FLAG_IS_FAVORITE" />
          <LocalIcon :size="22" v-if="data.flag & c.FLAG_IS_LOCAL" />
        </div>

        <div class="flag bottom-left">
          <span class="shared-by" v-if="showOwnerName && sharedBy">{{ sharedBy }}</span>
        </div>

        <div
          class="img-outer fill-block"
          :class="{ 'memories-livephoto': data.liveid }"
          @contextmenu="contextmenu"
          @pointerdown.passive="$emit('pointerdown', $event)"
          @touchstart.passive="$emit('touchstart', $event)"
          @touchmove="$emit('touchmove', $event)"
          @touchend.passive="$emit('touchend', $event)"
          @touchcancel.passive="$emit('touchend', $event)"
        >
          <XImg
            v-if="src"
            ref="ximg"
            draggable="false"
            class="ximg fill-block no-user-select"
            :class="[`memories-thumb-${data.key}`]"
            :src="src"
            :key="data.fileid"
            @load="load"
            @error="error"
          />
          <video
            ref="video"
            v-if="videoUrl"
            :src="videoUrl"
            preload="none"
            muted
            playsinline
            disableRemotePlayback
          ></video>
          <div class="overlay top-left fill-block"></div>
        </div>

        <!-- Metadata overlay -->
        <div class="metadata-overlay" v-if="showMetadataOverlay">
          <div class="filename-row" v-if="showFilename">
            <span class="filename">{{ listFilenameBase }}</span>
          </div>
          <div class="meta-row" v-if="gridMetaStr">{{ gridMetaStr }}</div>
          <RatingTags v-if="showTags" :tags="currentTags" :compact="true" :max-tags="3" />
        </div>
      </template>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import * as utils from '@services/utils';

import RatingTags from '@components/RatingTags.vue';
import LivePhotoIcon from '@components/icons/LivePhoto.vue';
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircleOutline.vue';
import LocalIcon from 'vue-material-design-icons/CloudOff.vue';
import RawIcon from 'vue-material-design-icons/Raw.vue';

import type { IDay, IPhoto } from '@typings';
import type XImg from '@components/XImg.vue';
import type { ImageDisplayShape } from '@services/view-mode-scope';
import type { ImagePropsBundle } from '@services/image-props';

import errorsvg from '@assets/error.svg';

export default defineComponent({
  name: 'Photo',

  components: {
    RatingTags,
    LivePhotoIcon,
    CheckCircleIcon,
    VideoIcon,
    StarIcon,
    StarOutlineIcon,
    LocalIcon,
    RawIcon,
  },

  props: {
    data: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
    day: {
      type: Object as PropType<IDay>,
      required: true,
    },
    imageDisplayShape: {
      type: String as PropType<ImageDisplayShape>,
      required: true,
    },
    imageProps: {
      type: Object as PropType<ImagePropsBundle>,
      required: true,
    },
    /** Scale factor for the current zoom level (1 = ZOOM_DEFAULT), so overlay text/rating grow with the tile in square/dynamic mode. */
    zoomScale: {
      type: Number,
      default: 1,
    },
  },

  emits: {
    select: (e: PointerEvent) => true,
    pointerdown: (e: PointerEvent) => true,
    touchstart: (e: TouchEvent) => true,
    touchmove: (e: TouchEvent) => true,
    touchend: (e: TouchEvent) => true,
  },

  data: () => ({
    touchTimer: 0,
    liveState: {
      playTimer: 0,
      playing: false,
      waiting: false,
      requested: false,
    },
    faceSrc: null as string | null,
  }),

  watch: {
    data(newData: IPhoto, oldData: IPhoto) {
      // Copy flags relevant to this component
      if (oldData && newData) {
        newData.flag |= oldData.flag & (this.c.FLAG_SELECTED | this.c.FLAG_LOAD_FAIL);
      }
    },
  },

  mounted() {
    this.faceSrc = null;

    // Setup video hooks
    const video = this.refs.video;
    if (video) {
      utils.setupLivePhotoHooks(video, this.liveState);
    }
  },

  /** Clear timers */
  beforeDestroy() {
    clearTimeout(this.touchTimer);
    clearTimeout(this.liveState.playTimer);

    // Clean up blob url if face rect was created
    if (this.faceSrc) {
      URL.revokeObjectURL(this.faceSrc);
    }
  },

  computed: {
    refs() {
      return this.$refs as {
        ximg?: InstanceType<typeof XImg> & { $el: HTMLImageElement };
        video?: HTMLVideoElement;
      };
    },

    videoDuration(): string | null {
      if (this.data.video_duration) {
        return utils.getDurationStr(this.data.video_duration);
      }
      return null;
    },

    videoUrl(): string | null {
      if (this.data.liveid) {
        return utils.getLivePhotoVideoUrl(this.data, true);
      }
      return null;
    },

    src(): string | null {
      this.data.etag; // dependency

      if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
        return null;
      } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
        return errorsvg;
      } else if (this.faceSrc) {
        return this.faceSrc;
      } else {
        return this.url();
      }
    },

    isRaw(): boolean {
      return !!this.data.stackraw || this.data.mimetype === this.c.MIME_RAW;
    },

    showOwnerName(): boolean {
      if (this.routeIsBase && !this.imageProps.show_owner_name_timeline) {
        return false;
      }
      return true;
    },

    sharedBy(): string | null {
      if (this.data.shared_by == '[unknown]') {
        return this.t('memories', 'Shared');
      } else if (this.data.shared_by) {
        return this.data.shared_by;
      }
      return null;
    },

    /** Get current photo rating */
    currentRating(): number {
      const exif = this.data.imageInfo?.exif || this.data.exif;
      return utils.getRatingFromExif(exif);
    },

    /** Get current photo embedded tags */
    currentTags(): string[][] {
      const exif = this.data.imageInfo?.exif || this.data.exif;
      return utils.getTagsFromExif(exif);
    },

    /** Whether to show the rating stars -- a compact corner badge in grid mode, folded into the meta text in list mode */
    showRating(): boolean {
      return this.imageProps.enable_exif_photo_rating_in_gallery && this.currentRating > 0;
    },

    /** Whether to show embedded tags (the only thing RatingTags renders now -- rating lives elsewhere) */
    showTags(): boolean {
      return this.imageProps.metadata_in_gallery && this.currentTags.length > 0;
    },

    /** Compact star-glyph text for the rating, e.g. "★★★★☆" -- used inline in the list row's meta text */
    ratingStarsText(): string {
      return '★'.repeat(this.currentRating) + '☆'.repeat(5 - this.currentRating);
    },

    /** Icon size (px) for the top-right rating badge, scaled with the current zoom level like the rest of the overlay */
    ratingBadgeSize(): number {
      return Math.round(12 * this.zoomScale);
    },

    /** Whether to show the filename */
    showFilename(): boolean {
      return this.imageProps.show_filename_in_gallery && !!this.data.basename;
    },

    /** Whether to show the file extension badge */
    showExtension(): boolean {
      return this.imageProps.show_extension_in_gallery && !!this.listExtension;
    },

    /** Whether to show the metadata overlay container */
    showMetadataOverlay(): boolean {
      return this.showTags || this.showFilename || !!this.gridMetaStr;
    },

    /** Whether to render the compact list-row layout instead of a tile */
    isListView(): boolean {
      return this.imageDisplayShape === 'list';
    },

    /** Filename without the extension, for the list view filename line */
    listFilenameBase(): string {
      return utils.removeExtension(this.data.basename ?? '');
    },

    /** Uppercased file extension (no dot), for the list view filename line */
    listExtension(): string {
      const base = this.data.basename ?? '';
      const ext = base.slice(this.listFilenameBase.length + 1);
      return ext.toUpperCase();
    },

    /** Full date and time the photo was taken, for the list view meta line */
    listDateTimeStr(): string | null {
      if (!this.data.datetaken) return null;
      return utils.getLongDateStr(new Date(this.data.datetaken * 1000), false, true);
    },

    /** Secondary metadata line shown under the filename in list view */
    listMetaStr(): string {
      const parts: string[] = [];
      if (this.showRating) {
        parts.push(this.ratingStarsText);
      }
      if (this.imageProps.show_datetime_in_gallery && this.listDateTimeStr) {
        parts.push(this.listDateTimeStr);
      }
      if (this.imageProps.show_dimensions_in_gallery && this.data.w && this.data.h) {
        parts.push(`${this.data.w} × ${this.data.h}`);
      }
      if (this.videoDuration) {
        parts.push(this.videoDuration);
      }
      return parts.join(' · ');
    },

    /** Secondary metadata line shown under the filename in the grid/dynamic/square overlay */
    gridMetaStr(): string {
      const parts: string[] = [];
      if (this.imageProps.show_datetime_in_gallery && this.listDateTimeStr) {
        parts.push(this.listDateTimeStr);
      }
      if (this.imageProps.show_dimensions_in_gallery && this.data.w && this.data.h) {
        parts.push(`${this.data.w} × ${this.data.h}`);
      }
      return parts.join(' · ');
    },
  },

  methods: {
    /** Get url of the photo */
    url() {
      let base: 256 | 512 = 256;

      // Check if displayed size is larger than the image
      if (this.data.dispH! > base * 0.9 && this.data.dispW! > base * 0.9) {
        // Get a bigger image
        // 1. No trickery here, just get one size bigger. This is to
        //    ensure that the images can be cached even after reflow.
        // 2. Nextcloud only allows 4**x sized images, so technically
        //    this ends up being equivalent to 1024x1024.
        base = 512;
      }

      return utils.getPreviewUrl({
        photo: this.data,
        msize: base,
      });
    },

    /** Set src with overlay face rect */
    async addFaceRect() {
      if (!this.data.facerect || this.faceSrc) return;

      const img = this.refs.ximg?.$el;
      if (!img) return;

      // This is a hack to check if img is actually loaded.
      //   XImg loads an empty image, which may sometimes show up here
      //   If the size is less than 5px it is probably this dummy image
      //   Either way, the user cannot see anything if the image is this small
      //   so there's no point in trying to draw the face rect
      if (!img || img.naturalWidth < 5) return;

      const canvas = document.createElement('canvas');
      const context = canvas.getContext('2d');
      if (!context) return; // failed to create canvas

      canvas.width = img.naturalWidth;
      canvas.height = img.naturalHeight;
      context.drawImage(img, 0, 0);
      context.strokeStyle = '#00ff00';
      context.lineWidth = 2;
      context.strokeRect(
        this.data.facerect.x * img.naturalWidth,
        this.data.facerect.y * img.naturalHeight,
        this.data.facerect.w * img.naturalWidth,
        this.data.facerect.h * img.naturalHeight,
      );

      canvas.toBlob(
        (blob) => {
          if (!blob) return;
          this.faceSrc = URL.createObjectURL(blob);
        },
        'image/jpeg',
        0.95,
      );
    },

    /** Post load tasks */
    load() {
      this.addFaceRect();
    },

    /** Error in loading image */
    error(e: Error) {
      this.data.flag |= this.c.FLAG_LOAD_FAIL;
    },

    contextmenu(e: Event) {
      e.preventDefault();
      e.stopPropagation();
    },

    /** Start preview video */
    playVideo() {
      if (this.data.flag & this.c.FLAG_SELECTED) return;
      this.liveState.waiting = true;

      // Quickly moving over the icon causes unnecessary
      // transcoding requests which are expensive
      utils.setRenewingTimeout(
        this.liveState,
        'playTimer',
        async () => {
          const video = this.refs.video;
          if (!video || this.data.flag & this.c.FLAG_SELECTED) return;

          try {
            this.liveState.requested = true;
            video.currentTime = 0;
            video.loop = true;
            await video.play();
          } catch (e) {
            // ignore, pause was probably called too soon
          } finally {
            this.liveState.waiting = false;
          }
        },
        this.liveState.requested ? 0 : 300, // delay only the first play
      );
    },

    /** Stop preview video */
    stopVideo() {
      this.refs.video?.pause();
      window.clearTimeout(this.liveState.playTimer);
      this.liveState.playTimer = 0;
      this.liveState.waiting = false;
    },

    /** Start/stop preview video for touchscreens */
    touchVideo() {
      if (this.liveState.playing) this.stopVideo();
      else this.playVideo();
    },
  },
});
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
  & {
    padding: 2px;
    --icon-dist: 8px;

    transition:
      background-color 0.15s ease,
      opacity 0.2s ease-in,
      transform 0.2s ease-in;
  }

  @media (max-width: 768px) {
    padding: 1px;
    --icon-dist: 4px;
  }

  &.leaving {
    transform: scale(0.9);
    opacity: 0;
  }

  &.selected {
    background-color: var(--color-primary-select-light);
    background-clip: content-box;
  }
}

// Distance of icon from border
$icon-half-size: 6px;
$icon-size: $icon-half-size * 2;

// Selection icon
// Not the same as any other flag because it does not
// translate when selected, and changes color
.select {
  position: absolute;
  top: calc(var(--icon-dist) + 2px);
  right: calc(var(--icon-dist) + 2px);
  z-index: 100;
  border-radius: 50%;
  display: none;
  opacity: 0.7;

  @mixin visible {
    display: flex;
    opacity: 1;
  }

  @media (hover: hover) and (pointer: fine) {
    .p-outer:hover > & {
      @include visible;
    }
  }

  & {
    filter: invert(1) brightness(100);
  }

  .p-outer.selected > & {
    @include visible;
    filter: invert(0);
    background-color: white;
    color: var(--color-primary);
  }

  .check-circle-icon {
    cursor: pointer;

    // Extremely ugly way to fill up the space
    // If this isn't done, bg has a border
    ::v-deep path {
      transform: scale(1.2) translate(-2px, -2px);
    }
  }
}

// Flags to show on timeline
.flag {
  position: absolute;
  z-index: 100;
  pointer-events: none;
  transition: transform 0.15s ease;
  color: white;
  display: flex;

  &.top-left {
    top: var(--icon-dist);
    left: var(--icon-dist);
    gap: 6px;
    .p-outer.selected > & {
      transform: translate($icon-size, $icon-size);
    }
  }

  &.top-right {
    top: var(--icon-dist);
    right: var(--icon-dist);
    gap: 6px;
    .p-outer.selected > & {
      transform: translate(-$icon-size, $icon-size);
    }
  }

  &.bottom-left {
    bottom: var(--icon-dist);
    left: var(--icon-dist);
    .p-outer.selected > & {
      transform: translate($icon-size, -$icon-size);
    }
  }

  &.bottom-right {
    bottom: var(--icon-dist);
    right: var(--icon-dist);
    .p-outer.selected > & {
      transform: translate(-$icon-size, -$icon-size);
    }
  }

  > .shared-by {
    font-size: 0.75em;
    line-height: 0.75em;
    font-weight: 400;
    margin: 2px;
  }

  > .video {
    display: flex;
    line-height: 22px; // force text height to match

    > .time {
      font-size: 0.75em;
      font-weight: bold;
      margin-right: 3px;
    }
  }

  > .livephoto {
    pointer-events: auto; // hover to play
  }

  > .raw {
    height: 22px; // force height to match
  }

  > .extension {
    font-size: 0.75em;
    font-weight: bold;
    line-height: 22px; // force text height to match
    letter-spacing: 0.02em;
    filter: drop-shadow(1px 0 0 rgba(0, 0, 0, 0.9)) drop-shadow(-1px 0 0 rgba(0, 0, 0, 0.9))
      drop-shadow(0 1px 0 rgba(0, 0, 0, 0.9)) drop-shadow(0 -1px 0 rgba(0, 0, 0, 0.9));
  }

  // Compact readonly star row -- white with a bold outline so it stays
  // legible directly over the photo (this corner has no gradient backdrop)
  > .rating-badge {
    display: flex;
    align-items: center;

    .material-design-icon {
      filter: drop-shadow(1px 0 0 rgba(0, 0, 0, 0.9)) drop-shadow(-1px 0 0 rgba(0, 0, 0, 0.9))
        drop-shadow(0 1px 0 rgba(0, 0, 0, 0.9)) drop-shadow(0 -1px 0 rgba(0, 0, 0, 0.9));
    }

    .material-design-icon.star-outline-icon {
      opacity: 0.75;
    }
  }
}

// Actual image
div.img-outer {
  position: relative;
  box-sizing: border-box;
  padding: 0;
  cursor: pointer;

  transition: padding 0.15s ease;
  .p-outer.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  .p-outer.placeholder > & {
    background-color: var(--color-background-dark);
    background-clip: content-box, padding-box;
  }

  > .ximg {
    background-clip: content-box;
    object-fit: cover;
    z-index: 1;
    background-color: var(--color-background-dark);

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    pointer-events: none;
    transition:
      border-radius 0.1s ease-in,
      transform 0.3s ease-in-out;

    .p-outer.placeholder > & {
      display: none;
    }
    .p-outer.error & {
      object-fit: contain;
    }
  }

  > video {
    pointer-events: none;
    object-fit: cover;
    z-index: 2;
  }

  > .overlay {
    pointer-events: none;
    z-index: 3;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.2) 0%, transparent 30%);

    display: none;
    transition: border-radius 0.1s ease-in;
    @media (hover: hover) and (pointer: fine) {
      .p-outer:not(.selected):hover > & {
        display: block;
      }
    }
  }

  > * {
    @media (max-width: 768px) {
      .selected > & {
        border-radius: $icon-size;
        border-top-left-radius: 0;
      }
    }
  }
}

// Metadata overlay
.metadata-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 50;
  pointer-events: none;
  background: linear-gradient(180deg, transparent 0%, rgba(0, 0, 0, 0.7) 100%);

  // Stack rows (filename, date/dimensions, rating+tags) with one consistent
  // gap instead of per-row margins, so spacing stays even regardless of
  // which rows are actually present
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 8px 8px 6px;

  // Scale with the current zoom level -- --zoom-scale tracks the same ratio
  // the tile itself grows/shrinks by, so the overlay's proportion of the
  // tile (not just its absolute size) stays consistent across zoom levels.
  // All the em-based sizes below cascade off this.
  font-size: calc(1em * var(--zoom-scale, 1));

  // Hide on hover to prevent interference with selection
  @media (hover: hover) and (pointer: fine) {
    .p-outer:hover > .img-outer > & {
      opacity: 0.3;
      transition: opacity 0.2s ease;
    }
  }

  // Hide when selected
  .p-outer.selected > .img-outer > & {
    opacity: 0;
  }

  // Filename line
  .filename-row {
    min-width: 0;

    .filename {
      display: block;
      font-size: 0.75em;
      font-weight: 500;
      color: white;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      min-width: 0;
    }
  }

  // Date/dimensions line
  .meta-row {
    font-size: 0.7em;
    color: rgba(255, 255, 255, 0.8);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  // Compact styling for gallery (tags only -- rating shows separately, top-right)
  ::v-deep .rating-tags {
    font-size: 0.75em;
    gap: 6px;
    width: 100%;

    // Let the tag list take the remaining width so chips know how much
    // space they actually have to shrink into
    .tags-section {
      flex: 1 1 auto;
      min-width: 0;
    }

    .tags-container {
      flex: 1 1 auto;
      gap: 2px;
      flex-wrap: nowrap;
      overflow: hidden;
      min-width: 0;

      // NcChip's real root class is .nc-chip (not .chip), and its actual
      // rendered size comes from the --chip-size custom property (drives
      // height, icon size, and border-radius) -- not padding, which is why
      // scaling padding alone never changed its footprint. Overriding the
      // variable here scales the whole chip together with the surrounding
      // text. No extra deep marker needed -- already inside one from the
      // "::v-deep .rating-tags" wrapper above. (Also: this project's
      // vue-loader/@vue/component-compiler-utils version only recognizes
      // the double-colon "::v-deep" form -- ":deep(...)" and ":deep foo"
      // compile to nothing and silently drop the whole rule.)
      .nc-chip {
        --chip-size: calc(24px * var(--zoom-scale, 1));
        font-size: 0.75em;
        flex-shrink: 1;
        min-width: 20px;

        .nc-chip__text {
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }

    // Pin the remaining-tags count to the right edge of the row, i.e.
    // the bottom-right corner of the thumbnail, regardless of how much
    // room the (shrinking) tag chips end up using. Sized off the same
    // --chip-size formula as .nc-chip above so the "+N" pill matches the
    // tag chips' height/shape instead of reading as plain, hard-to-see text.
    .more-tags {
      --chip-size: calc(24px * var(--zoom-scale, 1));
      font-size: 0.7em;
      flex-shrink: 0;
      margin-left: auto;
    }
  }

  @media (max-width: 768px) {
    padding: 6px 6px 4px;
    gap: 2px;

    ::v-deep .rating-tags {
      font-size: 0.7em;
      gap: 4px;
    }
  }
}

// List view row layout
.p-outer.list-view {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 8px;
  border-radius: var(--border-radius, 8px);

  @media (hover: hover) and (pointer: fine) {
    &:not(.selected):hover {
      background-color: var(--color-background-hover);
    }
  }

  &.selected {
    background-color: var(--color-primary-select-light);
  }

  &.placeholder .list-thumb {
    background-color: var(--color-background-dark);
  }
}

.list-select {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  opacity: 0.7;

  .p-outer.selected & {
    opacity: 1;
    color: var(--color-primary);
  }
}

.list-main {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 12px;
  height: 100%;
  cursor: pointer;
}

.list-thumb {
  position: relative;
  flex: 0 0 auto;
  width: auto;
  height: calc(100% - 12px);
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border-radius: 4px;
  background-color: var(--color-background-dark);
  margin: 6px 0;

  .ximg {
    width: 100%;
    height: 100%;
    object-fit: cover;
    background-color: var(--color-background-dark);
    pointer-events: none;

    .p-outer.error & {
      object-fit: contain;
    }
  }

  video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    pointer-events: none;
    z-index: 2;
  }
}

.list-info {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: calc(2px * var(--zoom-scale, 1));
  overflow: hidden;

  // Scale with the current zoom level, same as the grid overlay -- all the
  // em-based sizes below (and the row height itself, via LIST_ROW_HEIGHTS)
  // shrink/grow together so content keeps fitting at every zoom level.
  font-size: calc(1em * var(--zoom-scale, 1));

  .list-filename-row {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
  }

  .list-filename {
    flex: 0 1 auto;
    min-width: 0;
    font-size: 0.95em;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .list-ext {
    flex: 0 0 auto;
    font-size: 0.75em;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--color-text-maxcontrast);
  }

  .list-raw-badge {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
  }

  .list-meta {
    font-size: 0.8em;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  // Compact styling for the tags row in list view (rating is folded into .list-meta text above)
  ::v-deep .list-rating-tags {
    flex-wrap: nowrap;
    gap: calc(6px * var(--zoom-scale, 1));
    font-size: 0.8em;
    width: 100%;

    .tags-section {
      flex: 1 1 auto;
      min-width: 0;
    }

    .tags-container {
      flex-wrap: nowrap;
      overflow: hidden;
      min-width: 0;

      // See the grid overlay's equivalent rule for why --chip-size (not
      // padding) is what actually needs to scale here. No extra deep
      // marker needed -- already inside one from "::v-deep .list-rating-tags" above.
      .nc-chip {
        --chip-size: calc(24px * var(--zoom-scale, 1));
        font-size: 0.75em;
        flex-shrink: 1;
        min-width: 20px;

        .nc-chip__text {
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }

    .more-tags {
      --chip-size: calc(24px * var(--zoom-scale, 1));
      font-size: 0.7em;
      flex-shrink: 0;
      margin-left: auto;
    }
  }
}

.list-flags {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--color-text-maxcontrast);

  > .video {
    display: flex;
    align-items: center;

    > .time {
      font-size: 0.8em;
      margin-right: 3px;
    }
  }

  > .shared-by {
    font-size: 0.8em;
  }

  @media (max-width: 600px) {
    > .shared-by {
      display: none;
    }
  }
}
</style>
