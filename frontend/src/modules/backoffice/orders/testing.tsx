import { type QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import '../i18n'
import { createQueryClient } from '../../../shared/api/queryClient'
import type {
  AdvOrder,
  AdvOrderCounts,
  AdvOrderLine,
  AdvOrdersPage,
  AdvOrderSummary,
  CurrentUser,
  ReasonOption,
  Region,
} from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, makeUser, mockApi } from '../../../test/utils'
import { TestLocation } from '../../distribution/TestLocation'
import OrderPage from './OrderPage'
import OrdersQueuePage from './OrdersQueuePage'

/**
 * Jeu de données et rendu des tests de la file ADV et du détail d'une commande
 * (non chargé par l'application). Journée DAY, régions Centre (dépôt Blida) et
 * Est (sans dépôt) ; commandes validées par Karim Régional à 10:15 et 10:40
 * (heure d'Alger).
 */

export const DAY = '2026-10-08'
export const ORDERS_URL = 'GET /api/v1/adv/orders'
export const BULK_URL = 'POST /api/v1/adv/orders/bulk-status'
export const REGIONS_URL = 'GET /api/v1/regions'
export const REASONS_URL = 'GET /api/v1/reason-codes'
export const ID1 = '01JA2Z6K8QW3X9M4T7RBN5CDEF'
export const ID2 = '01JA2Z6K8QW3X9M4T7RBN5CDEG'
export const ID3 = '01JA2Z6K8QW3X9M4T7RBN5CDEH'
export const orderUrl = (id: string) => `GET /api/v1/adv/orders/${id}`
export const shippingUrl = (id: string) => `PATCH /api/v1/adv/orders/${id}/shipping`
export const cancelUrl = (id: string) => `POST /api/v1/adv/orders/${id}/cancel`
export const pdfUrl = (id: string) => `GET /api/v1/adv/orders/${id}/pdf`

export const CENTRE = { id: 3, code: 'CENTRE', name: 'Centre' }
export const EST = { id: 4, code: 'EST', name: 'Est' }
export const BLIDA = { id: 1, code: 'BLIDA', name: 'Dépôt de Blida' }

const ADV_PERMISSIONS = ['order.view', 'order.process', 'order.cancel-validated', 'dashboard.view', 'region.view', 'product.view', 'limit.view', 'quota.view']

export const adv = (overrides: Partial<CurrentUser> = {}) => makeUser('adv', { id: 4, permissions: ADV_PERMISSIONS, ...overrides })

/** L'Admin lit la file, le détail et le suivi, sans rien traiter (I-87). */
export const admin = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('admin', { id: 1, permissions: ['order.view', 'dashboard.view', 'region.view', 'product.view', 'limit.view', 'quota.view'], ...overrides })

const reason = (type: ReasonOption['type'], code: string, label: string, requiresComment = false, sortOrder = 10): ReasonOption => ({
  type,
  code,
  label,
  requires_comment: requiresComment,
  sort_order: sortOrder,
})

/** Motifs actifs d'écart d'expédition et d'annulation (valeurs par défaut de la migration, AUTRE exige un commentaire sauf pour un écart, I-95). */
export const REASONS: ReasonOption[] = [
  reason('SHIPPING_GAP', 'RUPTURE', 'Rupture de stock', false, 10),
  reason('SHIPPING_GAP', 'CASSE', 'Casse', false, 20),
  reason('SHIPPING_GAP', 'AUTRE', 'Autre', false, 40),
  reason('CANCELLATION', 'DEMANDE_CLIENT', 'Demande du client', false, 10),
  reason('CANCELLATION', 'RUPTURE', 'Rupture de stock', false, 20),
  reason('CANCELLATION', 'AUTRE', 'Autre', true, 40),
]

/** `GET /reason-codes?type=A,B` : les motifs actifs des types demandés. */
export const reasonsRoute = ({ url }: { url: URL }) => {
  const types = (url.searchParams.get('type') ?? '').split(',')
  return json({ data: REASONS.filter((entry) => types.includes(entry.type)) })
}

