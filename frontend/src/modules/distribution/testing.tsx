import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi } from 'vitest'
import { createQueryClient } from '../../shared/api/queryClient'
import type {
  CurrentUser,
  DistributionGrid,
  DistributionGridClient,
  DistributionGridProduct,
  LimitCellInput,
  RegionalQuotaRegion,
  RegionalQuotas,
  SaveLimitsResult,
} from '../../shared/api/types'
import { meQueryKey } from '../../shared/auth/session'
import { json, makeUser, mockApi } from '../../test/utils'
import { draftStorageKey } from './drafts'
import { formatQuantity } from './model'
import RegionalDistributionPage from './RegionalDistributionPage'
import { TestLocation } from './TestLocation'

/**
 * Jeu de données et rendu des tests de la grille de répartition (non
 * chargé par l'application).
 *
 * Colonnes : 0 Orange 1 L (Q 500, S 120, V 300, R 200), 1 Pomme 1 L (Q 300,
 * épuisé), 2 Lben 1 L (famille Laitiers, Q 800) ; lignes : 0 C0012 Client A
 * (Grossiste), 1 C0047 Client B (bloqué, Détaillant), 2 C0051 Client C
 * (Détaillant). Répartition déjà envoyée (v1) : RG-B07 s'applique.
 */

export const DAY = '2026-10-08'
export const GRID_URL = `GET /api/v1/regional/days/${DAY}/grid`
export const REGIONS_URL = 'GET /api/v1/regional/quotas'
export const SAVE_URL = `PUT /api/v1/regional/days/${DAY}/limits`
export const COPY_URL = `POST /api/v1/regional/days/${DAY}/limits/copy-previous`
export const PUBLISH_URL = `POST /api/v1/regional/days/${DAY}/distribution/publish`

export const CENTRE = { id: 3, code: 'CENTRE', name: 'Centre' }
export const EST = { id: 4, code: 'EST', name: 'Est' }

const JUS = { id: 2, code: 'JUS', name: 'Jus' }
const LAIT = { id: 1, code: 'LAIT', name: 'Laitiers' }
const GROS = { id: 1, code: 'GROS', name: 'Grossiste' }
const DETAIL = { id: 2, code: 'DETAIL', name: 'Détaillant' }

export const regional = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('regional', { permissions: ['limit.view', 'limit.edit', 'limit.publish', 'quota.view'], ...overrides })

const product = (id: number, shortName: string, family: DistributionGridProduct['family'], quota: number, extra: Partial<DistributionGridProduct> = {}): DistributionGridProduct => ({
  id,
  sku: `P00${id}`,
  name: `Jus ${shortName} (désignation)`,
  short_name: shortName,
  family,
  sales_unit_label: 'Fardeau 6 × 1 L',
  quota,
  sum_limits: 0,
  pending: 0,
  validated: 0,
  remaining: quota,
  ...extra,
})

const client = (id: number, name: string, category: DistributionGridClient['category'], status: DistributionGridClient['status'] = 'ACTIVE'): DistributionGridClient => ({
  id,
  code: `C00${id}`,
  name,
  status,
  category,
})

