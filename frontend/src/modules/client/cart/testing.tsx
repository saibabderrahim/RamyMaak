import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi } from 'vitest'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { ClientFamily, ClientProduct, ClientToday, CurrentUser, Order, OrderLine, OrderSummary } from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { totals } from '../../../shared/money'
import '../../../shared/i18n/orders'
import { json, makeUser, mockApi } from '../../../test/utils'
import CataloguePage from '../catalogue/CataloguePage'
import ClientHomePage from '../home/ClientHomePage'
import CartPage from './CartPage'
import { cartKey, cartStore, type Cart, type CartLine } from './store'
import { TestProbe } from './TestProbe'

/**
 * Jeu de données et rendu des tests de l'accueil, du catalogue et du panier
 * du client (non chargé par l'application). Les pages sont rendues sans la
 * coquille ni le chargement à la demande du module.
 *
 * Journée du jeudi 08/10/2026, livrée le dimanche 11/10, commandes ouvertes
 * jusqu'à 13:00 (12:00 UTC). Produits : 42 Jus orange 1 L (borne 50, déjà
 * 10, disponible 40, mis en avant), 57 Jus pomme 1 L (sans borne), 58 Jus
 * fraise 1 L (épuisé), famille Jus ; 61 Lben 1 L (famille Laitiers, TVA 9 %,
 * sans photo).
 */

export const DAY = '2026-10-08'
export const USER_ID = 9
export const CLIENT_ID = 12
export const TODAY_URL = 'GET /api/v1/client/today'
export const ORDERS_URL = 'POST /api/v1/client/orders'
export const ORDER_ID = '01JA2Z6K8QW3X9M4T7RBN5CDEF'
export const ORDER_NUMBER = 'RMK-261008-00042'

export const clientUser = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('client', {
    id: USER_ID,
    client_id: CLIENT_ID,
    name: 'Gérant C0012',
    permissions: ['order.view', 'order.create', 'limit.view'],
    ...overrides,
  })

export const JUS: ClientFamily = { id: 2, code: 'JUS', name: 'Jus', sort_order: 1 }
export const LAIT: ClientFamily = { id: 1, code: 'LAIT', name: 'Laitiers', sort_order: 2 }

export const photoOf = (id: number, main = true) => ({
  id,
  is_main: main,
  urls: { thumb: `/media/p${id}-200.webp`, medium: `/media/p${id}-600.webp`, large: `/media/p${id}-1200.webp` },
})

export function makeProduct(overrides: Partial<ClientProduct> & Pick<ClientProduct, 'id' | 'name'>): ClientProduct {
  return {
    sku: `P00${overrides.id}`,
    short_name: overrides.name,
    family_id: JUS.id,
    format: '1 L',
    sales_unit_label: 'Fardeau 6 × 1 L',
    units_per_sales_unit: 6,
    vat_rate: 19,
    price: { price_ht: 900, price_ttc: 1071, unit_price_ht: 150, valid_from: '2026-01-01' },
    photos: [photoOf(overrides.id)],
    is_new: false,
    is_featured: false,
    limit: null,
    engaged: 0,
    available: null,
    sold_out: false,
    ...overrides,
  }
}

export const orange = makeProduct({
  id: 42,
  name: 'Jus orange 1 L',
  short_name: 'Orange 1 L',
  photos: [photoOf(7), photoOf(8, false)],
  is_featured: true,
  limit: 50,
  engaged: 10,
  available: 40,
})
export const pomme = makeProduct({ id: 57, name: 'Jus pomme 1 L', short_name: 'Pomme Ramy', is_new: true })
export const fraise = makeProduct({ id: 58, name: 'Jus fraise 1 L', limit: 20, engaged: 20, available: 0, sold_out: true })
export const lben = makeProduct({
  id: 61,
  name: 'Lben 1 L',
  short_name: 'Lben',
  family_id: LAIT.id,
  format: null,
  sales_unit_label: 'Caisse 12 × 1 L',
  units_per_sales_unit: 12,
  vat_rate: 9,
  price: { price_ht: 130.5, price_ttc: 142.25, unit_price_ht: 10.88, valid_from: '2026-01-01' },
  photos: [],
})

export function makeSummary(overrides: Partial<OrderSummary> = {}): OrderSummary {
  return {
    id: ORDER_ID,
    number: ORDER_NUMBER,
    day: DAY,
    status: 'SUBMITTED',
    status_label: 'Envoyée',
    version: 1,
    submitted_at: '2026-10-08T08:40:00+00:00',
    total_ht: 58500,
    total_ttc: 69615,
    lines_count: 2,
    is_adjusted: false,
    can_modify: true,
    can_cancel: true,
    ...overrides,
  }
}