export function summary(overrides: Partial<AdvOrderSummary> = {}): AdvOrderSummary {
  return {
    id: ID1,
    number: 'RMK-261008-00042',
    day: DAY,
    status: 'VALIDATED',
    status_label: 'Validée',
    version: 3,
    submitted_at: '2026-10-08T08:40:00+00:00',
    decided_at: '2026-10-08T09:15:00+00:00',
    decided_by: { id: 9, name: 'Karim Régional' },
    prep_started_at: null,
    prepared_at: null,
    shipped_at: null,
    delivery_note_ref: null,
    client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE' },
    region: CENTRE,
    depot: BLIDA,
    lines_count: 2,
    total_ht: 58500,
    total_ttc: 69615,
    is_adjusted: false,
    has_shipping_gap: false,
    next_statuses: ['IN_PREPARATION'],
    can_cancel: true,
    ...overrides,
  }
}

export const SECOND = summary({
  id: ID2,
  number: 'RMK-261008-00043',
  version: 2,
  decided_at: '2026-10-08T09:40:00+00:00',
  client: { id: 47, code: 'C0047', name: 'Grossiste Benali', status: 'BLOCKED' },
  region: EST,
  depot: null,
  lines_count: 1,
  total_ht: 1250.5,
  total_ttc: 1488.1,
  is_adjusted: true,
})

export const THIRD = summary({
  id: ID3,
  number: 'RMK-261007-00031',
  day: '2026-10-07',
  version: 5,
  decided_at: '2026-10-07T10:05:00+00:00',
  client: { id: 51, code: 'C0051', name: 'Épicerie Larbi', status: 'ACTIVE' },
})

export const COUNTS: AdvOrderCounts = { VALIDATED: 3, IN_PREPARATION: 1, PREPARED: 0, SHIPPED: 2, CANCELLED: 0 }

export function queuePage(
  data: AdvOrderSummary[],
  { counts = COUNTS, page = 1, lastPage = 1, total = data.length }: { counts?: AdvOrderCounts; page?: number; lastPage?: number; total?: number } = {},
): AdvOrdersPage {
  return {
    data,
    meta: {
      current_page: page,
      last_page: lastPage,
      per_page: 50,
      total,
      from: data.length > 0 ? 1 : null,
      to: data.length > 0 ? data.length : null,
      counts,
    },
  }
}

export const region = (item: { id: number; code: string; name: string }, depot: Region['depot']): Region => ({
  ...item,
  is_active: true,
  depot,
  titular: null,
  deputies: [],
  clients_count: 20,
})

/** `GET /regions` : Centre (dépôt de Blida) et Est (sans dépôt). */
export const regionsRoute = () =>
  json({
    data: [region(CENTRE, BLIDA), region(EST, null)],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 2, from: 1, to: 2 },
  })

const line = (extra: Partial<AdvOrderLine>): AdvOrderLine => ({
  product_id: 42,
  sku: 'P0042',
  name: 'Jus orange 1 L',
  short_name: 'Orange 1 L',
  sales_unit_label: 'Fardeau 6 × 1 L',
  photo: null,
  requested_qty: 40,
  validated_qty: 40,
  shipped_qty: null,
  unit_price_ht: 900,
  vat_rate: 19,
  amount_ht: 36000,
  adjustment_reason_code: null,
  adjustment_reason: null,
  adjustment_comment: null,
  shipping_gap_reason: null,
  ...extra,
})

export const orange = (extra: Partial<AdvOrderLine> = {}) => line(extra)

export const lben = (extra: Partial<AdvOrderLine> = {}) =>
  line({
    product_id: 57,
    sku: 'P0057',
    name: 'Lben 1 L',
    short_name: 'Lben 1 L',
    sales_unit_label: 'Caisse 12 × 1 L',
    requested_qty: 1250,
    validated_qty: 1000,
    unit_price_ht: 18,
    amount_ht: 18000,
    adjustment_reason_code: 'QUOTA_INSUFFISANT',
    adjustment_reason: { code: 'QUOTA_INSUFFISANT', label: 'Quota insuffisant' },
    adjustment_comment: 'Reste région limité',
    ...extra,
  })

