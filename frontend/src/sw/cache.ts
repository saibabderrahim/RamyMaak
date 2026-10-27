/// <reference lib="webworker" />
/**
 * Photos produits en « cache d'abord » (lot 8, contrat §6.2) : 30 jours,
 * 500 images au plus. L'API n'est jamais mise en cache (I-101). Volet D.
 *
 * Route indépendante de celle de la navigation : celle-ci ne répond qu'aux
 * navigations, et `/media/` figure dans NAVIGATION_DENYLIST. Les URL des
 * photos sont versionnées (lot 2) : une photo remplacée change d'URL, la
 * copie enregistrée ne peut donc pas être périmée.
 */
import type { WorkboxPlugin } from 'workbox-core'
import { ExpirationPlugin } from 'workbox-expiration'
import { registerRoute } from 'workbox-routing'
import { CacheFirst } from 'workbox-strategies'

export const PHOTO_CACHE = 'ramymaak-photos'
export const PHOTO_MAX_ENTRIES = 500
export const PHOTO_MAX_AGE_SECONDS = 30 * 24 * 60 * 60

/** Photo de l'application servie par nginx : même origine, sous /media/, en lecture. */
export function isPhotoRequest(url: URL, request: Request, origin: string): boolean {
  return url.origin === origin && url.pathname.startsWith('/media/') && request.method === 'GET'
}

/**
 * Seules les réponses 200 sont enregistrées (rôle de workbox-cacheable-response) :
 * jamais une erreur, une redirection ou une réponse partielle.
 */
export const cacheOkOnly: WorkboxPlugin = {
  cacheWillUpdate: async ({ response }) => (response.status === 200 ? response : null),
}

export function registerPhotoCache(): void {
  registerRoute(
    ({ url, request }) => isPhotoRequest(url, request, self.location.origin),
    new CacheFirst({
      cacheName: PHOTO_CACHE,
      plugins: [
        cacheOkOnly,
        new ExpirationPlugin({ maxEntries: PHOTO_MAX_ENTRIES, maxAgeSeconds: PHOTO_MAX_AGE_SECONDS, purgeOnQuotaError: true }),
      ],
    }),
  )
}
