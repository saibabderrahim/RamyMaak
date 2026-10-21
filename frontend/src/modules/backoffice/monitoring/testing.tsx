import { type QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import '../i18n'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { CurrentUser, DayMonitoring, DayMonitoringRegion, MonitoringOrderCounts } from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, mockApi } from '../../../test/utils'
import { TestLocation } from '../../distribution/TestLocation'
import { admin, adv } from '../orders/testing'
import DayMonitoringPage from './DayMonitoringPage'

/**
 * Jeu de données et rendu des tests du suivi de la journée (non chargé par
 * l'application). Journée DAY ouverte, régions Centre (dépôt de Blida, retards)
 * et Est ; produits Orange 1 L (épuisé au Centre) et Lben 1 L.
 */

export { admin, adv }

export const DAY = '2026-10-08'
export const MONITORING_URL = `GET /api/v1/adv/days/${DAY}/monitoring`
export const PICKING_URL = `GET /api/v1/adv/days/${DAY}/picking-list`
export const EXPORT_URL = `GET /api/v1/adv/days/${DAY}/orders/export`

const counts = (overrides: Partial<MonitoringOrderCounts> = {}): MonitoringOrderCounts => ({
  SUBMITTED: 0,
  VALIDATED: 0,
  IN_PREPARATION: 0,
  PREPARED: 0,
  SHIPPED: 0,
  REJECTED: 0,
  CANCELLED: 0,
  EXPIRED: 0,
  ...overrides,
})

export const CENTRE: DayMonitoringRegion = {
  region: { id: 3, code: 'CENTRE', name: 'Centre', depot: { id: 1, code: 'BLIDA', name: 'Dépôt de Blida' } },
  status: 'DISTRIBUTION_PUBLISHED',
  status_label: 'Répartition publiée',
  quota_published_at: '2026-10-08T06:55:00+00:00',
  distribution_published_at: '2026-10-08T08:40:00+00:00',
  cutoffs: { distribution: '10:00', ordering: '13:30', validation: '15:00' },
  clients: { notified: 40, ordered: 17 },
  orders: counts({ SUBMITTED: 4, VALIDATED: 8, IN_PREPARATION: 2, SHIPPED: 1, REJECTED: 1 }),
  last_validated_at: '2026-10-08T13:20:00+00:00',
  alerts: ['VALIDATION_LATE'],
}

export const EST: DayMonitoringRegion = {
  region: { id: 4, code: 'EST', name: 'Est', depot: null },
  status: 'QUOTA_PUBLISHED',
  status_label: 'Quotas publiés',
  quota_published_at: '2026-10-08T06:55:00+00:00',
  distribution_published_at: null,
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00' },
  clients: { notified: 0, ordered: 0 },
  orders: counts(),
  last_validated_at: null,
  alerts: ['DISTRIBUTION_LATE'],
}

export function makeMonitoring(overrides: Partial<DayMonitoring> = {}): DayMonitoring {
  return {
    day: DAY,
    status: 'OPEN',
    cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
    generated_at: '2026-10-08T13:30:00+00:00',
    totals: { orders: counts({ SUBMITTED: 4, VALIDATED: 8, IN_PREPARATION: 2, SHIPPED: 1, REJECTED: 1 }), clients: { notified: 40, ordered: 17 } },
    regions: [CENTRE, EST],
    products: [
      {
        product_id: 42,
        sku: 'P0042',
        short_name: 'Orange 1 L',
        family: { id: 1, name: 'Jus' },
        quota: 300,
        validated: 300,
        pending: 25,
        remaining: 0,
        sold_out_regions: [3],
      },
      {
        product_id: 57,
        sku: 'P0057',
        short_name: 'Lben 1 L',
        family: { id: 2, name: 'Produits laitiers' },
        quota: 1000,
        validated: 400,
        pending: 0,
        remaining: 600,
        sold_out_regions: [],
      },
    ],
    cells: {
      '3': { '42': { quota: 300, validated: 300, pending: 25 }, '57': { quota: 600, validated: 400, pending: 0 } },
      '4': { '57': { quota: 400, validated: 0, pending: 0 } },
    },
    ...overrides,
  }
}

type Routes = Parameters<typeof mockApi>[0]

/** Suivi de la journée DAY ; la réponse est sans enveloppe `data` (I-96). */
export function renderMonitoring({
  path = `{base}/journees/${DAY}/suivi`,
  routes = {},
  user = adv(),
  queryClient = createQueryClient(),
}: { path?: string; routes?: Routes; user?: CurrentUser; queryClient?: QueryClient } = {}) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    [MONITORING_URL]: () => json(makeMonitoring()),
    ...routes,
  })
  queryClient.setQueryData(meQueryKey, user)
  const base = user.role === 'admin' ? '/admin' : '/adv'
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path.replace('{base}', base)]}>
        <Routes>
          <Route path={`${base}/journees/:day/suivi`} element={<DayMonitoringPage />} />
          <Route path="*" element={null} />
        </Routes>
        <TestLocation />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...mock, ...rendered, queryClient }
}
