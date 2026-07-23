declare module '@typings' {
  type HighResCond = 'always' | 'zoom' | 'never';
  type MetadataReadMode = 'sidecar_only' | 'prefer_sidecar' | 'prefer_file' | 'file_only';
  type MetadataWriteMode = 'sidecar_only' | 'file_only' | 'file_and_sidecar';
  type TagMode = 'embedded' | 'collab' | 'both';

  export type IConfig = {
    // general stuff
    version: string;
    vod_disable: boolean;
    video_default_quality: string;
    places_gis: number;

    // enabled apps
    systemtags_enabled: boolean;
    embeddedtags_enabled: boolean;
    systemtags_available: boolean;
    albums_enabled: boolean;
    recognize_installed: boolean;
    recognize_enabled: boolean;
    facerecognition_installed: boolean;
    facerecognition_enabled: boolean;
    preview_generator_enabled: boolean;

    // general settings
    timeline_path: string;
    all_photos_exclude_paths: string;
    enable_top_memories: boolean;
    stack_raw_files: boolean;
    dedup_identical: boolean;
    show_owner_name_timeline: boolean;
    tag_mode: TagMode;

    // viewer settings
    high_res_cond_default: HighResCond;
    livephoto_autoplay: boolean;
    livephoto_loop: boolean;
    video_loop: boolean;
    sidebar_filepath: boolean;
    metadata_in_slideshow: boolean;
    metadata_in_gallery: boolean;
    enable_exif_photo_rating_in_gallery: boolean;
    show_filename_in_gallery: boolean;
    show_datetime_in_gallery: boolean;
    show_extension_in_gallery: boolean;
    show_dimensions_in_gallery: boolean;
    show_collab_tags_in_gallery: boolean;
    /** Raw JSON, see @services/view-mode-scope -- per-view-type base + per-item overrides for image_display_shape */
    view_mode_scope: string;
    /** Raw JSON, see @services/image-props -- per-mode (square/list) Image Properties overrides */
    image_props_modes: string;
    /** Raw JSON, see @services/list-scope -- per-view-type base + per-item overrides for Sort by */
    sort_scope: string;
    /** Raw JSON, see @services/list-scope -- per-view-type base + per-item overrides for Group by */
    group_scope: string;
    /** Raw JSON, see @services/saved-filters -- list of named saved filter presets */
    saved_filters: string;

    // metadata settings
    metadata_write_mode: MetadataWriteMode;
    metadata_read_mode: MetadataReadMode;

    // folder settings
    folders_path: string;
    favorite_folders: string;
    show_hidden_folders: boolean;
    show_empty_folders: boolean;

    // album settings
    show_hidden_albums: boolean;

    // mobile settings
    mobile_nav_items: string[];

    // local settings
    high_res_cond: HighResCond | null;
    show_face_rect: boolean;
    grid_zoom: number;
    blur_thumbnails: boolean;
    /** Whether the viewer's info sidebar should be open by default, per-device */
    sidebar_open: boolean;
  };
}
