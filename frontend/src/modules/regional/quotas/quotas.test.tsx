import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CurrentUser, NotificationsPage, RegionalQuotaRegion, RegionalQuotas } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { disconnectRealtime, NOTIFICATION_EVENT } from '../../../shared/realtime'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { formatQuantity, groupByFamily, isDayKey, nextDay, weekdayOf } from './format'

// Temps réel simulé (même principe que shared/realtime/realtime.test.tsx).
const fake = vi.hoisted(() => ({
  stateChange: null as null | ((change: { current: string }) => void),
  listeners: new Map<string, Set<(payload: unknown) => void>>(),
}))

vi.mock('pusher-js', () => ({
  default: class {
    connection = { bind: (_: string, callback: (change: { current: string }) => void) => (fake.stateChange = callback) }
  },
}))

vi.mock('laravel-echo', () => ({
  default: class {
    private(channel: string) {
      return {
        listen: (event: string, listener: (payload: unknown) => void) => {
          const key = `${channel}:${event}`
          fake.listeners.set(key, (fake.listeners.get(key) ?? new Set()).add(listener))
        },
        stopListening: (event: string, listener: (payload: unknown) => void) => fake.listeners.get(`${channel}:${event}`)?.delete(listener),
      }
    }
    leave() {}
    disconnect() {}
  },
}))

afterEach(() => {
  vi.useRealTimers()
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
})

const broadcast = (key: string, payload: unknown) => act(() => fake.listeners.get(key)?.forEach((listener) => listener(payload)))

// Premier rendu : le module du rôle est chargé à la demande.
const slow = { timeout: 5000 }

const today = businessToday()
const tomorrow = nextDay(today)

const centre: RegionalQuotaRegion = {
  region: { id: 3, code: 'CENTRE', name: 'Centre' },
  status: 'QUOTA_PUBLISHED',
  quota_version: 2,
  quota_last_published_at: '2026-10-08T07:10:00+00:00',
  // Répartition prolongée pour la région (RG-A05).
  cutoffs: { distribution: '11:15', ordering: '13:00' },
  quotas: [
    {
      product: { id: 42, sku: 'P0042', name: 'Jus orange 1 L', family: { id: 2, code: 'JUS', name: 'Jus' }, sales_unit_label: 'Fardeau 6 × 1 L' },
      quantity: 1500,
      validated: 0,
    },
    {
      product: { id: 43, sku: 'P0043', name: 'Jus pomme 1 L', family: { id: 2, code: 'JUS', name: 'Jus' }, sales_unit_label: 'Fardeau 6 × 1 L' },
      quantity: 300,
      validated: 120,
    },
    {
      product: { id: 57, sku: 'P0057', name: 'Lben 1 L', family: { id: 1, code: 'LAIT', name: 'Laitiers' }, sales_unit_label: 'Caisse 12 × 1 L' },
      quantity: 800,
      validated: 0,
    },
  ],
}

const est: RegionalQuotaRegion = {
  region: { id: 4, code: 'EST', name: 'Est' },
  status: 'QUOTA_DRAFT',
  quota_version: 0,
  quota_last_published_at: null,
  cutoffs: { distribution: '10:00', ordering: '13:00' },
  quotas: [],
}

const opened = (day: string, regions: RegionalQuotaRegion[] = [centre, est]): RegionalQuotas => ({
  day,
  opened: true,
  status: 'OPEN',
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
  regions,
})

const notOpened = (day: string): RegionalQuotas => ({ day, opened: false, status: null, cutoffs: null, regions: [] })

const noNotifications: NotificationsPage = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, from: null, to: null, unread_count: 0 },
}

function quotasApi(user: CurrentUser, respond: (day: string) => Response) {
  const api = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () => json(noNotifications),
    'GET /api/v1/regional/quotas': ({ url }) => respond(url.searchParams.get('day') ?? ''),
  })
  const requestedDays = () =>
    api.fetchMock.mock.calls
      .map(([input]) => new URL(String(input), 'http://localhost'))
      .filter((url) => url.pathname === '/api/v1/regional/quotas')
      .map((url) => url.searchParams.get('day'))
  return { ...api, requestedDays }
}

