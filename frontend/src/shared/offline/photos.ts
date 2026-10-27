/**
 * Photos produits gardées par le service worker (lot 8, contrat §6.2) :
 * règles sans dépendance au service worker, partagées avec les tests.
 */

export const PHOTO_CACHE = 'ramymaak-photos'
export const PHOTO_MAX_ENTRIES = 500
export const PHOTO_MAX_AGE_SECONDS = 30 * 24 * 60 * 60

/** Photo servie par nginx (§9.8) : même origine, sous /media/, en lecture. Jamais l'API (I-101). */
export function isPhotoRequest(url: URL, method: string, origin: string): boolean {
  return url.origin === origin && url.pathname.startsWith('/media/') && method === 'GET'
}

/** Seules les réponses 200 sont enregistrées : jamais une erreur, une redirection ou une réponse partielle (206). */
export function isCacheablePhoto(response: Pick<Response, 'status'>): boolean {
  return response.status === 200
}
