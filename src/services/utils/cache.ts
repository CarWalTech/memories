import config from '../static-config';
import { uid } from './helpers';

/**
 * Cache bucket name.
 *
 * Deliberately synchronous. config.get() waits for StaticConfig's init, which
 * is a network request to /api/config -- so deriving the bucket name from it
 * put a round trip in front of every read of already-stashed data, which is
 * exactly the wait the cache exists to avoid. getSync() reads the version
 * mirrored in localStorage instead, which is available on the first tick.
 *
 * On an app upgrade the mirrored version is briefly the previous one, so the
 * first reads after an update come from the old bucket. That resolves itself:
 * init() writes the new version through and calls onVersionChange() below, and
 * the sweep at module load deletes every bucket but the current one.
 */
function getCacheName() {
  return `memories-data-${config.getSync('version')}-${uid}`;
}

// Clear all caches except the current one
(async function clearCaches() {
  if (!uid) return;

  const keys = await window.caches?.keys();
  if (!keys?.length) return;

  const cacheName = getCacheName();

  for (const key of keys) {
    if (key.match(/^memories-data-/) && key !== cacheName) {
      window.caches.delete(key);
    }
  }
})();

/** Singleton cache instance */
let staticCache: Cache | null = null;
let staticCacheName: string | null = null;

/**
 * Drop the open bucket so the next access reopens under the current version.
 * Called by StaticConfig when the server reports a version we hadn't seen.
 */
export function onVersionChange() {
  staticCache = null;
  staticCacheName = null;
}

/**
 * Open (and remember) the cache bucket.
 *
 * The open promise itself is memoized, not just its result: a cold timeline
 * fires many cache reads on the same tick, and without this each one would
 * start its own caches.open().
 */
let openPromise: Promise<Cache | null> | null = null;
export async function openCache() {
  const name = getCacheName();
  if (staticCache && staticCacheName === name) return staticCache;
  if (openPromise && staticCacheName === name) return await openPromise;

  staticCacheName = name;
  openPromise = (async () => {
    try {
      return (staticCache = (await window.caches?.open(name)) ?? null);
    } catch {
      return null;
    }
  })();

  return await openPromise;
}

/** Get data from the cache */
export async function getCachedData<T>(url: string): Promise<T | null> {
  if (!window.caches) return null;
  const cache = await openCache();
  if (!cache) return null;

  const cachedResponse = await cache.match(url);
  if (!cachedResponse || !cachedResponse.ok) return null;
  return await cachedResponse.json();
}

/** Store data in the cache */
export function cacheData(url: string, data: Object) {
  if (!window.caches) return;
  const str = JSON.stringify(data);

  (async () => {
    const cache = await openCache();
    if (!cache) return;

    const response = new Response(str);
    const encoded = new TextEncoder().encode(str);
    response.headers.set('Content-Type', 'application/json');
    response.headers.set('Content-Length', encoded.length.toString());
    response.headers.set('Cache-Control', 'max-age=604800'); // 1 week
    response.headers.set('Vary', 'Accept-Encoding');
    await cache.put(url, response);
  })();
}
