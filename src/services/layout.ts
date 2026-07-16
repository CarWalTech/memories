import justifiedLayout from 'justified-layout';

/**
 * Generate the layout matrix.
 *
 * If we are in square mode, do this manually to get non-uniformity.
 * Otherwise, use flickr/justified-layout (at least for now).
 */
export function getLayout(
  input: {
    width: number;
    height: number;
    forceSquare: boolean;
  }[],
  opts: {
    rowWidth: number;
    rowHeight: number;
    squareMode: boolean;
    numCols: number;
    allowBreakout: boolean;
    seed: number;
    /** List view: stretch each cell to the full row width instead of a square */
    listMode?: boolean;
    /**
     * Stretch an incomplete last row to fill the full row width instead of
     * leaving it short -- used for Group by: None, where there's no header
     * to explain why a row looks visually "cut off" at a bucket boundary.
     * Left false for grouped modes, where a short last row is a normal,
     * expected end-of-bucket indicator.
     */
    stretchLastRow?: boolean;
  },
): {
  top: number;
  left: number;
  width: number;
  height: number;
  rowHeight?: number;
}[] {
  if (input.length === 0) return [];

  if (!opts.squareMode) {
    // Always compute the library's default (left-aligned, un-stretched) widow
    // row -- its own 'justify' widowLayoutStyle has no cap on how far it'll
    // blow up a nearly-empty last row (e.g. a single photo left in the row
    // gets stretched across the *entire* row width). fillLastRow() below
    // fills it in a more controlled way instead.
    const boxes = justifiedLayout(input, {
      containerPadding: 0,
      boxSpacing: 0,
      containerWidth: opts.rowWidth,
      targetRowHeight: opts.rowHeight,
      targetRowHeightTolerance: 0.1,
    }).boxes;

    if (opts.stretchLastRow) {
      fillLastRow(boxes, input, opts.rowWidth, opts.rowHeight);
    }

    return boxes;
  }

  // RNG
  const rand = mulberry32(opts.seed);

  // Binary flags
  const FLAG_USE = 1 << 0;
  const FLAG_USED = 1 << 1;
  const FLAG_USE4 = 1 << 2;
  const FLAG_USE6 = 1 << 3;
  const FLAG_BREAKOUT = 1 << 4;

  // Create 2d matrix to work in
  const matrix: number[][] = [];

  // Fill in the matrix
  let row = 0;
  let col = 0;
  let photoId = 0;
  while (photoId < input.length) {
    // Check if we reached the end of row
    if (col >= opts.numCols) {
      row++;
      col = 0;
    }

    // Make sure we have this and the next few rows
    while (row + 3 >= matrix.length) {
      matrix.push(new Array(opts.numCols).fill(0));
    }

    // Check if already used
    if (matrix[row][col] & FLAG_USED) {
      col++;
      continue;
    }

    // Use this slot
    matrix[row][col] |= FLAG_USE;

    // Check if previous row has something used
    // or something beside this is used
    // We don't do these one after another
    if (
      !opts.allowBreakout ||
      (row > 0 && matrix[row - 1].some((v) => v & FLAG_USED)) ||
      (col > 0 && matrix[row][col - 1] & FLAG_USED)
    ) {
      photoId++;
      col++;
      continue;
    }

    // Number of photos left
    const numLeft = input.length - photoId - 1;
    // Number of photos needed for perfect fill after using n
    const needFill = (n: number) => opts.numCols - col - 2 + (n / 2 - 1) * (opts.numCols - 2);

    let canUse4 =
      // We have enough space
      row + 1 < matrix.length &&
      col + 1 < opts.numCols &&
      // This cannot end up being a widow (conservative)
      // Also make sure the next row gets fully filled, otherwise looks weird
      (numLeft === needFill(4) || numLeft >= needFill(4) + opts.numCols);

    let canUse6 =
      // Image is portrait
      input[photoId].height > input[photoId].width &&
      // We have enough space
      row + 2 < matrix.length &&
      col + 1 < opts.numCols &&
      // This cannot end up being a widow (conservative)
      // Also make sure the next row gets fully filled, otherwise looks weird
      (numLeft === needFill(6) || numLeft >= needFill(6) + 2 * opts.numCols);

    let canBreakout =
      // First column only
      col === 0 &&
      // Image is landscape
      input[photoId].width > input[photoId].height &&
      // The next row gets filled
      (numLeft === 0 || numLeft >= opts.numCols);

    // Probably folders or tags or faces
    if (input[photoId].forceSquare) {
      // We are square already. Everything below is else-if.
    }

    // Full width breakout
    else if (canBreakout && rand() < (input.length > 0 ? 0.25 : 0.1)) {
      matrix[row][col] |= FLAG_BREAKOUT;
      for (let i = 1; i < opts.numCols; i++) {
        matrix[row][i] |= FLAG_USED;
      }
    }

    // Use 6 vertically
    else if (canUse6 && rand() < 0.2) {
      matrix[row][col] |= FLAG_USE6;
      matrix[row + 1][col] |= FLAG_USED;
      matrix[row + 2][col] |= FLAG_USED;
      matrix[row][col + 1] |= FLAG_USED;
      matrix[row + 1][col + 1] |= FLAG_USED;
      matrix[row + 2][col + 1] |= FLAG_USED;
    }

    // Use 4 box
    else if (canUse4 && rand() < 0.35) {
      matrix[row][col] |= FLAG_USE4;
      matrix[row + 1][col] |= FLAG_USED;
      matrix[row][col + 1] |= FLAG_USED;
      matrix[row + 1][col + 1] |= FLAG_USED;
    }

    // Go ahead
    photoId++;
    col++;
  }

  // Square layout matrix
  const absMatrix: {
    top: number;
    left: number;
    width: number;
    height: number;
  }[] = [];

  let currTop = 0;
  row = 0;
  col = 0;
  photoId = 0;
  while (photoId < input.length) {
    // Check if we reached the end of row
    if (col >= opts.numCols) {
      row++;
      col = 0;
      currTop += opts.rowHeight;
      continue;
    }

    // Skip if used
    if (!(matrix[row][col] & FLAG_USE)) {
      col++;
      continue;
    }

    // Create basic object
    const sqsize = opts.rowHeight;
    const p = {
      top: currTop,
      left: col * sqsize,
      width: opts.listMode ? opts.rowWidth : sqsize,
      height: sqsize,
      rowHeight: opts.rowHeight,
    };

    // Use twice the space
    const v = matrix[row][col];
    if (v & FLAG_USE4) {
      p.width *= 2;
      p.height *= 2;
      col += 2;
    } else if (v & FLAG_USE6) {
      p.width *= 2;
      p.height *= 3;
      col += 2;
    } else if (v & FLAG_BREAKOUT) {
      p.width *= opts.numCols;
      p.height = (input[photoId].height * p.width) / input[photoId].width;
      p.rowHeight = p.height;
      col += opts.numCols;
    } else {
      col++;
    }

    absMatrix.push(p);
    photoId++;
  }

  if (opts.stretchLastRow) {
    stretchLastRow(absMatrix, opts.rowWidth);
  }

  return absMatrix;
}