export function makeGrid(overrides: Partial<DistributionGrid> = {}): DistributionGrid {
  return {
    day: { day: DAY, status: 'OPEN', delivery_date: '2026-10-11', cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' } },
    region: {
      ...CENTRE,
      status: 'DISTRIBUTION_PUBLISHED',
      status_label: 'Répartition publiée',
      quota_version: 2,
      distribution_version: 1,
      distribution_published_at: '2026-10-08T08:40:00+00:00',
      cutoffs: { distribution: '11:15', ordering: '13:00' },
      extended: { distribution: true, ordering: false },
    },
    policy: 'FREE',
    can_edit: true,
    can_publish: true,
    products: [
      product(42, 'Orange 1 L', JUS, 500, { sum_limits: 560, pending: 120, validated: 300, remaining: 200 }),
      product(43, 'Pomme 1 L', JUS, 300, { sum_limits: 20, validated: 300, remaining: 0 }),
      product(57, 'Lben 1 L', LAIT, 800, { sum_limits: 85, validated: 80, remaining: 720 }),
    ],
    clients: [client(12, 'Client A', GROS), client(47, 'Client B', DETAIL, 'BLOCKED'), client(51, 'Client C', DETAIL)],
    cells: {
      '12': {
        '42': { limit: 50, draft: { value: 60 }, pending: 10, validated: 30 },
        '43': { limit: 20, draft: null, pending: 0, validated: 20 },
        '57': { limit: 5, draft: null, pending: 10, validated: 0 },
      },
      '47': {
        '42': { limit: 100, draft: null, pending: 80, validated: 0 },
        '57': { limit: 80, draft: null, pending: 0, validated: 80 },
      },
      '51': { '42': { limit: 400, draft: null, pending: 0, validated: 0 } },
    },
    publication: { first: false, drafts: 1, notified_clients: 1, overallocated: [{ product_id: 42, sku: 'P0042', quota: 500, sum_limits: 560 }] },
    ...overrides,
  }
}

const regionEntry = (region: typeof CENTRE, extra: Partial<RegionalQuotaRegion> = {}): RegionalQuotaRegion => ({
  region,
  status: 'DISTRIBUTION_PUBLISHED',
  quota_version: 2,
  quota_last_published_at: '2026-10-08T07:10:00+00:00',
  distribution_version: 1,
  cutoffs: { distribution: '10:00', ordering: '13:00' },
  quotas: [],
  ...extra,
})

export const centreEntry = (extra: Partial<RegionalQuotaRegion> = {}) => regionEntry(CENTRE, extra)
export const estEntry = (extra: Partial<RegionalQuotaRegion> = {}) => regionEntry(EST, { status: 'QUOTA_PUBLISHED', distribution_version: 0, ...extra })

export const opened = (day: string, regions: RegionalQuotaRegion[] = [centreEntry()]): RegionalQuotas => ({
  day,
  opened: true,
  status: 'OPEN',
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
  regions,
})

export const notOpened = (day: string): RegionalQuotas => ({ day, opened: false, status: null, cutoffs: null, regions: [] })

/** Réponse du PUT : les cellules envoyées passent en brouillon (borne publiée de la grille d'origine). */
export function savedCells(body: unknown, grid: DistributionGrid = makeGrid()): SaveLimitsResult {
  const { cells } = body as { cells: LimitCellInput[] }
  return {
    changed: cells.length,
    status: 'DISTRIBUTION_PUBLISHED',
    cells: cells.map(({ client_id, product_id, limit }) => {
      const current = grid.cells[String(client_id)]?.[String(product_id)]
      const published = current?.limit ?? null
      return {
        client_id,
        product_id,
        limit: published,
        draft: limit === published ? null : { value: limit },
        pending: current?.pending ?? 0,
        validated: current?.validated ?? 0,
      }
    }),
  }
}

type Routes = Parameters<typeof mockApi>[0]

/** Saisies non enregistrées gardées dans le navigateur pour la journée DAY et la région Centre ; null : aucune. */
export const storedDrafts = (userId = 1): LimitCellInput[] | null => JSON.parse(localStorage.getItem(draftStorageKey(userId, DAY, CENTRE.id)) ?? 'null')

/**
 * Page du régional seule, utilisateur connu ; `routes` complète ou remplace
 * les réponses simulées. Le stockage du navigateur part vide, sauf les
 * saisies non enregistrées `stored` d'une visite précédente (journée DAY,
 * région Centre).
 */
export function renderDistribution({
  path = `/regional/repartition?day=${DAY}`,
  routes = {},
  user = regional(),
  stored = [],
}: { path?: string; routes?: Routes; user?: CurrentUser; stored?: LimitCellInput[] } = {}) {
  localStorage.clear()
  if (stored.length > 0) localStorage.setItem(draftStorageKey(user.id, DAY, CENTRE.id), JSON.stringify(stored))
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    [REGIONS_URL]: ({ url }) => json(opened(url.searchParams.get('day') ?? DAY)),
    [GRID_URL]: () => json(makeGrid()),
    ...routes,
  })
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/regional/repartition" element={<RegionalDistributionPage />} />
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

/** Écran de téléphone (sous 768 px) : matchMedia simulé, réactif. */
export function mockScreen(wide: boolean) {
  const listeners = new Set<() => void>()
  const state = { wide }
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      get matches() {
        return query === '(min-width: 768px)' ? state.wide : false
      },
      media: query,
      addEventListener: (_: string, listener: () => void) => listeners.add(listener),
      removeEventListener: (_: string, listener: () => void) => listeners.delete(listener),
    })),
  )
  return {
    resize(next: boolean) {
      state.wide = next
      listeners.forEach((listener) => listener())
    },
  }
}

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/** Cellule d'un client (code) et d'un produit (nom court) de la grille PC. */
export const cellOf = (code: string, productName: string) => screen.getByRole('gridcell', { name: new RegExp(`^${escape(code)} [^—]+ — ${escape(productName)} :`) })

/** Cellule de synthèse (« Quota (Q) », « Σ bornes (ΣB) »…) d'un produit. */
export const summaryOf = (row: string, productName: string) => screen.getByRole('gridcell', { name: new RegExp(`^${escape(row)} — ${escape(productName)} :`) })

export const findGrid = () => screen.findByRole('grid', { name: 'Répartition de la région Centre du 08/10/2026, par client et par produit' })

/** Quantité telle que lue par toHaveTextContent (espaces normalisés). */
export const text = (value: number) => formatQuantity(value).replace(/ /g, ' ')
