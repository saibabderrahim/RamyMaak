import { useEffect, useRef, useState } from 'react'
import type { ClientToday } from '../../../shared/api/types'
import { useClientToday } from '../api'
import { type CartChange, reconcileLines } from './reconcile'
import { type CartStore, useCart } from './store'

// Panier tenu en accord avec la journée du client (contrat du lot 5, §6
// « Panier ») : au chargement et à chaque relecture de GET /client/today,
// produits retirés ou quantités plafonnées, avec un avis ; panier d'une
// autre journée vidé. Heure limite suivie sur l'horloge du serveur (D-10).

/** Avis montré après un changement du panier qui ne vient pas du client. */
export type CartNotice =
  | { kind: 'reconciled'; changes: CartChange[] }
  | { kind: 'dayChanged'; day: string }
  | { kind: 'dayClosed'; day: string }

/**
 * Rapproche le panier de `today`, journée qui le concerne : la journée
 * proposée (nouvelle commande) ou celle de la commande en cours de
 * modification, lue avec `?order=` (disponible hors de ses quantités, I-62 :
 * ses propres lignes ne sont jamais plafonnées ni retirées à cause
 * d'elles-mêmes). Le panier est relu juste avant : ce qui vient d'être
 * saisi est gardé, seul ce qui dépasse est corrigé, et le commentaire n'est
 * jamais touché. Les lignes d'un envoi interrompu restent telles quelles
 * jusqu'au renvoi. Renvoie l'avis à montrer, ou null.
 */
export function syncCart(store: CartStore, today: ClientToday, names: ReadonlyMap<number, string> = new Map()): CartNotice | null {
  const cart = store.get()
  const editingId = cart.editing?.orderId ?? null

  if (editingId === null) {
    // Journée proposée changée (lendemain ouvert, journée suivante) : le panier ne vaut plus.
    if (cart.day !== null && cart.day !== today.day) {
      store.clear()
      return cart.lines.length > 0 ? { kind: 'dayChanged', day: cart.day } : null
    }
    if (cart.lines.length > 0 && today.status === 'DAY_CLOSED') {
      store.clear()
      return { kind: 'dayClosed', day: today.day }
    }
    // Envoi interrompu : la commande a pu être enregistrée, et ses quantités
    // comptées dans l'engagé du client ; plafonner ou retirer ses lignes sur ce
    // disponible les compterait deux fois. Le renvoi du même identifiant tranche
    // (RG-C07) : commande existante, ou refus, puis rapprochement.
    if (cart.pending) return null
  } else {
    // Relecture d'une autre journée (le panier vient de changer) ou commande
    // qui ne peut plus être modifiée : rien à rapprocher, l'abandon est proposé.
    const order = today.orders.find((candidate) => candidate.id === editingId)
    if (cart.day !== today.day || !order?.can_modify) return null
  }

  if (cart.lines.length === 0) return null
  const { lines, changes } = reconcileLines(cart.lines, today.products, names)
  if (changes.length === 0) return null
  store.replace(today.day, lines)
  return { kind: 'reconciled', changes }
}

/**
 * Rapprochement à chaque réception de `today` (chargement, relecture en
 * temps réel ou toutes les 30 s) ; `today` undefined : rien à faire.
 */
export function useCartSync(store: CartStore, today: ClientToday | undefined, receivedAt: number) {
  const [notice, setNotice] = useState<CartNotice | null>(null)
  // Noms des produits déjà vus : un produit qui quitte le catalogue est nommé dans l'avis.
  const names = useRef(new Map<number, string>())

  useEffect(() => {
    if (!today) return
    const outcome = syncCart(store, today, names.current)
    today.products.forEach((product) => names.current.set(product.id, product.name))
    if (outcome) setNotice(outcome)
  }, [store, today, receivedAt])

  return { notice, dismissNotice: () => setNotice(null) }
}

/**
 * Journée du panier : en modification, celle de la commande avec `?order=` ;
 * sinon la journée proposée par le serveur (I-42). Panier rapproché, et
 * relecture à l'heure limite.
 */
export function useCartToday() {
  const { cart, store } = useCart()
  const editingId = cart.editing?.orderId
  const query = useClientToday(editingId ? (cart.day ?? undefined) : undefined, editingId)
  const { notice, dismissNotice } = useCartSync(store, query.data, query.dataUpdatedAt)
  useCutoffRefetch(query.data, query.dataUpdatedAt, query.refetch)
  return { cart, store, query, notice, dismissNotice }
}

/** Marge après l'heure limite avant de relire : le serveur reste juge. */
export const CUTOFF_MARGIN_MS = 1000
// Délai maximal d'un setTimeout (≈ 24,8 jours).
const MAX_TIMEOUT_MS = 2_147_483_647

/**
 * Délai avant l'heure limite `cutoffAt` sur l'horloge du serveur : l'écart
 * entre `serverTime` et l'heure de l'appareil à la réception (`receivedAt`)
 * corrige une horloge d'appareil fausse (D-10). Données anciennes (relues
 * du cache) dont l'heure limite est passée depuis : relecture aussitôt.
 * null : l'heure limite était déjà passée quand le serveur a répondu, rien
 * à attendre (pas de relecture en boucle ; le repli de 30 s continue).
 */
export function cutoffDelay(cutoffAt: string, serverTime: string, receivedAt: number, now: number): number | null {
  const cutoff = Date.parse(cutoffAt)
  const server = Date.parse(serverTime)
  if (Number.isNaN(cutoff) || Number.isNaN(server) || cutoff < server) return null
  const remaining = cutoff - (now + server - receivedAt)
  return Math.min(Math.max(remaining, 0) + CUTOFF_MARGIN_MS, MAX_TIMEOUT_MS)
}

/**
 * Minuterie à `ordering_cutoff_at` tant que la commande est possible ou
 * attendue : relit `today` (le serveur passe alors la journée en
 * « Commandes clôturées »).
 */
export function useCutoffRefetch(today: ClientToday | undefined, receivedAt: number, refetch: () => unknown): void {
  const refetchRef = useRef(refetch)
  useEffect(() => {
    refetchRef.current = refetch
  })

  const watched = today && (today.status === 'ORDERING_OPEN' || today.status === 'WAITING_QUOTAS')
  const cutoffAt = watched ? today.ordering_cutoff_at : null
  const serverTime = today?.server_time ?? null

  useEffect(() => {
    const delay = cutoffAt === null || serverTime === null ? null : cutoffDelay(cutoffAt, serverTime, receivedAt, Date.now())
    if (delay === null) return
    const timer = window.setTimeout(() => void refetchRef.current(), delay)
    return () => window.clearTimeout(timer)
  }, [cutoffAt, serverTime, receivedAt])
}