const regionCard = (name: string) => screen.getByRole('region', { name })

describe('regional quotas of the day', () => {
  it('shows the published quotas of each region of the perimeter, grouped by family', async () => {
    const { requestedDays } = quotasApi(makeUser('regional'), (day) => json(opened(day)))

    renderApp('/regional/quotas')

    expect(await screen.findByRole('heading', { name: 'Quotas du jour' }, slow)).toBeInTheDocument()
    await screen.findByRole('region', { name: 'Centre' })
    expect(requestedDays()).toEqual([today])
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'false')

    const card = regionCard('Centre')
    expect(within(card).getByText('CENTRE')).toBeInTheDocument()
    expect(within(card).getByText('À répartir')).toBeInTheDocument()
    // Heure de publication dans le fuseau d'Alger (UTC+1).
    expect(within(card).getByText('Quotas publiés — version 2, le 08/10 à 08:10')).toBeInTheDocument()
    // Heure limite de répartition prolongée pour la région, commande inchangée.
    const cutoffs = within(card).getByTestId('region-cutoffs')
    expect(cutoffs).toHaveTextContent('Répartition avant 11:15 (prolongée) · Commande avant 13:00')
    expect(cutoffs).not.toHaveTextContent('13:00 (prolongée)')
    expect(within(card).getByText('3 produits')).toBeInTheDocument()

    // Familles dans l'ordre reçu, produits de chaque famille.
    expect(within(card).getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual(['Jus', 'Laitiers'])
    const juices = within(within(card).getByRole('list', { name: 'Jus' })).getAllByRole('listitem')
    expect(juices).toHaveLength(2)
    const [orange, apple] = juices as [HTMLElement, HTMLElement]
    expect(orange).toHaveTextContent('Jus orange 1 L')
    expect(orange).toHaveTextContent('P0042 · Fardeau 6 × 1 L')
    // Espaces insécables : séparateur de milliers et unité (le texte recherché est normalisé).
    expect(within(orange).getByText('1 500 UV').textContent).toBe('1\u00a0500\u00a0UV')
    // Le validé n'apparaît que s'il est > 0.
    expect(orange).not.toHaveTextContent('Validé')
    expect(apple).toHaveTextContent('Jus pomme 1 L')
    expect(within(apple).getByText('Validé : 120 UV')).toBeInTheDocument()

    const dairy = within(within(card).getByRole('list', { name: 'Laitiers' })).getAllByRole('listitem')
    expect(dairy).toHaveLength(1)
    expect(dairy[0]).toHaveTextContent('Lben 1 L')
    expect(dairy[0]).toHaveTextContent('P0057 · Caisse 12 × 1 L')
    expect(dairy[0]).toHaveTextContent('800 UV')
  })

  it('shows a region still waiting for its quotas', async () => {
    quotasApi(makeUser('regional'), (day) => json(opened(day)))
    renderApp('/regional/quotas')

    const card = await screen.findByRole('region', { name: 'Est' }, slow)
    expect(within(card).getByText('En attente des quotas')).toBeInTheDocument()
    expect(within(card).getByText(/L’ADV n’a pas encore publié les quotas de cette région/)).toBeInTheDocument()
    expect(within(card).getByTestId('region-cutoffs')).toHaveTextContent('Répartition avant 10:00 · Commande avant 13:00')
    expect(within(card).getByTestId('region-cutoffs')).not.toHaveTextContent('prolongée')
    expect(within(card).queryByRole('list')).not.toBeInTheDocument()
    expect(within(card).queryByText(/Quotas publiés/)).not.toBeInTheDocument()
  })

  it('explains that the quotas of a day are not opened yet', async () => {
    quotasApi(makeUser('regional'), (day) => json(notOpened(day)))
    renderApp('/regional/quotas')

    expect(await screen.findByText('Les quotas de cette journée ne sont pas encore ouverts.', {}, slow)).toBeInTheDocument()
    expect(screen.queryByRole('region')).not.toBeInTheDocument()
  })

  it('explains when no region is assigned for the day', async () => {
    quotasApi(makeUser('regional'), (day) => json(opened(day, [])))
    renderApp('/regional/quotas')

    expect(await screen.findByText(/Aucune région ne vous est affectée pour cette journée/, {}, slow)).toBeInTheDocument()
  })

  it('tells when the day is closed', async () => {
    quotasApi(makeUser('regional'), (day) =>
      json({ ...opened(day, [{ ...est, status: 'CLOSED' }]), status: 'CLOSED' } satisfies RegionalQuotas),
    )
    renderApp('/regional/quotas')

    expect(await screen.findByText('Journée clôturée : les quotas ne sont plus modifiables.', {}, slow)).toBeInTheDocument()
    expect(within(regionCard('Est')).getByText('Aucun quota n’a été publié pour cette région ce jour-là.')).toBeInTheDocument()
  })

  it('switches to tomorrow and back', async () => {
    const user = userEvent.setup()
    const { requestedDays } = quotasApi(makeUser('regional'), (day) => json(day === tomorrow ? notOpened(day) : opened(day)))
    renderApp('/regional/quotas')

    await screen.findByRole('region', { name: 'Centre' }, slow)
    await user.click(screen.getByRole('button', { name: /Demain/ }))

    expect(await screen.findByText('Les quotas de cette journée ne sont pas encore ouverts.')).toBeInTheDocument()
    expect(requestedDays()).toEqual([today, tomorrow])
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText(`Journée du ${weekdayOf(tomorrow)} ${tomorrow.split('-').reverse().join('/')}`)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Aujourd’hui/ }))
    expect(await screen.findByRole('region', { name: 'Centre' })).toBeInTheDocument()
  })

  it('opens the day of the address (notification link)', async () => {
    const { requestedDays } = quotasApi(makeUser('regional'), (day) => json(opened(day)))
    renderApp(`/regional/quotas?day=${tomorrow}`)

    await screen.findByRole('region', { name: 'Centre' }, slow)
    expect(requestedDays()).toEqual([tomorrow])
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'false')
  })

  it('opens another day of the address with neither choice selected', async () => {
    const { requestedDays } = quotasApi(makeUser('regional'), (day) => json(opened(day)))
    renderApp('/regional/quotas?day=2026-10-05')

    await screen.findByRole('region', { name: 'Centre' }, slow)
    expect(requestedDays()).toEqual(['2026-10-05'])
    expect(screen.getByText('Journée du lundi 05/10/2026')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'false')
  })

  it('falls back to today on a malformed day', async () => {
    const { requestedDays } = quotasApi(makeUser('regional'), (day) => json(opened(day)))
    renderApp('/regional/quotas?day=2026-02-31')

    await screen.findByRole('region', { name: 'Centre' }, slow)
    expect(requestedDays()).toEqual([today])
  })

  it('shows an error with a retry', async () => {
    const user = userEvent.setup()
    let fail = true
    quotasApi(makeUser('regional'), (day) => (fail ? problem(503, 'SERVER_ERROR', 'Service indisponible.') : json(opened(day))))
    renderApp('/regional/quotas')

    expect(await screen.findByText('Service indisponible.', {}, slow)).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByRole('region', { name: 'Centre' })).toBeInTheDocument()
  })

  it('reloads the quotas on a publication broadcast on the channel of a region', async () => {
    let version = 2
    const user = makeUser('regional', { id: 7, realtime: { key: 'app-key' } })
    const { requestedDays } = quotasApi(user, (day) => json(opened(day, [{ ...centre, quota_version: version }, est])))
    renderApp('/regional/quotas')

    await screen.findByRole('region', { name: 'Centre' }, slow)
    await waitFor(() => expect(fake.listeners.get(`region-day.3.${today}:.QuotaPublished`)?.size).toBe(1))
    expect(fake.listeners.get(`region-day.4.${today}:.QuotaPublished`)?.size).toBe(1)
    act(() => fake.stateChange?.({ current: 'connected' }))

    version = 3
    broadcast(`region-day.3.${today}:.QuotaPublished`, { region_id: 3, day: today, version: 3, first: false })

    expect(await screen.findByText(/Quotas publiés — version 3/)).toBeInTheDocument()
    expect(requestedDays()).toEqual([today, today])
  })

  it('reloads the quotas when a cutoff extension is notified', async () => {
    const user = makeUser('regional', { id: 7, realtime: { key: 'app-key' } })
    let distribution = '10:00'
    quotasApi(user, (day) => json(opened(day, [{ ...est, cutoffs: { distribution, ordering: '13:00' } }])))
    renderApp('/regional/quotas')

    await screen.findByRole('region', { name: 'Est' }, slow)
    await waitFor(() => expect(fake.listeners.get(`user.7:.${NOTIFICATION_EVENT}`)?.size).toBeGreaterThan(0))

    distribution = '11:15'
    broadcast(`user.7:.${NOTIFICATION_EVENT}`, {
      id: 'n-9',
      type: 'CUTOFF_EXTENDED',
      code: null,
      title: 'Heure limite prolongée — région Est',
      body: 'Répartition jusqu’à 11:15.',
      url: `/regional/quotas?day=${today}`,
      read_at: null,
      created_at: '2026-10-08T08:20:00+00:00',
    })

    await waitFor(() => expect(within(regionCard('Est')).getByTestId('region-cutoffs')).toHaveTextContent('Répartition avant 11:15 (prolongée)'))
  })

  it('polls every 30 s without realtime, and stops once connected', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const user = makeUser('regional', { id: 7, realtime: { key: 'app-key' } })
    const { requestedDays } = quotasApi(user, (day) => json(opened(day)))
    renderApp('/regional/quotas')

    await screen.findByRole('region', { name: 'Centre' }, slow)
    await waitFor(() => expect(fake.stateChange).not.toBeNull())
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(requestedDays()).toHaveLength(2))

    act(() => fake.stateChange?.({ current: 'connected' }))
    await act(() => vi.advanceTimersByTimeAsync(65_000))
    expect(requestedDays()).toHaveLength(2)
  })
})

