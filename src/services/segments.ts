import type { IDay, ISegment } from '@typings';

/**
 * Group by: None -- minimum photo count per merged bucket. Large enough that
 * seams between buckets are infrequent; small enough to keep individual
 * fetches reasonably sized.
 */
export const MIN_UNGROUPED_BUCKET_PHOTOS = 60;

/**
 * Photos per chunk when a day is split.
 *
 * Chosen large enough that the DB cost of a deep OFFSET stays irrelevant (a
 * fully scrolled 6000-photo day is ~13 queries, not ~60) and that justified
 * layout has ample slack to fill each chunk's last row, but small enough that
 * neither the JSON payload nor the layout pass blocks for long.
 */
export const CHUNK_SIZE = 500;

/**
 * Days at or below this stay a single unchunked segment, requested on exactly
 * the same URL (and therefore the same cache key) as before chunking existed.
 * Above it, the day is split into CHUNK_SIZE segments.
 *
 * Deliberately above CHUNK_SIZE: it means a chunked day always has more than
 * CHUNK_SIZE photos, so "the response is longer than the window I asked for"
 * is an unambiguous signal that the server declined to page.
 */
export const CHUNK_THRESHOLD = 750;

export type BuildSegmentsOpts = {
  /** Whether headers are shown per day (false => Group by: None) */
  isGrouped: boolean;
  /** Whether days may be split for fetching (not just for layout) */
  pagable: boolean;
  /** Whether consecutive days may be merged into shared buckets */
  mergeable: boolean;
};

export type BuildSegmentsResult = {
  /** Head-level days, after any merging. One header is created per entry. */
  buckets: IDay[];
  /** All segments, in display order */
  segments: ISegment[];
  /** Real backend day id -> the bucket id it was merged into */
  realToBucket: Map<number, number>;
};

/**
 * Turn a /days response into the buckets that get a header and the segments
 * that get fetched and laid out.
 *
 * Two independent transformations, in order:
 *
 *  1. MERGE (Group by: None only) -- consecutive days are folded into one
 *     bucket until it holds at least MIN_UNGROUPED_BUCKET_PHOTOS, so layout
 *     packs across old day boundaries with no seam.
 *
 *  2. SPLIT -- any bucket holding more than CHUNK_THRESHOLD photos is divided
 *     into CHUNK_SIZE segments. Without this, a single day holding thousands
 *     of photos is one unbounded request and one unbounded layout pass.
 *
 * A bucket always keeps exactly one header regardless of how many segments it
 * ends up with: ScrollerManager pairs scrollbar ticks to head rows by day id
 * alone, so a second head for the same day would silently misalign every tick
 * after it.
 */
export function buildSegments(days: IDay[], opts: BuildSegmentsOpts): BuildSegmentsResult {
  const realToBucket = new Map<number, number>();
  const bucketRealIds = new Map<number, number[]>();

  // Step 1 -- merge consecutive days into buckets
  let buckets: IDay[];
  if (opts.mergeable) {
    buckets = [];
    let current: IDay | null = null;
    for (const day of days) {
      if (day.count === 0) continue;
      if (!current || current.count >= MIN_UNGROUPED_BUCKET_PHOTOS) {
        current = { dayid: day.dayid, count: 0, rows: [] };
        bucketRealIds.set(current.dayid, []);
        buckets.push(current);
      }
      current.count += day.count;
      bucketRealIds.get(current.dayid)!.push(day.dayid);
      realToBucket.set(day.dayid, current.dayid);
    }
  } else {
    buckets = days;
    for (const day of days) {
      bucketRealIds.set(day.dayid, [day.dayid]);
      realToBucket.set(day.dayid, day.dayid);
    }
  }

  // Step 2 -- split large buckets into segments
  const segments: ISegment[] = [];
  for (const bucket of buckets) {
    if (bucket.count === 0) continue;

    const realDayIds = bucketRealIds.get(bucket.dayid) ?? [bucket.dayid];
    const split = bucket.count > CHUNK_THRESHOLD;
    const nSegs = split ? Math.ceil(bucket.count / CHUNK_SIZE) : 1;

    for (let i = 0; i < nSegs; i++) {
      const offset = i * CHUNK_SIZE;
      segments.push({
        id: `${bucket.dayid}:${offset}`,
        dayId: bucket.dayid,
        realDayIds,
        offset,
        limit: split ? CHUNK_SIZE : null,
        index: i,
        count: split ? Math.min(CHUNK_SIZE, bucket.count - offset) : bucket.count,
        // A segment is always the unit of layout. It is the unit of FETCH only
        // when the server can page: otherwise one whole-day request satisfies
        // every segment of the day at once, which still avoids the giant
        // single layout pass even though the download stays monolithic.
        chunked: split && opts.pagable,
        fetchWhole: split && !opts.pagable,
        final: i === nSegs - 1,
        rows: [],
      });
    }
  }

  return { buckets, segments, realToBucket };
}

/**
 * Recompute a day's segments after its true photo count turns out to differ
 * from the estimate the days list gave (photos deleted, filtered client-side,
 * or a chunk coming back short).
 *
 * Returns the segments that should now exist for the day, reusing the
 * identity (and therefore rows/photos/cache keys) of those that survive.
 */
export function resegment(existing: ISegment[], count: number): ISegment[] {
  const first = existing[0];
  if (!first) return [];

  const split = count > CHUNK_THRESHOLD && (first.chunked || first.fetchWhole);
  const nSegs = Math.max(1, split ? Math.ceil(count / CHUNK_SIZE) : 1);
  const out: ISegment[] = [];

  for (let i = 0; i < nSegs; i++) {
    const offset = i * CHUNK_SIZE;
    const prev = existing[i];
    const seg: ISegment = prev ?? {
      ...first,
      id: `${first.dayId}:${offset}`,
      offset,
      index: i,
      photos: undefined,
      rows: [],
    };

    seg.count = split ? Math.min(CHUNK_SIZE, count - offset) : count;
    seg.final = i === nSegs - 1;
    out.push(seg);
  }

  return out;
}