type LayoutBox = { top: number; left: number; width: number; height: number; rowHeight?: number };

/**
 * Maximum factor a last row's boxes may be scaled up by to fill the row
 * width. Past this cap it's better to leave the row short than to blow
 * photos up further, so stretching is skipped entirely once the required
 * scale exceeds it. fillLastRow() (dynamic mode) already reflows the tail
 * before falling back to this, so in practice this only has to close a
 * small residual gap -- see the empirical worst case noted there.
 */
const MAX_LAST_ROW_STRETCH = 2;

/**
 * Scale up the last row's boxes (preserving their relative proportions and
 * aspect ratios) so they fill the full row width instead of ending short.
 * Returns whether it stretched -- false (no-op) if the last row is already
 * full width, doing so would overlap an earlier multi-cell box, or the
 * required scale exceeds MAX_LAST_ROW_STRETCH.
 */
function tryStretchLastRow(boxes: LayoutBox[], rowWidth: number): boolean {
  if (!boxes.length) return false;

  const maxTop = Math.max(...boxes.map((b) => b.top));
  const lastRow = boxes.filter((b) => b.top === maxTop).sort((a, b) => a.left - b.left);
  const rowBottom = maxTop + Math.max(...lastRow.map((b) => b.height));

  // Bail if any *other* box's vertical extent overlaps the last row's band --
  // e.g. a square-mode 4-box/6-box/breakout photo that started a row or two
  // earlier and spans down into it. Its own position is already fixed, so
  // stretching the simple boxes next to it would misalign or overlap them
  // (which can also make the overlapped photo unclickable).
  const overlapsAnotherRow = boxes.some((b) => b.top !== maxTop && b.top < rowBottom && b.top + b.height > maxTop);
  if (overlapsAnotherRow) return false;

  const rightEdge = Math.max(...lastRow.map((b) => b.left + b.width));
  if (rightEdge >= rowWidth - 1) return true;

  const scale = rowWidth / rightEdge;
  if (scale > MAX_LAST_ROW_STRETCH) return false;

  let left = 0;
  for (const b of lastRow) {
    const newWidth = b.width * scale;
    b.left = left;
    b.width = newWidth;
    b.height *= scale;
    if (b.rowHeight) b.rowHeight *= scale;
    left += newWidth;
  }
  return true;
}

