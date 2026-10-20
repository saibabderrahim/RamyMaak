import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import '../../../shared/i18n/orders'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { ClientWithoutOrder, CurrentUser, RegionalDashboard, RegionalDashboardRegion, RegionRef } from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, makeUser, mockApi } from '../../../test/utils'
import { TestLocation } from '../../distribution/TestLocation'
import MyDayPage from './MyDayPage'

/**
 * Jeu de données et rendu des tests de « Ma journée » (non chargé par
 * l'application). Journée DAY, région Centre : répartition envoyée à
 * 09:30, 4 commandes à valider dont la plus ancienne envoyée à 09:40.
 */

export const DAY = '2026-10-08'
export const CENTRE: RegionRef = { id: 3, code: 'CENTRE', name: 'Centre' }
export const EST: RegionRef = { id: 4, code: 'EST', name: 'Est' }
export const DASHBOARD_URL = 'GET /api/v1/regional/dashboard'

export const regional = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('regional', { id: 7, permissions: ['order.view', 'order.validate', 'quota.view', 'dashboard.view'], ...overrides })

export function region(overrides: Partial<RegionalDashboardRegion> = {}): RegionalDashboardRegion {
  return {
    region: CENTRE,
    status: 'DISTRIBUTION_PUBLISHED',
    status_label: 'Répartition publiée',
    quota_published_at: '2026-10-08T07:10:00+00:00',
    distribution_published_at: '2026-10-08T08:30:00+00:00',
    cutoffs: { distribution: '10:00', ordering: '13:30', validation: '15:00' },
    extended: { distribution: false, ordering: true, validation: false },
    orders: { SUBMITTED: 4, VALIDATED: 12, REJECTED: 1, CLOSED: 0 },
    oldest_submitted_at: '2026-10-08T08:40:00+00:00',
    clients: { notified: 40, ordered: 17, without_order: 23 },
    ...overrides,
  }
}

export function client(overrides: Partial<ClientWithoutOrder> = {}): ClientWithoutOrder {
  return { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE', region_id: 3, phone: '0550 12 34 56', phone2: null, ...overrides }
}

export function dashboard(overrides: Partial<RegionalDashboard> = {}): RegionalDashboard {
  return {
    day: DAY,
    opened: true,
    status: 'OPEN',
    cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
    validation_blocking: false,
    regions: [region()],
    clients_without_order: [],
    ...overrides,
  }
}

export const notOpened = (day: string): RegionalDashboard => ({
  day,
  opened: false,
  status: null,
  cutoffs: null,
  validation_blocking: false,
  regions: [],
  clients_without_order: [],
})

type Routes = Parameters<typeof mockApi>[0]

/** « Ma journée » seule, utilisateur connu ; `routes` complète ou remplace les réponses simulées. */
export function renderMyDay({
  path = `/regional?day=${DAY}`,
  routes = {},
  user = regional(),
}: { path?: string; routes?: Routes; user?: CurrentUser } = {}) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    [DASHBOARD_URL]: ({ url }) => json(dashboard({ day: url.searchParams.get('day') ?? DAY })),
    ...routes,
  })
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/regional" element={<MyDayPage />} />
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
