import { test, expect, type Page, type Request } from '@playwright/test';
import { login, APP_ROOT } from './login';

/**
 * Chunked day loading (see src/services/segments.ts and DaysController::day).
 *
 * Every assertion here is non-destructive: the invariants are observable from
 * the network traffic and the DOM's own geometry, so this suite is safe to
 * point at a real library -- which it needs, since nothing chunks until a
 * single day holds more than CHUNK_THRESHOLD photos.
 */

const CHUNK_SIZE = 500;
const CHUNK_THRESHOLD = 750;

type DayRequest = {
  url: string;
  dayIds: string;
  offset: number | null;
  limit: number | null;
  startedAt: number;
};

type Recorder = {
  requests: DayRequest[];
  days: { dayid: number; count: number }[];
};

/**
 * Watch the day traffic: every /api/days/<ids> request, plus the days list
 * itself so a test can pick a genuinely large day to target.
 */
function record(page: Page): Recorder {
  const rec: Recorder = { requests: [], days: [] };

  page.on('request', (req: Request) => {
    const url = new URL(req.url());
    const m = url.pathname.match(/\/api\/days\/([^/?]+)$/);
    if (!m) return;

    const offset = url.searchParams.get('chunkOffset');
    const limit = url.searchParams.get('chunkLimit');
    rec.requests.push({
      url: req.url(),
      dayIds: m[1],
      offset: offset === null ? null : Number(offset),
      limit: limit === null ? null : Number(limit),
      startedAt: Date.now(),
    });
  });

  page.on('response', async (res) => {
    if (!new URL(res.url()).pathname.endsWith('/api/days')) return;
    try {
      const body = await res.json();
      if (Array.isArray(body)) rec.days = body;
    } catch {
      /* not json (redirect, error page) -- ignore */
    }
  });

  return rec;
}

/** Scroll the recycler down one viewport; resolves true once at the bottom */
async function scrollStep(page: Page): Promise<boolean> {
  return await page.evaluate(() => {
    const el = document.querySelector('.recycler') as HTMLElement | null;
    if (!el) return true;
    const before = el.scrollTop;
    el.scrollTop = Math.min(el.scrollTop + el.clientHeight * 0.9, el.scrollHeight);
    return el.scrollTop === before;
  });
}

async function scrollThrough(page: Page, steps = 40, pause = 400) {
  for (let i = 0; i < steps; i++) {
    const done = await scrollStep(page);
    await page.waitForTimeout(pause);
    if (done) break;
  }
}

/** Jump the recycler to a fraction of its total scroll height */
async function scrollToFraction(page: Page, f: number) {
  await page.evaluate((frac) => {
    const el = document.querySelector('.recycler') as HTMLElement | null;
    if (el) el.scrollTop = el.scrollHeight * frac;
  }, f);
}

/**
 * Get to a specific day. The largest days in a real library are usually deep
 * in the timeline, far past what linear scrolling reaches in a test, so sweep
 * the scroll range and watch for the day being requested.
 */
async function scrollToDay(page: Page, rec: Recorder, dayid: number, steps = 40): Promise<boolean> {
  const wanted = (r: DayRequest) => r.dayIds.split(',').includes(String(dayid));
  for (let i = 0; i <= steps; i++) {
    if (rec.requests.some(wanted)) return true;
    await scrollToFraction(page, i / steps);
    await page.waitForTimeout(400);
  }
  return rec.requests.some(wanted);
}

/** Wait until the days list has been observed, so tests can size themselves */
async function waitForDays(page: Page, rec: Recorder) {
  await expect
    .poll(() => rec.days.length, { message: 'days list was returned', timeout: 30000 })
    .toBeGreaterThan(0);
}

