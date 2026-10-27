/// <reference lib="webworker" />
/**
 * Photos produits en « cache d'abord » (lot 8, contrat §6.2) : 30 jours,
 * 500 images au plus. L'API n'est jamais mise en cache (I-101). Volet D.
 *
 * Route indépendante de celle de la navigation : celle-ci ne répond qu'aux
 * navigations, et `/media/` figure dans NAVIGATION_DENYLIST. Les URL des
 * photos sont versionnées (lot 2) : une photo remplacée change d'URL, la
 * copie enregistrée ne peut donc pas être périmée. Hors ligne, une photo
 * jamais vue échoue, et l'application montre le visuel par défaut.
 */
import type { WorkboxPlugin } from 'workbox-core'
import { ExpirationPlugin } from 'workbox-expiration'
import { registerRoute } from 'workbox-routing'
import { CacheFirst } from 'workbox-strategies'
import { isCacheablePhoto, isPhotoRequest, PHOTO_CACHE, PHOTO_MAX_AGE_SECONDS, PHOTO_MAX_ENTRIES } from '../shared/offline/photos'

declare let self: ServiceWorkerGlobalScope

/** Rôle de workbox-cacheable-response (non installé) : réponses 200 seulement. */
const cacheOkOnly: WorkboxPlugin = {
  cacheWillUpdate: async ({ response }) => (isCacheablePhoto(response) ? response : null),
}

export function registerPhotoCache(): void {
  registerRoute(
    ({ url, request }) => isPhotoRequest(url, request.method, self.location.origin),
    new CacheFirst({
      cacheName: PHOTO_CACHE,
      plugins: [cacheOkOnly, new ExpirationPlugin({ maxEntries: PHOTO_MAX_ENTRIES, maxAgeSeconds: PHOTO_MAX_AGE_SECONDS, purgeOnQuotaError: true })],
    }),
  )
}