export function makeOrder(overrides: Partial<AdvOrder> = {}): AdvOrder {
  return {
    ...summary(),
    client: {
      id: 12,
      code: 'C0012',
      name: 'Superette El Baraka',
      status: 'ACTIVE',
      address: '12 rue des Frères Bouchama',
      wilaya: 'Alger',
      commune: 'Bab Ezzouar',
      phone: '0550 12 34 56',
      phone2: '0770112233',
      nif: '000216001234567',
      rc: '16/00-1234567B21',
    },
    is_adjusted: true,
    comment: 'Livraison avant 10 h',
    total_vat: 11115,
    decision_reason: null,
    decision_comment: null,
    lines: [orange(), lben()],
    history: [
      { from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null },
      { from: 'SUBMITTED', to: 'VALIDATED', version: 2, at: '2026-10-08T09:15:00+00:00', actor: 'REGIONAL', by_name: 'Karim Régional', comment: null },
    ],
    can: { next_statuses: ['IN_PREPARATION'], edit_shipping_lines: false, edit_delivery_note: false, cancel: true, print: true },
    ...overrides,
  }
}

/** Commande en préparation : quantités expédiées saisissables, étape suivante « Préparée ». */
export const inPreparation = (overrides: Partial<AdvOrder> = {}) =>
  makeOrder({
    status: 'IN_PREPARATION',
    status_label: 'En préparation',
    version: 4,
    prep_started_at: '2026-10-08T10:20:00+00:00',
    next_statuses: ['PREPARED'],
    lines: [orange(), lben()],
    can: { next_statuses: ['PREPARED'], edit_shipping_lines: true, edit_delivery_note: true, cancel: true, print: true },
    ...overrides,
  })

/** Commande préparée : quantités expédiées = validées, étape suivante « Expédiée », plus d'annulation. */
export const prepared = (overrides: Partial<AdvOrder> = {}) =>
  makeOrder({
    status: 'PREPARED',
    status_label: 'Préparée',
    version: 5,
    prepared_at: '2026-10-08T11:00:00+00:00',
    lines: [orange({ shipped_qty: 40 }), lben({ shipped_qty: 1000 })],
    can_cancel: false,
    can: { next_statuses: ['SHIPPED'], edit_shipping_lines: true, edit_delivery_note: true, cancel: false, print: true },
    ...overrides,
  })

/** Commande expédiée : seul le bon de livraison reste modifiable. */
export const shipped = (overrides: Partial<AdvOrder> = {}) =>
  makeOrder({
    status: 'SHIPPED',
    status_label: 'Expédiée',
    version: 7,
    shipped_at: '2026-10-08T13:00:00+00:00',
    delivery_note_ref: 'BL-0042',
    lines: [orange({ shipped_qty: 40 }), lben({ shipped_qty: 900, shipping_gap_reason: { code: 'RUPTURE', label: 'Rupture de stock' } })],
    has_shipping_gap: true,
    can: { next_statuses: [], edit_shipping_lines: false, edit_delivery_note: true, cancel: false, print: true },
    ...overrides,
  })

type Routes = Parameters<typeof mockApi>[0]

type Options = { path?: string; routes?: Routes; user?: CurrentUser; queryClient?: QueryClient }

function renderScreens({ path, routes = {}, user = adv(), queryClient = createQueryClient() }: Required<Pick<Options, 'path'>> & Options, defaults: Routes) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    [REGIONS_URL]: regionsRoute,
    [REASONS_URL]: reasonsRoute,
    ...defaults,
    ...routes,
  })
  queryClient.setQueryData(meQueryKey, user)
  const base = user.role === 'admin' ? '/admin' : '/adv'
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path.replace('{base}', base)]}>
        <Routes>
          <Route path={`${base}/commandes`} element={<OrdersQueuePage />} />
          <Route path={`${base}/commandes/:id`} element={<OrderPage />} />
          <Route path="*" element={null} />
        </Routes>
        <TestLocation />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...mock, ...rendered, queryClient }
}

/** File des commandes : trois commandes à préparer par défaut ; `routes` complète ou remplace les réponses simulées. */
export function renderQueue(options: Options = {}) {
  return renderScreens(
    { path: '{base}/commandes', ...options },
    { [ORDERS_URL]: () => json(queuePage([summary(), SECOND, THIRD])) },
  )
}

/** Détail d'une commande : `makeOrder()` par défaut (validée, étape suivante « En préparation »). */
export function renderOrder(options: Options & { order?: AdvOrder } = {}) {
  const { order = makeOrder(), ...rest } = options
  return renderScreens({ path: `{base}/commandes/${order.id}`, ...rest }, { [orderUrl(order.id)]: () => json(order) })
}