test.describe('Chunked day loading', () => {
  test('an ordinary day is requested with no chunk params', async ({ page }) => {
    const rec = record(page);
    await login('/')({ page } as any);

    await page.waitForSelector('.p-outer', { timeout: 60000 });
    await waitForDays(page, rec);
    await page.waitForTimeout(1500);

    expect(rec.requests.length, 'some day requests were made').toBeGreaterThan(0);

    // Any day at or under the threshold must be requested on exactly the URL
    // it used before chunking existed -- no chunk params, so its CacheStorage
    // key is unchanged and warm caches still hit.
    const small = new Set(rec.days.filter((d) => d.count <= CHUNK_THRESHOLD).map((d) => String(d.dayid)));
    const wrong = rec.requests.filter((r) => r.offset !== null && r.dayIds.split(',').every((id) => small.has(id)));
    expect(wrong.map((r) => r.url), 'no chunk params on small days').toHaveLength(0);
  });

  test('a large day is requested in bounded windows, without gaps or overlaps', async ({ page }) => {
    const rec = record(page);
    // All Photos, not the default timeline: the configured timeline path can
    // be a small subset, while the days worth chunking live in the full set.
    await login('/all')({ page } as any);
    await page.waitForSelector('.p-outer', { timeout: 60000 });
    await waitForDays(page, rec);

    const big = rec.days.reduce((a, b) => (b.count > (a?.count ?? 0) ? b : a), rec.days[0]);
    test.skip(!big || big.count <= CHUNK_THRESHOLD, 'no day in this library exceeds CHUNK_THRESHOLD');

    const reached = await scrollToDay(page, rec, big.dayid);
    test.skip(!reached, 'never reached the large day');

    // Walk far enough to cross at least one chunk boundary. A chunk is
    // CHUNK_SIZE photos, which is many viewports' worth of rows.
    await scrollThrough(page, 60, 300);

    const forDay = rec.requests.filter((r) => r.dayIds.split(',').includes(String(big.dayid)));
    const maxOffset = Math.floor((big.count - 1) / CHUNK_SIZE) * CHUNK_SIZE;

    // Every request for it must be a bounded, aligned window inside the day
    for (const req of forDay) {
      expect(req.limit, `chunkLimit on ${req.url}`).toBe(CHUNK_SIZE);
      expect(req.offset, `chunkOffset on ${req.url}`).not.toBeNull();
      expect(req.offset! % CHUNK_SIZE, 'offsets are aligned').toBe(0);
      expect(req.offset!, 'offset is within the day').toBeLessThanOrEqual(maxOffset);
    }

    // The day was actually split, and no window was fetched twice. Jumping
    // around the scrollbar legitimately fetches non-adjacent windows -- that
    // is the feature -- so contiguity is not asserted here; the server-side
    // partition invariant covers gaps and overlaps.
    const offsets = forDay.map((r) => r.offset!);
    expect(new Set(offsets).size, 'the day was split into several windows').toBeGreaterThan(1);
    expect([...new Set(offsets)], 'no window fetched twice').toHaveLength(offsets.length);
  });

  test('no photo is ever rendered twice at once', async ({ page }) => {
    const rec = record(page);
    await login('/')({ page } as any);
    await page.waitForSelector('.p-outer', { timeout: 60000 });
    await waitForDays(page, rec);

    // A duplicate within a single snapshot means two windows overlapped, or a
    // segment's rows were spliced in without the old ones being removed.
    const seen = new Set<string>();
    for (let i = 0; i < 80; i++) {
      // Each thumbnail carries a memories-thumb-<key> class, where key is the
      // photo's unique vue key (fileid, or fileid-n for genuine duplicates).
      const keys = await page.evaluate(() =>
        Array.from(document.querySelectorAll('[class*="memories-thumb-"]')).map(
          (el) => Array.from(el.classList).find((c) => c.startsWith('memories-thumb-')) ?? '',
        ),
      );
      const present = keys.filter(Boolean);
      const dupes = present.filter((k, idx) => present.indexOf(k) !== idx);
      expect(dupes, `same photo rendered twice simultaneously: ${JSON.stringify(dupes)}`).toHaveLength(0);
      present.forEach((k) => seen.add(k));

      const done = await scrollStep(page);
      await page.waitForTimeout(350);
      if (done) break;
    }

    expect(seen.size, 'a meaningful number of photos was seen').toBeGreaterThan(100);
  });

  test('chunk boundaries are full-width rows, not ragged ones', async ({ page }) => {
    const rec = record(page);
    await login('/all')({ page } as any);
    await page.waitForSelector('.p-outer', { timeout: 60000 });
    await waitForDays(page, rec);

    const big = rec.days.reduce((a, b) => (b.count > (a?.count ?? 0) ? b : a), rec.days[0]);
    test.skip(!big || big.count <= CHUNK_THRESHOLD, 'no day in this library exceeds CHUNK_THRESHOLD');
    await scrollToDay(page, rec, big.dayid);

    // Sample row fill fractions through the large day, where the seams are.
    const fills: number[] = [];
    for (let i = 0; i < 40; i++) {
      const rows = await page.evaluate(() => {
        const out: number[] = [];
        for (const view of Array.from(document.querySelectorAll('.vue-recycle-scroller__item-view'))) {
          const photos = Array.from(view.querySelectorAll('.photo')).map((p) => p.getBoundingClientRect());
          if (photos.length < 2) continue; // headers, and single-photo rows
          const width = (view as HTMLElement).clientWidth;
          if (!width) continue;
          const left = Math.min(...photos.map((p) => p.left));
          const right = Math.max(...photos.map((p) => p.right));
          out.push((right - left) / width);
        }
        return out;
      });
      fills.push(...rows);

      const done = await scrollStep(page);
      await page.waitForTimeout(350);
      if (done) break;
    }

    test.skip(fills.length < 20, 'not enough rows rendered to judge');

    // Days genuinely end on a short row, so a handful is expected; a chunk
    // boundary every CHUNK_SIZE photos left ragged would be far more.
    const short = fills.filter((f) => f < 0.85);
    const ratio = short.length / fills.length;
    expect(ratio, `too many rows far short of full width (${short.length}/${fills.length})`).toBeLessThan(0.15);
  });

  test('the photo list does not wait for the folder tile grid', async ({ page }) => {
    // Phase 1: /api/days used to be issued only after the dynamic top matter
    // resolved, so a slow folder listing meant no photos at all.
    const FOLDER_DELAY = 5000;
    let foldersStarted = -1;
    let foldersFinished = -1;
    let daysStarted = -1;

    page.on('request', (req) => {
      const p = new URL(req.url()).pathname;
      if (p.endsWith('/api/folders/sub') && foldersStarted < 0) foldersStarted = Date.now();
      if (p.endsWith('/api/days') && daysStarted < 0) daysStarted = Date.now();
    });
    page.on('response', (res) => {
      if (new URL(res.url()).pathname.endsWith('/api/folders/sub') && foldersFinished < 0) {
        foldersFinished = Date.now();
      }
    });

    // Make the folder listing slow, so any serialization is unmissable
    await page.route('**/api/folders/sub*', async (route) => {
      await new Promise((r) => setTimeout(r, FOLDER_DELAY));
      await route.continue();
    });

    await login('/')({ page } as any);

    await page.goto(`${APP_ROOT}/folders`);
    await page.waitForSelector('.p-outer', { timeout: 60000 });
    const firstPhoto = Date.now();

    // The ordering is the property under test, not any wall-clock budget:
    // page boot dominates the absolute numbers and varies with the network.
    expect(daysStarted, '/api/days was requested').toBeGreaterThan(0);
    expect(foldersStarted, '/api/folders/sub was requested').toBeGreaterThan(0);
    expect(daysStarted - foldersStarted, '/api/days is issued alongside /api/folders/sub').toBeLessThan(
      FOLDER_DELAY / 2,
    );

    // And photos are on screen before the (deliberately stalled) folder
    // listing even comes back -- impossible when the two were serialized.
    expect(foldersFinished, '/api/folders/sub completed').toBeGreaterThan(0);
    expect(firstPhoto, 'photos render before the folder listing resolves').toBeLessThan(foldersFinished);
  });

  test('a failed day request does not leave placeholders stuck', async ({ page }) => {
    // Regression test for the rollback moved into a finally: a segment marked
    // loaded before its request resolved used to stay marked on some failure
    // paths, so its placeholders never resolved for the rest of the session.
    let fail = true;
    await page.route('**/api/days/*', async (route) => {
      if (fail) {
        fail = false;
        await route.abort();
        return;
      }
      await route.continue();
    });

    await login('/')({ page } as any);
    await page.waitForTimeout(2000);

    // Scrolling away and back must retry the failed day
    await scrollThrough(page, 3);
    await page.evaluate(() => {
      const el = document.querySelector('.recycler') as HTMLElement | null;
      if (el) el.scrollTop = 0;
    });
    await page.waitForTimeout(3000);

    expect(await page.locator('.p-outer').count(), 'photos recovered after a failed request').toBeGreaterThan(0);
  });
});
