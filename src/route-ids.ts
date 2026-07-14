// Route ids and their vue-router `name`s, kept dependency-free (no component
// imports) so services that only need "which route am I on" -- e.g.
// @services/view-mode-scope -- don't have to import router.ts and its full
// component graph. router.ts itself builds its `routes` table's `name`
// fields from ROUTE_NAMES so the two stay in sync.
//
// Importing router.ts from here (or from anything router.ts's component
// graph depends on) would reintroduce a circular import: router.ts imports
// the route components (Timeline.vue, etc.), which import mixins/services
// that need RouteId, which would import router.ts back before it finished
// initializing its `routes` export -- a TDZ ReferenceError at runtime.

export type RouteId =
  | 'Base'
  | 'Folders'
  | 'AllPhotos'
  | 'Favorites'
  | 'Videos'
  | 'Albums'
  | 'Archive'
  | 'ThisDay'
  | 'Recognize'
  | 'FaceRecognition'
  | 'Places'
  | 'Tags'
  | 'EmbeddedTags'
  | 'FolderShare'
  | 'AlbumShare'
  | 'Map'
  | 'Explore'
  | 'NxSetup';

export const ROUTE_NAMES: Record<RouteId, string> = {
  Base: 'timeline',
  Folders: 'folders',
  AllPhotos: 'all-photos',
  Favorites: 'favorites',
  Videos: 'videos',
  Albums: 'albums',
  Archive: 'archive',
  ThisDay: 'thisday',
  Recognize: 'recognize',
  FaceRecognition: 'facerecognition',
  Places: 'places',
  Tags: 'tags',
  EmbeddedTags: 'embeddedtags',
  FolderShare: 'folder-share',
  AlbumShare: 'album-share',
  Map: 'map',
  Explore: 'explore',
  NxSetup: 'nxsetup',
};

export const ALL_ROUTE_IDS = Object.keys(ROUTE_NAMES) as RouteId[];
