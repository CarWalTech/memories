import type { IFilters } from '@typings';

/** The "no filters applied" state, shared by every consumer of IFilters. */
export function emptyFilters(): IFilters {
  return {
    minRating: 0,
    maxRating: 0,
    favorite: false,
    filename: '',
    filenameRegex: false,
    filepath: '',
    filepathRegex: false,
    dateStart: '',
    dateEnd: '',
    fileType: [],
    extensions: [],
    camMake: '',
    camModel: '',
    camLens: '',
    tags: [],
    embeddedTags: [],
    untaggedCollab: false,
    untaggedEmbedded: false,
    filterExpr: null,
  };
}

/** Whether any filter in the given set is actually constraining results. */
export function hasActiveFilters(f: IFilters): boolean {
  return (
    f.minRating > 0 ||
    f.maxRating > 0 ||
    f.favorite ||
    '' !== f.filename ||
    '' !== f.filepath ||
    '' !== f.dateStart ||
    '' !== f.dateEnd ||
    f.fileType.length > 0 ||
    f.extensions.length > 0 ||
    '' !== f.camMake ||
    '' !== f.camModel ||
    '' !== f.camLens ||
    f.tags.length > 0 ||
    f.embeddedTags.length > 0 ||
    f.untaggedCollab ||
    f.untaggedEmbedded ||
    null !== f.filterExpr
  );
}
