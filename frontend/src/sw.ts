/// <reference lib="webworker" />
/**
 * Service worker de la PWA (§9.5). Lot 1 : pré-cache de la coquille de
 * l'application et navigation hors ligne. Les lots suivants y ajoutent les
 * photos produits, le catalogue, les notifications push et l'envoi différé.
 */
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'

declare let self: ServiceWorkerGlobalScope

// Mise à jour à la demande de l'utilisateur (bandeau « Recharger »).
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    void self.skipWaiting()
  }
})

// Coquille de l'application, versionnée à chaque déploiement.
precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// Application d'une seule page : toute navigation sert index.html, sauf
// l'API et les points techniques du serveur. Les requêtes d'API ne sont
// jamais mises en cache (réseau uniquement).
registerRoute(
  new NavigationRoute(createHandlerBoundToURL('index.html'), {
    denylist: [/^\/api\//, /^\/sanctum\//, /^\/broadcasting\//, /^\/app\//, /^\/up$/],
  }),
)
