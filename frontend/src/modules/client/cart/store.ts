import { useSyncExternalStore } from 'react'
import type { Order } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { readJson, STORAGE_PURGED_EVENT, writeJson } from '../../../shared/storage'

// Panier du client (EF-CLT-03, §6.2) : il n'existe que sur l'appareil et ne
// réserve rien. Conservé dans le navigateur par compte et par client, il
// survit à un rechargement et se synchronise entre onglets. Un seul panier :
// nouvelle commande, ou modification d'une commande envoyée (`editing`).
// L'identifiant d'envoi (`pending`) est enregistré avant l'envoi et réutilisé
// par une nouvelle tentative : un renvoi ne crée jamais de seconde commande
// (RG-C07).

export const CART_PREFIX = 'ramymaak:cart:'

export type CartLine = { product_id: number; quantity: number }

/** Commande envoyée en cours de modification (PATCH avec sa version). */
export type CartEditing = { orderId: string; number: string; version: number }

export type Cart = {
  v: 1
  /** Journée de la commande, fixée au premier ajout ; null : panier vide. */
  day: string | null
  lines: CartLine[]
  comment: string
  editing: CartEditing | null
  /** Envoi en cours ou interrompu : identifiant à réutiliser pour la nouvelle tentative. */
  pending: { client_request_id: string; at: string } | null
}

export const EMPTY_CART: Cart = { v: 1, day: null, lines: [], comment: '', editing: null, pending: null }

export function cartKey(userId: number, clientId: number): string {
  return `${CART_PREFIX}${userId}:${clientId}`
}

function isLine(value: unknown): value is CartLine {
  if (typeof value !== 'object' || value === null) return false
  const line = value as Record<string, unknown>
  return Number.isInteger(line.product_id) && Number.isInteger(line.quantity) && (line.quantity as number) > 0
}

/** Panier relu et validé ; tout contenu illisible donne un panier vide. */
function parse(value: unknown): Cart {
  if (typeof value !== 'object' || value === null) return EMPTY_CART
  const cart = value as Record<string, unknown>
  if (cart.v !== 1 || !Array.isArray(cart.lines)) return EMPTY_CART
  const editing = cart.editing as Record<string, unknown> | null
  const pending = cart.pending as Record<string, unknown> | null
  return {
    v: 1,
    day: typeof cart.day === 'string' ? cart.day : null,
    lines: cart.lines.filter(isLine).map(({ product_id, quantity }) => ({ product_id, quantity })),
    comment: typeof cart.comment === 'string' ? cart.comment : '',
    editing:
      editing && typeof editing.orderId === 'string' && typeof editing.number === 'string' && Number.isInteger(editing.version)
        ? { orderId: editing.orderId, number: editing.number, version: editing.version as number }
        : null,
    pending:
      pending && typeof pending.client_request_id === 'string' && typeof pending.at === 'string'
        ? { client_request_id: pending.client_request_id, at: pending.at }
        : null,
  }
}

function isEmpty(cart: Cart): boolean {
  return cart.lines.length === 0 && cart.comment === '' && cart.editing === null && cart.pending === null
}

/** Identifiant d'envoi : crypto.randomUUID n'existe qu'en contexte sécurisé (HTTPS). */
export function newRequestId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export class CartStore {
  private readonly key: string
  private cart: Cart
  private readonly listeners = new Set<() => void>()

  constructor(key: string) {
    this.key = key
    this.cart = parse(readJson(key))
  }

  get = (): Cart => this.cart

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  /** Relit le stockage (autre onglet). */
  reload(): void {
    this.cart = parse(readJson(this.key))
    this.emit()
  }

  /** Quantité d'un produit ; 0 ou moins retire la ligne. Le premier ajout fixe la journée. */
  setQuantity(day: string, productId: number, quantity: number): void {
    const lines = this.cart.lines.filter((line) => line.product_id !== productId)
    if (quantity > 0) {
      const index = this.cart.lines.findIndex((line) => line.product_id === productId)
      const line = { product_id: productId, quantity: Math.floor(quantity) }
      if (index === -1) lines.push(line)
      else lines.splice(index, 0, line)
    }
    this.set({ ...this.cart, day: this.cart.day ?? day, lines })
  }

  setComment(comment: string): void {
    this.set({ ...this.cart, comment })
  }

  /** Remplace les lignes (« Recommander », rapprochement avec le catalogue du jour). */
  replace(day: string, lines: CartLine[], comment?: string): void {
    this.set({ ...this.cart, day, lines: lines.filter((line) => line.quantity > 0), comment: comment ?? this.cart.comment })
  }

  /** Charge une commande envoyée pour la modifier (PATCH avec sa version). */
  startEditing(order: Pick<Order, 'id' | 'number' | 'version' | 'day' | 'comment' | 'lines'>): void {
    this.set({
      v: 1,
      day: order.day,
      lines: order.lines.map((line) => ({ product_id: line.product_id, quantity: line.requested_qty })),
      comment: order.comment ?? '',
      editing: { orderId: order.id, number: order.number, version: order.version },
      pending: null,
    })
  }

  /**
   * Début d'un envoi : identifiant enregistré avant la requête, ou celui de
   * l'envoi interrompu (même identifiant pour une nouvelle tentative).
   */
  beginSend(): string {
    if (this.cart.pending) return this.cart.pending.client_request_id
    const pending = { client_request_id: newRequestId(), at: new Date().toISOString() }
    this.set({ ...this.cart, pending })
    return pending.client_request_id
  }

  /** Refus définitif (4xx métier) : le prochain envoi aura un nouvel identifiant. */
  abandonSend(): void {
    if (this.cart.pending) this.set({ ...this.cart, pending: null })
  }

  /** Envoi ou modification réussi, ou panier vidé : tout est effacé. */
  clear(): void {
    this.set(EMPTY_CART)
  }

  private set(cart: Cart): void {
    this.cart = isEmpty(cart) ? EMPTY_CART : cart
    writeJson(this.key, isEmpty(cart) ? null : cart)
    this.emit()
  }

  private emit(): void {
    this.listeners.forEach((listener) => listener())
  }
}

const stores = new Map<string, CartStore>()

/** Une seule instance par panier, partagée par les écrans et les onglets. */
export function cartStore(userId: number, clientId: number): CartStore {
  const key = cartKey(userId, clientId)
  let store = stores.get(key)
  if (!store) {
    store = new CartStore(key)
    stores.set(key, store)
  }
  return store
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === null) stores.forEach((store) => store.reload())
    else stores.get(event.key)?.reload()
  })
  // Déconnexion (shared/auth/session.ts) : paniers retirés du stockage.
  window.addEventListener(STORAGE_PURGED_EVENT, () => stores.forEach((store) => store.reload()))
}

/** Panier du compte connecté (rôle client, rattaché à un client). */
export function useCart(): { cart: Cart; store: CartStore } {
  const user = useAuthenticatedUser()
  const store = cartStore(user.id, user.client_id ?? 0)
  const cart = useSyncExternalStore(store.subscribe, store.get, store.get)
  return { cart, store }
}

const noSubscription = () => () => {}

/** Nombre de lignes du panier du compte connecté (pastille de l'onglet Panier) ; 0 hors rôle client. */
export function useCartLineCount(): number {
  const user = useAuthenticatedUser()
  const store = user.role === 'client' && user.client_id !== null ? cartStore(user.id, user.client_id) : null
  return useSyncExternalStore(store?.subscribe ?? noSubscription, () => store?.get().lines.length ?? 0)
}
