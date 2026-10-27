/**
 * Chemins internes de la PWA (EF-NOT-03) : une notification, dans le centre
 * comme en push, ne mène jamais hors de l'application. Sans DOM ni React :
 * partagé avec le service worker (src/sw/push.ts).
 */

// Caractères que l'analyseur d'adresses retire ou interprète (« /\t/site.example » devient « //site.example »).
const CONTROL_CHARACTERS = /[\u0000-\u001F\u007F]/

/** Chemin interne de la PWA (`/client/commandes/…`) ; null pour toute autre adresse. */
export function internalPath(url: unknown): string | null {
  if (typeof url !== 'string' || CONTROL_CHARACTERS.test(url)) return null
  return url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/\\') ? url : null
}

/** Écran ouvert par une notification sans chemin interne : le centre de notifications. */
export const NOTIFICATIONS_PATH = '/notifications'

/**
 * Adresse complète ouverte par l'appui sur une notification : son chemin
 * interne sur l'origine de l'application, sinon le centre de notifications.
 */
export function notificationTarget(url: unknown, origin: string): string {
  const path = internalPath(url) ?? NOTIFICATIONS_PATH
  const target = new URL(path, origin)
  return target.origin === new URL(origin).origin ? target.href : new URL(NOTIFICATIONS_PATH, origin).href
}
