<template>
  <div class="outer" v-if="fileid">
    <div v-if="isShared" class="shared-by">
      <div class="section-title">{{ t('memories', 'Shared By') }}</div>
      <div class="top-field">
        <NcAvatar key="uid" :user="baseInfo.owneruid" :showUserStatus="false" :size="24" />
        <span class="name">{{ baseInfo.ownername }}</span>
      </div>
    </div>

    <div v-if="people.length" class="people">
      <div class="section-title">{{ t('memories', 'People') }}</div>
      <div class="container" v-for="face of people" :key="face.cluster_id">
        <Cluster class="cluster--rounded" :data="face" :counters="false"> </Cluster>
      </div>
    </div>

    <div v-if="albums.length">
      <div class="section-title">{{ t('memories', 'Albums') }}</div>
      <AlbumsList class="albums" :albums="albums" />
    </div>

    <div class="section-title">{{ t('memories', 'Metadata') }}</div>

    <div v-if="canEdit || title" class="top-field top-field--title">
      <div class="icon">
        <FormatTitleIcon :size="24" />
      </div>
      <div class="text">
        <span class="title">{{ t('memories', 'Title') }}</span>
        <NcTextField
          class="edit-field"
          :label="t('memories', 'Title')"
          :label-outside="true"
          :disabled="!canEdit || lock"
          :value.sync="editBuffer.Title"
          @blur="saveField('Title')"
          @keypress.enter="onEnterBlur"
        />
      </div>
    </div>

    <div v-if="canEdit || description" class="top-field top-field--description">
      <div class="icon">
        <TextBoxIcon :size="24" />
      </div>
      <div class="text">
        <span class="title">{{ t('memories', 'Description') }}</span>
        <NcTextField
          class="edit-field"
          :label="t('memories', 'Description')"
          :label-outside="true"
          :disabled="!canEdit || lock"
          :value.sync="editBuffer.Description"
          @blur="saveField('Description')"
          @keypress.enter="onEnterBlur"
        />
      </div>
    </div>

    <div v-if="canEdit || exif.Label" class="top-field top-field--label">
      <div class="icon">
        <LabelIcon :size="24" />
      </div>
      <div class="text">
        <span class="title">{{ t('memories', 'Label') }}</span>
        <NcTextField
          class="edit-field"
          :label="t('memories', 'Label')"
          :label-outside="true"
          :disabled="!canEdit || lock"
          :value.sync="editBuffer.Label"
          @blur="saveField('Label')"
          @keypress.enter="onEnterBlur"
        />
      </div>
    </div>

    <div v-for="field of topFields" :key="field.title" :class="`top-field top-field--${field.id}`">
      <div class="icon">
        <component :is="field.icon" :size="24" />
      </div>

      <div class="text">
        <template v-if="field.href">
          <a :href="field.href" target="_blank" rel="noopener noreferrer">
            <span class="title">{{ field.title }}</span>
          </a>
        </template>
        <template v-else>
          <span class="title">{{ field.title }}</span>
        </template>

        <template v-if="field.subtitle.length">
          <br />
          <span class="subtitle">
            <span class="part" v-for="part in field.subtitle" :key="part">
              {{ part }}
            </span>
          </span>
        </template>
      </div>

      <div class="edit" v-if="canEdit && field.edit">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Edit')" @click="field.edit?.()">
            {{ t('memories', 'Edit') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div v-if="canEdit" class="top-field top-field--collab-tags">
      <div class="icon">
        <TagIcon :size="24" />
      </div>

      <div class="text">
        <div class="title">{{ t('memories', 'Collaborative Tags') }} ({{ tagNames.length }})</div>
        <div class="tags-container" v-if="tagNames.length > 0">
          <NcChip v-for="(name, idx) in tagNames" :key="`collab-tag-${idx}`" :text="name" no-close />
        </div>
        <div v-else class="subtitle">
          {{ t('memories', 'Click edit to add tags') }}
        </div>
      </div>

      <div class="edit" v-if="canEdit">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Edit')" @click="editTags()">
            {{ t('memories', 'Edit') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div
      v-if="config.embeddedtags_enabled && (embeddedTags.length > 0 || canEdit)"
      class="top-field top-field--embedded-tags"
    >
      <div class="icon">
        <TagIcon :size="24" />
      </div>

      <div class="text">
        <div class="title">{{ t('memories', 'Tags') }} ({{ embeddedTags.length }})</div>
        <div class="tags-container" v-if="embeddedTags.length > 0">
          <template v-for="(tag, idx) in embeddedTags">
            <NcChip v-if="tag.length === 1" :key="`tag-${idx}`" :text="tag[0]" no-close />
            <div v-else-if="tag.length > 1" :key="`taglist-${idx}`" style="display: inline-block; margin: 2px">
              <NcPopover no-focus-trap>
                <template #trigger>
                  <NcChip :text="tag[tag.length - 1]" no-close>
                    <template #icon>
                      <TreeIcon :size="16" />
                    </template>
                  </NcChip>
                </template>
                <template #default>
                  <div class="tag-path">{{ tag.join(' → ') }}</div>
                </template>
              </NcPopover>
            </div>
          </template>
        </div>
        <div v-else class="subtitle">
          {{ t('memories', 'Click edit to add tags') }}
        </div>
      </div>

      <div class="edit" v-if="canEdit">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Edit')" @click="editEmbeddedTags()">
            {{ t('memories', 'Edit') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div class="top-field top-field--rating">
      <div class="icon">
        <StarIcon :size="24" />
      </div>

      <div class="text">
        <div class="title">{{ t('memories', 'Rating') }}</div>
        <RatingStars
          class="rating-stars-inline"
          :rating="rating ?? 0"
          :readonly="!canEdit || lock"
          @update:rating="updateRating"
        />
      </div>

      <div class="edit" v-if="canEdit && rating">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Clear rating')" @click="clearRating()">
            {{ t('memories', 'Clear rating') }}
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div v-if="lat && lon" class="map">
      <iframe class="fill-block" :src="mapUrl"></iframe>
    </div>

    <NcButton v-if="canEdit" class="edit-all-button" type="tertiary" @click="editAll()">
      {{ t('memories', 'Edit all metadata') }}
      <template #icon> <EditIcon :size="20" /> </template>
    </NcButton>
  </div>
  <div class="loading-icon fill-block" v-else-if="loading">
    <XLoadingIcon />
  </div>
  <div v-else-if="error">
    {{ t('memories', 'Failed to load metadata') }}
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Component } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js';
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

const NcAvatar = () => import('@nextcloud/vue/dist/Components/NcAvatar.js');
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import axios from '@nextcloud/axios';
import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';

import UserConfig from '@mixins/UserConfig';
import Cluster from '@components/frame/Cluster.vue';
import AlbumsList from '@components/modal/AlbumsList.vue';
import RatingStars from '@components/common/RatingStars.vue';

import EditIcon from 'vue-material-design-icons/Pencil.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import CameraIrisIcon from 'vue-material-design-icons/CameraIris.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';
import TreeIcon from 'vue-material-design-icons/FileTree.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import CopyrightIcon from 'vue-material-design-icons/Copyright.vue';
import FormatTitleIcon from 'vue-material-design-icons/FormatTitle.vue';
import TextBoxIcon from 'vue-material-design-icons/TextBox.vue';
import LabelIcon from 'vue-material-design-icons/Label.vue';
import LensIcon from 'vue-material-design-icons/ImageFilterCenterFocus.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import { API } from '@services/API';

import type { IAlbum, IFace, IImageInfo, IPhoto, IExif } from '@typings';
import { showError } from '@nextcloud/dialogs';

interface TopField {
  id?: string;
  title: string;
  subtitle: string[];
  icon: Component;
  href?: string;
  edit?: () => void;
}

/** EXIF text fields editable inline in the sidebar, saved on blur */
const TEXT_FIELDS: (keyof IExif)[] = ['Title', 'Description', 'Label'];

export default defineComponent({
  name: 'Metadata',
  components: {
    NcActions,
    NcActionButton,
    NcAvatar,
    NcChip,
    NcPopover,
    NcButton,
    NcTextField,
    AlbumsList,
    Cluster,
    EditIcon,
    CloseIcon,
    TagIcon,
    TreeIcon,
    StarIcon,
    CopyrightIcon,
    FormatTitleIcon,
    TextBoxIcon,
    LabelIcon,
    LensIcon,
    RatingStars,
  },

  mixins: [UserConfig],

  data: () => ({
    fileid: null as number | null,
    filename: '',
    exif: {} as IExif,
    baseInfo: {} as IImageInfo,
    /** Reference to the IPhoto object this panel was opened for, if any -- kept so
     * edits can be written back to it and reactively update any grid/viewer tile
     * sharing the same object (see update()). */
    photo: null as IPhoto | null,
    lock: false,
    error: false,

    /** Local, immediately-editable mirror of the text EXIF fields, saved on blur */
    editBuffer: {} as Partial<Record<keyof IExif, string>>,

    loading: 0,
    state: 0,
  }),

  mounted() {
    utils.bus.on('files:file:updated', this.handleFileUpdated);
    utils.bus.on('memories:albums:update', this.refresh);
  },

  beforeDestroy() {
    utils.bus.off('files:file:updated', this.handleFileUpdated);
    utils.bus.off('memories:albums:update', this.refresh);
  },

  computed: {
    topFields(): TopField[] {
      let list: TopField[] = [];

      if (this.dateOriginal) {
        list.push({
          title: this.dateOriginalStr!,
          subtitle: this.dateOriginalTime!,
          icon: CalendarIcon,
          edit: this.editDate,
        });
      }

      if (this.imageInfoTitle) {
        list.push({
          id: 'image-info', // adds class
          title: this.imageInfoTitle,
          subtitle: this.imageInfoSub,
          icon: ImageIcon,
          href: this.filepath
            ? dav.viewInFolderUrl({
                fileid: this.fileid!,
                filename: this.filepath,
              })
            : undefined,
        });
      }

      if (this.address || this.canEdit) {
        list.push({
          title: this.address || this.t('memories', 'No coordinates'),
          subtitle: this.address ? [] : [this.t('memories', 'Click edit to set location')],
          icon: LocationIcon,
          href: this.address ? this.mapFullUrl : undefined,
          edit: this.editGeo,
        });
      }

      if (this.camera) {
        list.push({
          title: this.camera,
          subtitle: this.cameraSub,
          icon: CameraIrisIcon,
          edit: this.editEXIF,
        });
      }

      if (this.lens) {
        list.push({
          title: this.lens,
          subtitle: [],
          icon: LensIcon,
          edit: this.editEXIF,
        });
      }

      if (this.exif.Copyright) {
        list.push({
          title: this.exif.Copyright,
          subtitle: [],
          icon: CopyrightIcon,
          edit: this.editEXIF,
        });
      }

      return list;
    },

    /** Whether the user has permission to edit -- kept independent of `lock` so
     * fields with no value don't flicker out of the list while a save is in flight. */
    canEdit(): boolean {
      return this.baseInfo?.permissions?.includes('U') ?? false;
    },

    /** Title EXIF value */
    title(): string | null {
      return this.exif.Title || null;
    },

    /** Description EXIF value */
    description(): string | null {
      return this.exif.Description || null;
    },

    /** Date taken info */
    dateOriginal(): DateTime | null {
      // Try to get timezone info
      let dateWithTz: DateTime | null = null;
      const valid = () => dateWithTz?.isValid;

      // If we have an actual epoch, we can shift the date to the correct timezone
      if (!valid() && this.exif.DateTimeEpoch) {
        const date = DateTime.fromSeconds(this.exif.DateTimeEpoch);
        if (date.isValid) {
          const tzOffset = this.exif.OffsetTimeOriginal || this.exif.OffsetTime; // e.g. -05:00
          const tzId = this.exif.LocationTZID; // e.g. America/New_York

          // Use timezone offset if available
          if (!valid() && tzOffset) {
            dateWithTz = date.setZone('UTC' + tzOffset);
          }

          // Fall back to tzId
          if (!valid() && tzId) {
            dateWithTz = date.setZone(tzId);
          }
        }
      }

      // If tz info is unavailable / wrong, we will show the local time only
      // In this case, use the datetaken instead, which is guaranteed to be local, shifted to UTC
      if (!valid() && this.baseInfo.datetaken) {
        const date = DateTime.fromSeconds(this.baseInfo.datetaken);
        if (date.isValid) {
          dateWithTz = date.setZone('UTC');
        }
      }

      // Return only if we found a valid date
      return valid() ? dateWithTz : null;
    },

    dateOriginalStr(): string | null {
      return utils.getLongDateStr(new Date(this.baseInfo.datetaken * 1000), true);
    },

    dateOriginalTime(): string[] | null {
      if (!this.dateOriginal) return null;

      const fields: (keyof IExif)[] = ['OffsetTimeOriginal', 'OffsetTime', 'LocationTZID'];
      const hasTz = fields.some((key) => this.exif[key]);

      const format = 't' + (hasTz ? ' ZZ' : '');

      return [this.dateOriginal.toFormat(format, { locale: getCanonicalLocale() })];
    },

    /** Camera make and model info */
    camera(): string | null {
      const make = this.exif.Make;
      const model = this.exif.Model;
      if (!make || !model) return null;
      if (model.startsWith(make)) return model;
      return `${make} ${model}`;
    },

    cameraSub(): string[] {
      const f = this.exif.FNumber || this.exif.Aperture;
      const s = this.shutterSpeed;
      const len = this.exif.FocalLength;
      const iso = this.exif.ISO;

      const parts: string[] = [];
      if (f) parts.push(`f/${f}`);
      if (s) parts.push(`${s}`);
      if (len) parts.push(`${len}mm`);
      if (iso) parts.push(`ISO${iso}`);
      return parts;
    },

    /** Convert shutter speed decimal to 1/x format */
    shutterSpeed(): string | null {
      const speed = Number(this.exif.ShutterSpeedValue || this.exif.ShutterSpeed || this.exif.ExposureTime);
      if (!speed) return null;

      if (speed < 1) {
        return `1/${Math.round(1 / speed)}`;
      } else {
        return `${Math.round(speed * 10) / 10}s`;
      }
    },

    /** Lens model info */
    lens(): string | null {
      return this.exif.LensModel || null;
    },

    /** Image info */
    imageInfoTitle(): string | null {
      if (this.config.sidebar_filepath && this.filepath) {
        return this.filepath.replace(/^\//, ''); // remove leading slash
      }

      return this.baseInfo.basename;
    },

    /** Path to file excluding user directory */
    filepath(): string | null {
      return this.baseInfo?.filename ?? null;
    },

    imageInfoSub(): string[] {
      let parts: string[] = [];
      let mp = Number(this.exif.Megapixels);

      if (this.baseInfo.w && this.baseInfo.h) {
        parts.push(`${this.baseInfo.w}x${this.baseInfo.h}`);

        if (!mp) {
          mp = (this.baseInfo.w * this.baseInfo.h) / 1000000;
        }
      }

      if (mp) {
        parts.unshift(`${mp.toFixed(1)}MP`);
      }

      return parts;
    },

    address(): string | undefined {
      if (this.baseInfo.address) {
        return this.baseInfo.address;
      }

      if (this.lat && this.lon) {
        return `${this.lat.toFixed(6)}, ${this.lon.toFixed(6)}`;
      }

      return undefined;
    },

    lat(): number {
      return Number(this.exif.GPSLatitude);
    },

    lon(): number {
      return Number(this.exif.GPSLongitude);
    },

    rating(): number | null {
      const rating = utils.getRatingFromExif(this.exif);
      return rating > 0 ? rating : null;
    },

    embeddedTags(): string[][] {
      return utils.getTagsFromExif(this.exif);
    },

    tagNames(): string[] {
      return Object.values(this.baseInfo?.tags || {}).map((tag: string) => this.t('recognize', tag));
    },

    mapUrl(): string {
      const boxSize = 0.0075;
      const bbox = [this.lon - boxSize, this.lat - boxSize, this.lon + boxSize, this.lat + boxSize];
      const m = `${this.lat},${this.lon}`;
      return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox.join()}&marker=${m}`;
    },

    mapFullUrl(): string {
      return `https://www.openstreetmap.org/?mlat=${this.lat}&mlon=${this.lon}#map=18/${this.lat}/${this.lon}`;
    },

    albums(): IAlbum[] {
      let albums = this.baseInfo?.clusters?.albums ?? [];

      // Filter out hidden albums
      if (!this.config.show_hidden_albums) {
        albums = albums.filter((a) => !a.name.startsWith('.'));
      }

      return albums;
    },

    people(): IFace[] {
      const clusters = this.baseInfo?.clusters;

      // force face-recognition on its own route, or if recognize is disabled
      if (this.routeIsFaceRecognition || !this.config.recognize_enabled) {
        return clusters?.facerecognition ?? [];
      }

      return clusters?.recognize ?? [];
    },

    isShared(): boolean {
      return !!this.baseInfo.owneruid && this.baseInfo.owneruid !== utils.uid;
    },
  },

  methods: {
    async update(photo: number | IPhoto, current = false): Promise<IImageInfo | null> {
      this.invalidateUnless(0);

      // Remember the IPhoto object (if we were given one) so later refreshes
      // (e.g. after an edit) can write back into it -- this is what keeps grid/
      // viewer tiles sharing this same object in sync without a full reload.
      if ('object' === typeof photo) {
        this.photo = photo;
      }

      // which clusters to get
      const clusters = this.routeIsPublic
        ? String()
        : [
            this.config.albums_enabled ? 'albums' : null,
            this.config.recognize_enabled ? 'recognize' : null,
            this.config.facerecognition_enabled ? 'facerecognition' : null,
          ]
            .filter((c) => c)
            .join(',');

      // get tags if enabled
      const tags = this.config.systemtags_enabled ? 1 : undefined;

      // get image info
      const url = API.Q(utils.getImageInfoUrl(photo), { tags, clusters, current: current ? 1 : undefined });
      const res = await this.guardState(axios.get<IImageInfo>(url));
      if (!res) return null;

      // set image info
      this.baseInfo = res.data;
      this.fileid = this.baseInfo.fileid;
      this.filename = this.baseInfo.basename;
      // Prefer the freshly re-read exif over the indexed one: the background
      // reindex triggered by an edit can still be in flight, so the indexed
      // row may briefly lag behind what was just written.
      this.exif = this.baseInfo.current ?? this.baseInfo.exif ?? {};
      this.syncEditBuffer();

      // Write back into the remembered IPhoto so any grid/viewer tile sharing
      // this object (e.g. Photo.vue's rating badge) reflects the fresh data
      // immediately, without waiting for its own list to be refetched. Photo.vue
      // reads imageInfo.exif directly, so fold the live `current` data in there
      // too rather than leaving the (possibly still-indexing) `exif` field.
      if (this.photo?.fileid === this.baseInfo.fileid) {
        this.photo.imageInfo = { ...this.baseInfo, exif: this.exif };
      }

      return this.baseInfo;
    },

    async refresh() {
      if (this.fileid) await this.update(this.fileid, true);
    },

    /**
     * Invalidate metadata for a future change
     * @param fileid Invalidate metadata unless this is the current fileid
     */
    invalidateUnless(fileid: number) {
      if (this.fileid === fileid) return;
      this.state = Math.random();
      this.loading = 0;
      this.error = false;
      this.fileid = null;
      this.exif = {};
      this.syncEditBuffer();
    },

    /** Reset the inline-editable text field buffer to match the loaded EXIF data */
    syncEditBuffer() {
      for (const field of TEXT_FIELDS) {
        this.editBuffer[field] = String(this.exif[field] ?? '');
      }
    },

    /** Save a single inline-editable text field, if its value actually changed */
    saveField(field: keyof IExif) {
      const value = this.editBuffer[field] ?? '';
      if (value === String(this.exif[field] ?? '')) return;
      this.updateExif(this.fileid!, { [field]: value } as Partial<IExif>);
    },

    /** Blur the input on Enter, triggering its save-on-blur handler */
    onEnterBlur(e: KeyboardEvent) {
      (e.target as HTMLInputElement)?.blur();
    },

    editDate() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [1]);
    },

    editTags() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [2]);
    },

    editEmbeddedTags() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [6]);
    },

    editGeo() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [4]);
    },

    editEXIF() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [3]);
    },

    /** Open the full "Edit metadata" modal, covering every section */
    editAll() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!]);
    },

    async updateExif(fileid: number, fields: Partial<IExif>) {
      this.lock = true;
      try {
        const raw = this.exif ?? {};
        //optimistically update the exif
        this.exif = { ...raw, ...fields };
        await axios.patch<IImageInfo>(API.IMAGE_SETEXIF(fileid), { raw: fields });
      } catch (e) {
        console.error('Failed to save metadata for', fileid, e);
        if (e.response?.data?.message) {
          showError(e.response.data.message);
        } else {
          showError(e);
        }
      } finally {
        this.lock = false;
        utils.bus.emit('files:file:updated', { fileid });
      }
    },

    updateRating(rating: number) {
      // Clicking the current rating again clears it. Clearing must send an explicit 0
      // (xmp:Rating 0 = unrated), never undefined: JSON.stringify drops undefined keys, so the
      // PATCH body would be empty and the clear would silently no-op.
      this.updateExif(this.fileid!, { Rating: rating === (this.exif.Rating ?? 0) ? 0 : rating });
    },

    clearRating() {
      this.updateExif(this.fileid!, { Rating: 0 });
    },

    handleFileUpdated({ fileid }: utils.BusEvent['files:file:updated']) {
      if (fileid && this.fileid === fileid) {
        this.refresh();
      }
    },

    async guardState<T>(promise: Promise<T>): Promise<T | null> {
      const state = this.state;
      try {
        this.loading++;
        const res = await promise;
        if (state === this.state) return res;
        return null;
      } catch (err) {
        this.error = true;
        throw err;
      } finally {
        if (state === this.state) this.loading--;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.section-title {
  font-variant: all-small-caps;
  padding: 0px 6px;
}

.exif-head {
  padding: 4px 6px;

  .title {
    font-weight: 500;
  }

  .description,
  .title {
    font-size: 0.93em;
    line-height: 1.5em;
    padding-bottom: 3px;

    cursor: pointer;
    &:hover {
      text-decoration: underline;
      text-decoration-color: #ddd;
      text-underline-offset: 4px;
    }
  }
}

.shared-by {
  > .top-field {
    margin-top: 6px;
    margin-bottom: 10px;
  }
  .name {
    margin-left: 8px;
  }
}

.people {
  margin-bottom: 6px;
  > .section-title {
    margin-bottom: 4px;
  }
  > .container {
    width: calc(100% / 3);
    aspect-ratio: 1;
    position: relative;
    display: inline-block;
    vertical-align: top;
    font-size: 0.85em;

    @media (max-width: 768px) {
      font-size: 0.95em;
    }
  }
}

.albums {
  font-size: 0.96em;
  :deep .line-one__title {
    font-weight: 400 !important; // no bold title
  }
}

.top-field {
  margin-left: 10px;
  margin-top: 10px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;

  .icon,
  .edit {
    display: inline-block;
    margin-right: 10px;

    :deep .material-design-icon {
      color: var(--color-text-lighter);
    }
  }
  .edit {
    transform: translateX(10px);
  }
  .text {
    display: inline-block;
    word-break: break-word;
    flex: 1;

    .subtitle {
      font-size: 0.95em;
      .part {
        margin-right: 5px;
      }
    }
  }

  &--image-info .title {
    user-select: all; // filename or basename
  }

  &--title,
  &--description,
  &--label {
    .edit-field {
      margin-top: 2px;
      --input-padding-start: 0px;
      --input-padding-end: 0px;

      :deep(.input-field__main-wrapper) {
        height: auto;
      }

      :deep(.input-field__input) {
        background: transparent;
        box-shadow: none !important;
        border: none;
        border-bottom: 1px solid var(--color-border-dark);
        border-radius: 0;
        padding: 2px 0;
        height: auto !important;
        cursor: text;
      }

      :deep(.input-field__input:hover:not(:disabled)) {
        box-shadow: none !important;
        border-bottom-color: var(--color-main-text);
      }

      :deep(.input-field__input:focus),
      :deep(.input-field__input:active) {
        box-shadow: none !important;
        border-bottom: 2px solid var(--color-primary-element);
      }

      :deep(.input-field__input:disabled) {
        cursor: default;
        border-bottom-style: dotted;
      }
    }
  }
}

.edit-all-button {
  margin: 10px auto 0;
  display: flex;
}

.loading-icon {
  height: 75%;
}

.map {
  width: 100%;
  aspect-ratio: 16 / 10;
  min-height: 200px;
  max-height: 250px;
}

.rating-stars-inline {
  width: fit-content;
  margin-top: 2px;
  margin-left: -4px; // offset the star buttons' own padding so they align with other titles
  margin-right: auto; // force left alignment, overriding any inherited centering
}

.tags-container {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;

  :deep .chip {
    margin: 0;
  }
}

.tag-path {
  padding: 8px 12px;
  font-size: 0.9em;
  white-space: nowrap;
}
</style>
