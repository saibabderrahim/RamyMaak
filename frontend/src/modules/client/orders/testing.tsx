import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { ClientProduct, ClientToday, CurrentUser, Order, OrderLine, OrderSummary, Paginated } from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import '../../../shared/i18n/orders'
import { json, makeUser, mockApi } from '../../../test/utils'
import { cartKey, cartStore, type Cart } from '../cart/store'
import OrderDetailPage from './OrderDetailPage'
import OrdersPage from './OrdersPage'
import { TestLocation } from './TestLocation'

/**
 * Jeu de données et rendu des tests de « Mes commandes » (non chargé par
 * l'application). Les pages sont rendues sans la coquille ; le catalogue et
 * le panier sont simulés.
 *
 * Commande RMK-261008-00042 du jeudi 08/10/2026, envoyée à 09:40 (08:40
 * UTC), modifiable jusqu'à 13:00 (12:00 UTC) : 40 Jus orange 1 L, 25 Jus
 * pomme 1 L, 10 Lben 1 L. Journée proposée : la même ; orange borné
 * (disponible 30), pomme sans borne, Lben absent du catalogue, fraise
 * épuisé.
 */

export const DAY = '2026-10-08'
export const USER_ID = 9
export const CLIENT_ID = 12
export const ORDER_ID = '01JA2Z6K8QW3X9M4T7RBN5CDEF'
export const ORDER_NUMBER = 'RMK-261008-00042'
export const ORDER_URL = `GET /api/v1/client/orders/${ORDER_ID}`
export const CANCEL_URL = `POST /api/v1/client/orders/${ORDER_ID}/cancel`
export const LIST_URL = 'GET /api/v1/client/orders'
export const TODAY_URL = 'GET /api/v1/client/today'

export const clientUser = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('client', {
    id: USER_ID,
    client_id: CLIENT_ID,
    name: 'Gérant C0012',
    permissions: ['order.view', 'order.create', 'limit.view'],
    ...overrides,
  })

export function makeLine(overrides: Partial<OrderLine> & Pick<OrderLine, 'product_id' | 'name'>): OrderLine {
  const requested = overrides.requested_qty ?? 10
  return {
    sku: `P00${overrides.product_id}`,
    short_name: overrides.name,
    sales_unit_label: 'Fardeau 6 × 1 L',
    photo: { thumb: `/media/p${overrides.product_id}-200.webp`, medium: `/media/p${overrides.product_id}-600.webp` },
    requested_qty: requested,
    validated_qty: null,
    shipped_qty: null,
    unit_price_ht: 900,
    vat_rate: 19,
    amount_ht: 900 * (overrides.validated_qty ?? requested),
    adjustment_reason_code: null,
    ...overrides,
  }
}

export const orangeLine = makeLine({ product_id: 42, name: 'Jus orange 1 L', requested_qty: 40 })
export const pommeLine = makeLine({ product_id: 57, name: 'Jus pomme 1 L', requested_qty: 25 })
export const lbenLine = makeLine({
  product_id: 61,
  name: 'Lben 1 L',
  sales_unit_label: 'Caisse 12 × 1 L',
  photo: null,
  requested_qty: 10,
  unit_price_ht: 130.5,
  vat_rate: 9,
  amount_ht: 1305,
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
    total_ht: 59805,
    total_ttc: 71026.95,
    lines_count: 3,
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
    total_vat: 11221.95,
    decided_at: null,
    decision_reason_code: null,
    decision_comment: null,
    modifiable_until: '2026-10-08T12:00:00+00:00',
    lines: [orangeLine, pommeLine, lbenLine],
    history: [{ from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null }],
    ...overrides,
  }
}

/** Commande close (décidée, annulée ou expirée) : plus d'action du client, sauf « Recommander ». */
export const closedOrder = (overrides: Partial<Order>) => makeOrder({ can_modify: false, can_cancel: false, modifiable_until: null, ...overrides })

export function makeProduct(overrides: Partial<ClientProduct> & Pick<ClientProduct, 'id' | 'name'>): ClientProduct {
  return {
    sku: `P00${overrides.id}`,
    short_name: overrides.name,
    family_id: 2,
    format: '1 L',
    sales_unit_label: 'Fardeau 6 × 1 L',
    units_per_sales_unit: 6,
    vat_rate: 19,
    price: { price_ht: 900, price_ttc: 1071, unit_price_ht: 150, valid_from: '2026-01-01' },
    photos: [],
    is_new: false,
    is_featured: false,
    limit: null,
    engaged: 0,
    available: null,
    sold_out: false,
    ...overrides,
  }
}

export const orange = makeProduct({ id: 42, name: 'Jus orange 1 L', limit: 50, engaged: 20, available: 30 })
export const pomme = makeProduct({ id: 57, name: 'Jus pomme 1 L' })
export const fraise = makeProduct({ id: 58, name: 'Jus fraise 1 L', limit: 20, engaged: 20, available: 0, sold_out: true })

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
    max_orders_per_day: 2,
    min_amount_ht: null,
    can_submit: true,
    submit_blocker: null,
    orders: [],
    families: [{ id: 2, code: 'JUS', name: 'Jus', sort_order: 1 }],
    products: [orange, pomme, fraise],
    ...overrides,
  }
}

/** Page de la liste des commandes. */
export function makePage(orders: OrderSummary[], meta: Partial<Paginated<OrderSummary>['meta']> = {}): Paginated<OrderSummary> {
  return {
    data: orders,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: orders.length, from: orders.length ? 1 : null, to: orders.length || null, ...meta },
  }
}

export type ApiHandler = Parameters<typeof mockApi>[0][string]

/** API simulée : compte client et routes des tests. */
export function ordersApi(routes: Record<string, ApiHandler>, user: CurrentUser = clientUser()) {
  const mock = mockApi({ 'GET /api/v1/auth/me': () => json(user), ...routes })
  const requests = (route: string) => mock.calls.filter((call) => `${call.method} ${call.path}` === route)
  /** Paramètres des lectures d'une route GET, dans l'ordre. */
  const queries = (path: string) =>
    mock.fetchMock.mock.calls
      .map(([input]) => new URL(String(input), 'http://localhost'))
      .filter((url) => url.pathname === path)
      .map((url) => Object.fromEntries(url.searchParams))
  return { ...mock, requests, queries }
}

/** « Mes commandes » sous `path` (avec un `state` transmis par la page précédente) ; catalogue et panier simulés. */
export function renderOrders(path: string, state?: unknown, user: CurrentUser = clientUser()) {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[{ pathname: path.split('?')[0]!, search: path.includes('?') ? `?${path.split('?')[1]}` : '', state }]}>
        <Routes>
          <Route path="/client/commandes" element={<OrdersPage />} />
          <Route path="/client/commandes/:id" element={<OrderDetailPage />} />
          <Route path="/client/catalogue" element={<p>Catalogue du jour</p>} />
          <Route path="/client/panier" element={<p>Panier du client</p>} />
        </Routes>
        <TestLocation />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...rendered, queryClient }
}

/** Panier partagé du compte de test (le même que celui des pages). */
export const testCart = () => cartStore(USER_ID, CLIENT_ID)

/** Panier tel qu'enregistré dans le navigateur. */
export function storedCart(): Cart | null {
  const raw = localStorage.getItem(cartKey(USER_ID, CLIENT_ID))
  return raw === null ? null : (JSON.parse(raw) as Cart)
}

/** Texte tel que lu par toHaveTextContent (espaces insécables normalisés). */
export const plain = (text: string) => text.replace(/[  ]/g, ' ')
