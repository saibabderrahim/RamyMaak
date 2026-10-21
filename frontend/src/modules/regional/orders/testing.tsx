import { type QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi } from 'vitest'
import '../../../shared/i18n/orders'
import { createQueryClient } from '../../../shared/api/queryClient'
import type {
  CurrentUser,
  OrderLine,
  ReasonOption,
  RegionalOrder,
  RegionalOrderLineContext,
  RegionalOrdersPage as OrdersPage,
  RegionalOrderSummary,
  RegionalQuotaRegion,
  RegionalQuotas,
  RegionRef,
} from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, makeUser, mockApi } from '../../../test/utils'
import { TestLocation } from '../../distribution/TestLocation'
import type { OrderCounts } from './model'
import RegionalOrderPage from './RegionalOrderPage'
import RegionalOrdersPage from './RegionalOrdersPage'

/**
 * Jeu de données et rendu des tests des commandes reçues (non chargé par
 * l'application). Journée DAY, régions Centre et Est ; commandes envoyées à
 * 09:40 et 10:05 (heure d'Alger) par Superette El Baraka et Grossiste
 * Benali (bloqué).
 */

export const DAY = '2026-10-08'
export const ORDERS_URL = 'GET /api/v1/regional/orders'
export const REGIONS_URL = 'GET /api/v1/regional/quotas'
export const ORDER_ID = '01JA2Z6K8QW3X9M4T7RBN5CDEF'
export const orderUrl = (id: string) => `GET /api/v1/regional/orders/${id}`
export const validateUrl = (id: string) => `POST /api/v1/regional/orders/${id}/validate`
export const rejectUrl = (id: string) => `POST /api/v1/regional/orders/${id}/reject`
export const REASONS_URL = 'GET /api/v1/reason-codes'

export const CENTRE: RegionRef = { id: 3, code: 'CENTRE', name: 'Centre' }
export const EST: RegionRef = { id: 4, code: 'EST', name: 'Est' }

export const regional = (overrides: Partial<CurrentUser> = {}) => makeUser('regional', { id: 7, permissions: ['order.view', 'order.validate', 'quota.view', 'dashboard.view'], ...overrides })

const reason = (type: ReasonOption['type'], code: string, label: string, requiresComment = false, sortOrder = 10): ReasonOption => ({
  type,
  code,
  label,
  requires_comment: requiresComment,
  sort_order: sortOrder,
})

/** Motifs actifs d'ajustement et de rejet (valeurs par défaut de la migration, AUTRE exige un commentaire). */
export const REASONS: ReasonOption[] = [
  reason('ADJUSTMENT', 'QUOTA_INSUFFISANT', 'Quota insuffisant', false, 10),
  reason('ADJUSTMENT', 'BORNE_ATTEINTE', 'Borne atteinte', false, 20),
  reason('ADJUSTMENT', 'AUTRE', 'Autre', true, 40),
  reason('REJECTION', 'QUOTA_EPUISE', 'Quota épuisé', false, 10),
  reason('REJECTION', 'DOUBLON', 'Doublon', false, 30),
  reason('REJECTION', 'AUTRE', 'Autre', true, 50),
]

/** `GET /reason-codes?type=A,B` : les motifs actifs des types demandés. */
export const reasonsRoute = ({ url }: { url: URL }) => {
  const types = (url.searchParams.get('type') ?? '').split(',')
  return json({ data: REASONS.filter((entry) => types.includes(entry.type)) })
}

const regionEntry = (region: RegionRef): RegionalQuotaRegion => ({
  region,
  status: 'DISTRIBUTION_PUBLISHED',
  quota_version: 1,
  quota_last_published_at: '2026-10-08T07:10:00+00:00',
  distribution_version: 1,
  cutoffs: { distribution: '10:00', ordering: '13:00' },
  quotas: [],
})

export const opened = (day: string, regions: RegionRef[] = [CENTRE]): RegionalQuotas => ({
  day,
  opened: true,
  status: 'OPEN',
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
  regions: regions.map(regionEntry),
})

export const notOpened = (day: string): RegionalQuotas => ({ day, opened: false, status: null, cutoffs: null, regions: [] })

export function summary(overrides: Partial<RegionalOrderSummary> = {}): RegionalOrderSummary {
  return {
    id: ORDER_ID,
    number: 'RMK-261008-00042',
    day: DAY,
    status: 'SUBMITTED',
    status_label: 'Envoyée',
    version: 1,
    submitted_at: '2026-10-08T08:40:00+00:00',
    updated_at: '2026-10-08T08:40:00+00:00',
    client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE' },
    region: CENTRE,
    lines_count: 2,
    total_ht: 58500,
    is_adjusted: false,
    decided_at: null,
    decided_by: null,
    ...overrides,
  }
}

export const SECOND = summary({
  id: '01JA2Z6K8QW3X9M4T7RBN5CDEG',
  number: 'RMK-261008-00043',
  submitted_at: '2026-10-08T09:05:00+00:00',
  client: { id: 47, code: 'C0047', name: 'Grossiste Benali', status: 'BLOCKED' },
  lines_count: 1,
  total_ht: 1250.5,
})