describe('regional quotas formats', () => {
  it('validates the day of the address', () => {
    expect(isDayKey('2026-10-08')).toBe(true)
    expect(isDayKey('2026-02-31')).toBe(false)
    expect(isDayKey('08/10/2026')).toBe(false)
    expect(isDayKey(null)).toBe(false)
  })

  it('computes the next calendar day', () => {
    expect(nextDay('2026-10-08')).toBe('2026-10-09')
    expect(nextDay('2026-12-31')).toBe('2027-01-01')
    expect(nextDay('2028-02-28')).toBe('2028-02-29')
  })

  it('formats quantities with a space as thousands separator', () => {
    expect(formatQuantity(1500)).toBe('1\u00a0500')
    expect(formatQuantity(1234567)).toBe('1\u00a0234\u00a0567')
    expect(formatQuantity(0)).toBe('0')
  })

  it('groups the quotas by family in the received order', () => {
    expect(groupByFamily(centre.quotas).map(({ family, quotas }) => [family.code, quotas.map((quota) => quota.product.sku)])).toEqual([
      ['JUS', ['P0042', 'P0043']],
      ['LAIT', ['P0057']],
    ])
  })
})

describe('module boundaries', () => {
  // Code de la PWA de tous les rôles, client compris : rien du back-office (§9.5).
  const sources = import.meta.glob(
    [
      '../../../shared/notifications/*.{ts,tsx}',
      '../../notifications/*.{ts,tsx}',
      './*.{ts,tsx}',
      '../../../shared/layout/AppShell.tsx',
      '!**/*.test.{ts,tsx}',
    ],
    { query: '?raw', import: 'default', eager: true },
  )

  it('imports nothing from the back-office modules', () => {
    expect(Object.keys(sources).length).toBeGreaterThanOrEqual(8)
    for (const [file, source] of Object.entries(sources)) {
      expect(String(source), file).not.toMatch(/(from |import\()'[^']*\b(adv|admin|backoffice)\//)
    }
  })
})