export function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    ...makeSummary(),
    submitted_by: { id: USER_ID, name: 'Gérant C0012' },
    comment: null,
    total_vat: 11115,
    decided_at: null,
    decision_reason_code: null,
    decision_reason: null,
    decision_comment: null,
    modifiable_until: '2026-10-08T12:00:00+00:00',
    lines: [
      {
        product_id: orange.id,
        sku: orange.sku,
        name: orange.name,
        short_name: orange.short_name,
        sales_unit_label: orange.sales_unit_label,
        photo: null,
        requested_qty: 45,
        validated_qty: null,
        shipped_qty: null,
        unit_price_ht: 900,
        vat_rate: 19,
        amount_ht: 40500,
        adjustment_reason_code: null,
        adjustment_reason: null,
        adjustment_comment: null,
      },
    ],
    history: [{ from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null }],
    ...overrides,
  }
}

/**
 * Commande telle que l'enregistre le serveur pour les lignes `lines` (produits
 * du jeu de données) : prix du jour, montants et totaux calculés comme le
 * serveur (shared/money.ts).
 */
export function orderOf(lines: readonly CartLine[], overrides: Partial<Order> = {}): Order {
  const orderLines = lines.map(({ product_id, quantity }): OrderLine => {
    const product = [orange, pomme, fraise, lben].find((candidate) => candidate.id === product_id)
    if (!product) throw new Error(`Produit ${product_id} absent du jeu de données`)
    const priced = { unit_price_ht: product.price.price_ht, vat_rate: product.vat_rate, quantity }
    return {
      product_id,
      sku: product.sku,
      name: product.name,
      short_name: product.short_name,
      sales_unit_label: product.sales_unit_label,
      photo: product.photos[0] ? { thumb: product.photos[0].urls.thumb, medium: product.photos[0].urls.medium } : null,
      requested_qty: quantity,
      validated_qty: null,
      shipped_qty: null,
      unit_price_ht: priced.unit_price_ht,
      vat_rate: priced.vat_rate,
      amount_ht: totals([priced]).ht,
      adjustment_reason_code: null,
      adjustment_reason: null,
      adjustment_comment: null,
    }
  })
  const sums = totals(orderLines.map((line) => ({ unit_price_ht: line.unit_price_ht, vat_rate: line.vat_rate, quantity: line.requested_qty })))
  return makeOrder({ lines: orderLines, lines_count: orderLines.length, total_ht: sums.ht, total_vat: sums.vat, total_ttc: sums.ttc, ...overrides })
}

export function makeToday(overrides: Partial<ClientToday> = {}): ClientToday {
  return {
    server_time: '2026-10-08T08:30:12+00:00',
    day: DAY,
    delivery_date: '2026-10-11',
    status: 'ORDERING_OPEN',
    ordering_cutoff: '13:00',
    ordering_cutoff_at: '2026-10-08T12:00:00+00:00',
    ordering_cutoff_extended: false,
    client: { id: CLIENT_ID, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE' },
    max_orders_per_day: 1,
    min_amount_ht: null,
    can_submit: true,
    submit_blocker: null,
    orders: [],
    families: [JUS, LAIT],
    products: [orange, pomme, fraise, lben],
    ...overrides,
  }
}

export type ApiHandler = Parameters<typeof mockApi>[0][string]

/** API simulée : compte client, notifications, journée du client (`today`, appelé à chaque lecture), autres routes. */
export function clientApi(today: (url: URL) => Response, routes: Record<string, ApiHandler> = {}, user: CurrentUser = clientUser()) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, from: null, to: null, unread_count: 0 } }),
    [TODAY_URL]: ({ url }) => today(url),
    ...routes,
  })
  /** Paramètres des lectures de la journée, dans l'ordre. */
  const todayQueries = () =>
    mock.fetchMock.mock.calls
      .map(([input]) => new URL(String(input), 'http://localhost'))
      .filter((url) => url.pathname === '/api/v1/client/today')
      .map((url) => Object.fromEntries(url.searchParams))
  const requests = (route: string) => mock.calls.filter((call) => `${call.method} ${call.path}` === route)
  return { ...mock, todayQueries, requests }
}

/** Texte tel que lu par toHaveTextContent (espaces insécables normalisés). */
export const plain = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ')

/** Panier partagé du compte de test (le même que celui des pages). */
export const testCart = () => cartStore(USER_ID, CLIENT_ID)

/** Panier tel qu'enregistré dans le navigateur. */
export function storedCart(): Cart | null {
  const raw = localStorage.getItem(cartKey(USER_ID, CLIENT_ID))
  return raw === null ? null : (JSON.parse(raw) as Cart)
}

/** Accueil, catalogue et panier du client sous `path`, détail d'une commande simulé. */
export function renderClient(path: string, user: CurrentUser = clientUser(), state?: unknown) {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[state === undefined ? path : { pathname: path, state }]}>
        <Routes>
          <Route path="/client" element={<ClientHomePage />} />
          <Route path="/client/catalogue" element={<CataloguePage />} />
          <Route path="/client/panier" element={<CartPage />} />
          <Route path="/client/commandes/:id" element={<p>Détail de la commande</p>} />
        </Routes>
        <TestProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...rendered, queryClient }
}

/** Écran de PC (≥ 1 024 px) ou de téléphone : matchMedia simulé. */
export function mockDesktop(desktop: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query === '(min-width: 1024px)' ? desktop : false,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    })),
  )
}