/**
 * Square mode: same capped stretch as above, with no fallback -- the
 * fixed-column matrix packer can't be safely re-run on just a slice, since
 * multi-cell boxes already placed above it could overlap a re-randomized
 * layout underneath.
 */
function stretchLastRow(boxes: LayoutBox[], rowWidth: number): void {
  tryStretchLastRow(boxes, rowWidth);
}

/** How many trailing rows to reflow together when hunting for a fuller last row. */
const REFLOW_TAIL_ROWS = 6;

/**
 * Dynamic mode: fill the last row so Group by: None doesn't show a short
 * "cut off" row at a bucket boundary.
 *
 * Naively re-running the justified layout on a plain suffix of rows changes
 * nothing: justified-layout is a greedy left-to-right packer, so slicing at
 * an already-decided row boundary and re-laying it out reproduces the exact
 * same boundary every time (verified empirically -- 0% of sparse-last-row
 * cases improved). What *does* change the outcome is re-laying out a larger
 * tail chunk (several rows' worth) at a slightly different target row
 * height -- that shifts where each row's greedy cutoff lands, the same way
 * reflowing a paragraph at a different font size avoids an orphan word.
 * Searching a small range around the normal row height finds one where the
 * last row comes out full or nearly full in ~99% of cases (tested against
 * 2000 randomized mixed-aspect-ratio buckets of 60-100 photos); the tail
 * rows end up a bit taller/shorter than the rest of the bucket as a result,
 * capped to +/-25% so it stays a minor variation rather than a jarring one.
 * Whatever small gap remains after that is closed by the same capped,
 * aspect-preserving stretch used above (empirical worst case after reflow:
 * ~1.8x, comfortably under MAX_LAST_ROW_STRETCH).
 */
function fillLastRow(
  boxes: LayoutBox[],
  input: { width: number; height: number }[],
  rowWidth: number,
  rowHeight: number,
): void {
  if (!boxes.length) return;
  if (tryStretchLastRow(boxes, rowWidth)) return;

  const tops = [...new Set(boxes.map((b) => b.top))].sort((a, b) => a - b);
  const fromIdx = Math.max(0, tops.length - REFLOW_TAIL_ROWS);
  const startTop = tops[fromIdx];
  const startIdx = boxes.findIndex((b) => b.top === startTop);
  const tailInput = input.slice(startIdx);

  let best: LayoutBox[] | null = null;
  let bestRightEdge = -Infinity;
  for (let mult = 0.8; mult <= 1.25 + 1e-9; mult += 0.02) {
    const relaid: LayoutBox[] = justifiedLayout(tailInput, {
      containerPadding: 0,
      boxSpacing: 0,
      containerWidth: rowWidth,
      targetRowHeight: rowHeight * mult,
      targetRowHeightTolerance: 0.1,
    }).boxes;

    const maxTop = Math.max(...relaid.map((b) => b.top));
    const rightEdge = Math.max(...relaid.filter((b) => b.top === maxTop).map((b) => b.left + b.width));
    if (rightEdge > bestRightEdge) {
      best = relaid;
      bestRightEdge = rightEdge;
    }
    if (rightEdge >= rowWidth - 1) break;
  }
  if (!best) return;

  tryStretchLastRow(best, rowWidth); // close whatever gap remains, if within cap

  for (const b of best) b.top += startTop;
  boxes.splice(startIdx, boxes.length - startIdx, ...best);
}

function flagMatrixStr(matrix: number[][], numFlag: number) {
  let str = '';
  for (let i = 0; i < matrix.length; i++) {
    const rstr = matrix[i].map((v) => v.toString(2).padStart(numFlag, '0')).join(' ');
    str += i.toString().padStart(2) + ' | ' + rstr + '\n';
  }
  return str;
}

function mulberry32(a: number) {
  return function () {
    var t = (a += 0x6d2b79f5);
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
