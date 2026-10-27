/// <reference lib="webworker" />
/**
 * Notifications push (lot 8, contrat §2.5 et §7) : affichage, appui sur la
 * notification, changement d'abonnement. Volet C. Le contenu affiché et
 * l'adresse ouverte sont calculés par des fonctions pures, testées
 * (src/shared/push/notification.ts) ; ce module ne fait que les relier aux
 * événements du service worker.
 */
import { clickedPath, notificationOptions, parsePushPayload, pickWindow } from '../shared/push/notification'
import { notificationTarget } from '../shared/push/paths'

declare let self: ServiceWorkerGlobalScope

function readText(data: PushMessageData | null): string | null {
  try {
    return data?.text() ?? null
  } catch {
    return null
  }
}

/**
 * Met au premier plan une fenêtre de l'application et la mène à l'écran de
 * la notification ; sans fenêtre (ou si elle ne peut pas être menue), en
 * ouvre une. Seuls les chemins internes sont suivis (EF-NOT-03).
 */
async function openApp(data: unknown): Promise<void> {
  const url = notificationTarget(clickedPath(data), self.location.origin)
  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
  const client = windows[pickWindow(windows)]
  if (client) {
    try {
      const focused = await client.focus()
      if (focused.url !== url) await focused.navigate(url)
      return
    } catch {
      // Fenêtre non contrôlée par ce service worker : une nouvelle fenêtre.
    }
  }
  await self.clients.openWindow(url)
}

export function registerPush(): void {
  // Une fenêtre ouverte avant l'installation du service worker passe sous son
  // contrôle : l'appui sur une notification peut alors la mener à l'écran voulu.
  self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim())
  })

  self.addEventListener('push', (event) => {
    const displayed = parsePushPayload(readText(event.data))
    event.waitUntil(self.registration.showNotification(displayed.title, notificationOptions(displayed)))
  })

  self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    event.waitUntil(openApp(event.notification.data))
  })

  // Abonnement renouvelé par le service push : réabonnement avec la même clé
  // publique. La PWA renvoie l'abonnement au serveur à sa prochaine ouverture.
  self.addEventListener('pushsubscriptionchange', (event) => {
    const applicationServerKey = event.oldSubscription?.options.applicationServerKey
    if (event.newSubscription || !applicationServerKey) return
    event.waitUntil(
      self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey }).then(
        () => undefined,
        () => undefined,
      ),
    )
  })
}