export const COUNTS: OrderCounts = { SUBMITTED: 2, VALIDATED: 0, REJECTED: 0, CLOSED: 1 }

export function ordersPage(
  data: RegionalOrderSummary[],
  { counts = COUNTS, page = 1, lastPage = 1, total = data.length }: { counts?: OrderCounts; page?: number; lastPage?: number; total?: number } = {},
): OrdersPage {
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

const context = (extra: Partial<RegionalOrderLineContext> = {}): RegionalOrderLineContext => ({
  limit: 50,
  client_validated: 0,
  region_quota: 1500,
  region_validated: 0,
  region_remaining: 1500,
  max_validatable: 40,
  ...extra,
})

const line = (extra: Partial<OrderLine>, lineContext: RegionalOrderLineContext): OrderLine & { context: RegionalOrderLineContext } => ({
  product_id: 42,
  sku: 'P0042',
  name: 'Jus orange 1 L',
  short_name: 'Orange 1 L',
  sales_unit_label: 'Fardeau 6 × 1 L',
  photo: { thumb: '/media/42/thumb.webp', medium: '/media/42/medium.webp' },
  requested_qty: 40,
  validated_qty: null,
  shipped_qty: null,
  unit_price_ht: 900,
  vat_rate: 19,
  amount_ht: 36000,
  adjustment_reason_code: null,
  adjustment_reason: null,
  adjustment_comment: null,
  ...extra,
  context: lineContext,
})

export function makeOrder(overrides: Partial<RegionalOrder> = {}): RegionalOrder {
  return {
    id: ORDER_ID,
    number: 'RMK-261008-00042',
    day: DAY,
    status: 'SUBMITTED',
    status_label: 'Envoyée',
    version: 2,
    submitted_at: '2026-10-08T08:40:00+00:00',
    total_ht: 58500,
    total_ttc: 69615,
    lines_count: 2,
    is_adjusted: false,
    submitted_by: { id: 31, name: 'Gérant C0012' },
    comment: 'Livraison avant 10 h',
    total_vat: 11115,
    decided_at: null,
    decided_by: null,
    decision_reason_code: null,
    decision_reason: null,
    decision_comment: null,
    client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE', phone: '0550 12 34 56', phone2: '0770112233' },
    region: CENTRE,
    lines: [
      line({}, context()),
      line(
        {
          product_id: 57,
          sku: 'P0057',
          name: 'Lben 1 L',
          short_name: 'Lben 1 L',
          sales_unit_label: 'Caisse 12 × 1 L',
          photo: null,
          requested_qty: 1250,
          unit_price_ht: 18,
          amount_ht: 22500,
        },
        context({ limit: null, client_validated: 30, region_quota: 800, region_validated: 80, region_remaining: 720, max_validatable: 720 }),
      ),
    ],
    history: [
      { from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null },
      { from: 'SUBMITTED', to: 'SUBMITTED', version: 2, at: '2026-10-08T09:12:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null },
    ],
    validation: { cutoff: '15:00', cutoff_at: '2026-10-08T14:00:00+00:00', extended: false, blocking: false, passed: false },
    can_validate: false,
    can_reject: false,
    blocked_reason: null,
    ...overrides,
  }
}

/** Commande envoyée que le régional peut valider et rejeter. */
export const decidable = (overrides: Partial<RegionalOrder> = {}) => makeOrder({ can_validate: true, can_reject: true, ...overrides })

type Routes = Parameters<typeof mockApi>[0]

/**
 * Pages des commandes reçues (liste et détail), utilisateur connu ; `routes`
 * complète ou remplace les réponses simulées (par défaut : une région, deux
 * commandes à valider, le détail de la première).
 */
export function renderOrders({
  path = `/regional/commandes?day=${DAY}`,
  routes = {},
  user = regional(),
  queryClient = createQueryClient(),
}: { path?: string; routes?: Routes; user?: CurrentUser; queryClient?: QueryClient } = {}) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    [REGIONS_URL]: ({ url }) => json(opened(url.searchParams.get('day') ?? DAY)),
    [ORDERS_URL]: () => json(ordersPage([summary(), SECOND])),
    [orderUrl(ORDER_ID)]: () => json(makeOrder()),
    [REASONS_URL]: reasonsRoute,
    ...routes,
  })
  queryClient.setQueryData(meQueryKey, user)
  // jsdom ne défile pas : l'écran d'une commande se replace en haut à l'ouverture.
  vi.stubGlobal('scrollTo', vi.fn())
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/regional/commandes" element={<RegionalOrdersPage />} />
          <Route path="/regional/commandes/:id" element={<RegionalOrderPage />} />
          <Route path="*" element={null} />
        </Routes>
        <TestLocation />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  const requests = (route: string) => mock.calls.filter((call) => `${call.method} ${call.path}` === route)
  /** Paramètres de requête des appels à une route GET. */
  const queries = (route: string) =>
    mock.fetchMock.mock.calls
      .map(([input, init]) => ({ url: new URL(String(input), 'http://localhost'), method: (init?.method ?? 'GET').toUpperCase() }))
      .filter(({ url, method }) => `${method} ${url.pathname}` === route)
      .map(({ url }) => Object.fromEntries(url.searchParams))
  return { ...mock, ...rendered, queryClient, requests, queries }
}
