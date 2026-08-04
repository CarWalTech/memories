declare module '@typings' {
  export type ITick = {
    /** Day ID */
    dayId: number;
    /** Display top position */
    topF: number;
    /** Display top position (truncated to 1 decimal pt) */
    top: number;
    /** Y coordinate on recycler */
    y: number;
    /** Cumulative number of photos before this tick */
    count: number;
    /** Is a new month */
    isMonth: boolean;
    /** Text if any (e.g. year) */
    text?: string | number;
    /** Whether this tick should be shown */
    s?: boolean;
    /** Key for vue component */
    key?: number;
  };

  export interface TimelineState {
    list: IRow[];
    heads: Map<number, IHeadRow>;
  }

  /** Type of IRow (0 = head, 1 = photos) */
  export type IRowType = 0 | 1;

  /**
   * A contiguous, independently-fetched and independently-laid-out slice of
   * one head's photos (see services/segments.ts).
   *
   * Segments generalize the "Group by: None" pseudo-buckets they replaced:
   * those only ever MERGED several small days into one fetch unit, whereas a
   * segment can also SPLIT a single large day into several. Both directions
   * exist so the unit of work stays roughly constant regardless of how the
   * library's photos happen to be distributed across days.
   */
  export type ISegment = {
    /**
     * Unique key. Opaque -- unlike IRow.dayId this is NOT a day id and must
     * never be date-parsed.
     */
    id: string;
    /** The head (bucket) id this segment renders under */
    dayId: number;
    /** Real backend day ids covered. Length > 1 only for merged buckets. */
    realDayIds: number[];
    /** Photo offset within the bucket's ordered photo list */
    offset: number;
    /** Max photos in this segment, or null when it covers the whole bucket */
    limit: number | null;
    /** Position of this segment within its day's segment list */
    index: number;
    /** Photo count: estimated from the days list, corrected once fetched */
    count: number;
    /** Whether the request for this segment carries chunk params */
    chunked: boolean;
    /** Whether fetching this segment fetches (and satisfies) the whole day */
    fetchWhole: boolean;
    /** Whether this is the last segment of its day */
    final: boolean;
    /** Photos, once loaded */
    photos?: IPhoto[];
    /** Rows owned by this segment (a contiguous slice of day.rows) */
    rows: IRow[];
  };

  export type IRow = {
    /** Vue Recycler identifier */
    id?: string;
    /** Row ID from head */
    num: number;
    /** Day ID */
    dayId: number;
    /** Id of the segment that owns this row (see ISegment) */
    segId: string;
    /** Refrence to day object */
    day: IDay;
    /** Type of row (0 = head, 1 = photos) */
    type: IRowType;
    /** Main list of photo items */
    photos?: IPhoto[];

    /** Height in px of the row */
    size: number;
    /** Count of placeholders to create */
    pct?: number;
    /** Don't remove dom element */
    virtualSticky?: boolean;
  };

  export type IHeadRow = IRow & {
    /** Type of row */
    type: 0;
    /** Title of the header */
    name?: string;
    /** Bucket granularity this header represents, when shown (see date.ts's getHeadRowName) */
    groupMode?: 'day' | 'week' | 'month' | 'year';
    /** Group by: None -- header exists for bucket bookkeeping (dayId/day) but isn't rendered */
    collapsed?: boolean;
    /**  Boolean if the entire day is selected */
    selected: boolean;
    /** Bigger header text */
    super?: string;
  };
}
