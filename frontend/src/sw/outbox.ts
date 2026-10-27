/// <reference lib="webworker" />
/**
 * Envoi différé en arrière-plan (Background Sync, étiquette
 * `ramymaak-outbox`) de la boîte d'envoi des commandes client (lot 8,
 * contrat §6.3). Volet D.
 *
 * Le service worker n'envoie que les commandes du compte connecté sur
 * l'appareil (`session-user`), avec le jeton anti-CSRF lu par l'API Cookie
 * Store ; la logique d'envoi est celle de l'application
 * (shared/offline/outbox.ts). Après chaque résultat, les fenêtres ouvertes
 * sont prévenues (`outbox-updated`) et, application fermée ou cachée, une
 * notification locale annonce la commande envoyée ou refusée.
 */
import {
  decodeXsrf,
  needsRetry,
  OUTBOX_MESSAGE,
  OUTBOX_SYNC_TAG,
  outboxNotice,
  type ProcessResult,
  processPending,
  readSessionUser,
} from '../shared/offline/outbox'

export { OUTBOX_SYNC_TAG }

declare let self: ServiceWorkerGlobalScope

/** Événement Background Sync (absent des définitions de TypeScript). */
interface SyncEvent extends ExtendableEvent {
  readonly tag: string
  readonly lastChance: boolean
}

async function readXsrf(): Promise<string | null> {
  try {
    const cookie = 'cookieStore' in self ? await self.cookieStore.get('XSRF-TOKEN') : null
    return decodeXsrf(cookie?.value)
  } catch {
    return null
  }
}

async function windows(): Promise<readonly WindowClient[]> {
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true })
}

async function report(result: ProcessResult): Promise<void> {
  const open = await windows()
  open.forEach((client) => client.postMessage({ type: OUTBOX_MESSAGE }))

  const notice = outboxNotice(result)
  // Application à l'écran : la boîte d'envoi s'y met à jour, pas de notification en plus.
  const visible = open.some((client) => client.visibilityState === 'visible')
  if (!notice || visible || typeof Notification === 'undefined' || Notification.permission !== 'granted') return
  await self.registration.showNotification(notice.title, {
    body: notice.body,
    tag: notice.tag,
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-96.png',
    data: { url: notice.url },
  })
}

async function syncOutbox(): Promise<void> {
  const userId = await readSessionUser()
  if (userId === null) return
  const results = await processPending(userId, { fetch: (input, init) => fetch(input, init), xsrf: readXsrf }, report)
  // Réseau ou serveur encore indisponibles : le navigateur relancera la synchronisation plus tard.
  if (needsRetry(results)) throw new Error('Boîte d’envoi : commande encore en attente')
}

export function registerOutboxSync(): void {
  self.addEventListener('sync', (event: Event) => {
    const sync = event as SyncEvent
    if (sync.tag !== OUTBOX_SYNC_TAG) return
    sync.waitUntil(syncOutbox())
  })
}
