/**
 * The name of the offline cache, in one place (#102).
 *
 * Shared by the service worker, which fills it, and by `lib/pwa.ts`, which
 * empties it. Its own module because those two are compiled against different
 * libraries — `sw.ts` under `WebWorker`, everything else under `DOM` — so
 * neither can import the other without dragging in globals the other does not
 * have. A string duplicated across that boundary is one that silently stops
 * matching the day somebody bumps the version.
 */
export const OFFLINE_CACHE = 'goldieflow-offline-v1';
