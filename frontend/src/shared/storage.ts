// Stockage local du navigateur (panier du client, brouillons) : toute lecture
// et écriture est protégée, le stockage pouvant être indisponible (navigation
// privée, quota atteint) ; la mémoire reste alors la seule copie.

/** Lit une valeur JSON ; null si absente, illisible ou stockage indisponible. */
export function readJson(key: string): unknown {
  try {
    const raw = window.localStorage.getItem(key)
    return raw === null ? null : (JSON.parse(raw) as unknown)
  } catch {
    return null
  }
}

/** Écrit une valeur JSON ; null ou undefined retire la clé. */
export function writeJson(key: string, value: unknown): void {
  try {
    if (value === null || value === undefined) window.localStorage.removeItem(key)
    else window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // Stockage indisponible ou plein : rien à faire.
  }
}

/** Retire toutes les clés qui commencent par le préfixe (ex. à la déconnexion). */
export function removeKeys(prefix: string): void {
  try {
    const keys: string[] = []
    for (let index = 0; index < window.localStorage.length; index++) {
      const key = window.localStorage.key(index)
      if (key?.startsWith(prefix)) keys.push(key)
    }
    keys.forEach((key) => window.localStorage.removeItem(key))
  } catch {
    // Stockage indisponible.
  }
}

/** Événement émis après une purge (déconnexion) : les copies en mémoire se relisent. */
export const STORAGE_PURGED_EVENT = 'ramymaak:storage-purged'

/** Données propres à la session retirées à la déconnexion (appareil partagé) : paniers des clients. */
const SESSION_PREFIXES = ['ramymaak:cart:']

/** Brouillons de bornes d'un compte (saisies de la répartition sans accusé de réception, RG-B09). */
export const limitDraftsPrefix = (userId: number) => `ramymaak:limit-drafts:${userId}:`

/**
 * Déconnexion : paniers retirés, ainsi que les brouillons de bornes du compte
 * `userId` (lot 8, §6.1). Le cache persistant et la boîte d'envoi, dans
 * IndexedDB, sont effacés à part (shared/offline/persist.ts, `purgeAccount`).
 */
export function purgeSessionStorage(userId?: number): void {
  SESSION_PREFIXES.forEach(removeKeys)
  if (userId !== undefined) removeKeys(limitDraftsPrefix(userId))
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(STORAGE_PURGED_EVENT))
}
