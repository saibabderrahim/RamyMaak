import { type QueryClient, useMutation } from '@tanstack/react-query'
import { api, ApiError, buildUrl, csrfHeaders, NetworkError } from '../../../shared/api/client'
import type { Order, OrderViolation, SubmitOrderInput, UpdateOrderInput } from '../../../shared/api/types'
import { orderKey } from '../api'
import { normalizeComment, orderLines } from './reconcile'
import { type Cart, type CartLine, type CartStore, useCart } from './store'

// Envoi du panier (EF-CLT-03, RG-C07) — contrat : docs/lot-5-contrat-api.md §3 et §6.

/**
 * Commande rendue par l'envoi : `created` (201, nouvelle commande),
 * `replayed` (200, renvoi d'un identifiant déjà reçu : la commande existante,
 * telle qu'enregistrée, I-54) ou `updated` (PATCH).
 */
export type SendResult = { order: Order; kind: 'created' | 'replayed' | 'updated' }

/**
 * POST /client/orders avec le code de la réponse, que `request()`
 * (shared/api/client.ts) ne rend pas : 201 pour une nouvelle commande, 200
 * pour un renvoi (RG-C07). Même transport que `request()` : cookie de
 * session, jeton anti-CSRF renouvelé et requête rejouée une fois après 419,
 * erreurs `NetworkError` et `ApiError`.
 */
export async function postOrder(input: SubmitOrderInput): Promise<{ order: Order; created: boolean }> {
  const body = JSON.stringify(input)
  const send = async (renew: boolean) => {
    try {
      return await fetch(buildUrl('/client/orders'), {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(await csrfHeaders(renew)) },
        body,
      })
    } catch {
      throw new NetworkError()
    }
  }

  let response = await send(false)
  if (response.status === 419) response = await send(true)
  const payload: unknown = await response.json().catch(() => null)
  if (!response.ok) throw ApiError.fromResponse(response.status, payload)
  return { order: payload as Order, created: response.status === 201 }
}

/**
 * Envoi de `lines` (le panier tel qu'affiché) : PATCH de la commande en
 * cours de modification (avec sa version), sinon POST d'une nouvelle
 * commande. L'identifiant d'envoi est enregistré par `store.beginSend()`
 * avant la requête, et réutilisé par une nouvelle tentative (même commande,
 * jamais de doublon).
 */
export async function sendCart(store: CartStore, cart: Cart): Promise<SendResult> {
  const lines = orderLines(cart.lines)
  const comment = normalizeComment(cart.comment)

  if (cart.editing) {
    const body: UpdateOrderInput = { version: cart.editing.version, comment, lines }
    const order = await api.patch<Order>(`/client/orders/${encodeURIComponent(cart.editing.orderId)}`, body)
    return { order, kind: 'updated' }
  }

  if (cart.day === null) throw new Error('Panier sans journée')
  const { order, created } = await postOrder({ client_request_id: store.beginSend(), day: cart.day, comment, lines })
  return { order, kind: created ? 'created' : 'replayed' }
}

/**
 * Mutation d'envoi. `networkMode: 'always'` : hors ligne, la requête part et
 * échoue aussitôt (NetworkError) au lieu d'attendre en pause (pas d'envoi
 * différé avant le lot 7).
 */
export function useSendCart() {
  const { store } = useCart()
  return useMutation({
    networkMode: 'always',
    mutationFn: (cart: Cart) => sendCart(store, cart),
  })
}

/**
 * Échec sans réponse définitive du serveur (réseau, 5xx, 429, session ou
 * jeton expirés) : l'envoi a pu aboutir, l'identifiant est gardé pour la
 * nouvelle tentative. Tout autre refus est définitif (`abandonSend()`).
 */
export function isRetryable(error: unknown): boolean {
  if (error instanceof NetworkError) return true
  if (!(error instanceof ApiError)) return true
  return error.status >= 500 || [401, 408, 419, 429].includes(error.status)
}

/** Problème d'une ligne signalé par le serveur ; `quantity` : quantité envoyée. */
export type LineIssue = { quantity: number } & (
  | { kind: 'unavailable' }
  | { kind: 'limit'; max: number | null }
  | { kind: 'field'; messages: string[] }
)

const LINE_FIELD = /^lines\.(\d+)\.(product_id|quantity)$/

/**
 * Refus rapporté aux lignes envoyées (`sent`, dans l'ordre de l'envoi) :
 * `violations[]` (PRODUCT_NOT_AVAILABLE, CLIENT_LIMIT_EXCEEDED) par produit,
 * erreurs de format `lines.N.*` par indice. `fields` : les autres messages.
 */
export function sendIssues(error: ApiError, sent: readonly CartLine[]): { lines: Map<number, LineIssue>; fields: string[] } {
  const lines = new Map<number, LineIssue>()
  const quantityOf = (productId: number) => sent.find((line) => line.product_id === productId)?.quantity ?? 0

  const violations = Array.isArray(error.problem.violations) ? (error.problem.violations as OrderViolation[]) : []
  for (const violation of violations) {
    const productId = violation.product_id
    const quantity = violation.requested ?? quantityOf(productId)
    lines.set(
      productId,
      violation.max_allowed !== undefined || violation.requested !== undefined
        ? { kind: 'limit', max: violation.max_allowed ?? null, quantity }
        : { kind: 'unavailable', quantity },
    )
  }

  const fields: string[] = []
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    const match = LINE_FIELD.exec(field)
    const line = match ? sent[Number(match[1])] : undefined
    if (!line) {
      fields.push(...messages)
      continue
    }
    const previous = lines.get(line.product_id)
    lines.set(line.product_id, {
      kind: 'field',
      quantity: line.quantity,
      messages: [...(previous?.kind === 'field' ? previous.messages : []), ...messages],
    })
  }

  return { lines, fields }
}

/** Commande relue sur le serveur (version et droits à jour). */
export function fetchOrder(queryClient: QueryClient, id: string): Promise<Order> {
  return queryClient.fetchQuery({
    queryKey: orderKey(id),
    queryFn: ({ signal }) => api.get<Order>(`/client/orders/${encodeURIComponent(id)}`, undefined, signal),
    staleTime: 0,
  })
}

/**
 * La commande porte exactement le panier : même journée, mêmes produits et
 * quantités (dans n'importe quel ordre), même commentaire sans les espaces
 * autour. Sert à reconnaître une commande déjà enregistrée par un envoi
 * dont la réponse a été perdue (renvoi 200, VERSION_CONFLICT d'une
 * modification qui avait abouti).
 */
export function sameContent(order: Pick<Order, 'day' | 'lines' | 'comment'>, cart: Pick<Cart, 'day' | 'lines' | 'comment'>): boolean {
  if (order.day !== cart.day) return false
  if (normalizeComment(order.comment ?? '') !== normalizeComment(cart.comment)) return false
  if (order.lines.length !== cart.lines.length) return false
  const quantities = new Map(order.lines.map((line) => [line.product_id, line.requested_qty]))
  return cart.lines.every((line) => quantities.get(line.product_id) === line.quantity)
}
