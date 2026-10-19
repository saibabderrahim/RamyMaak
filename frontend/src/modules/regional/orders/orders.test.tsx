import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { NotificationsPage } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, mockApi, problem, renderApp } from '../../../test/utils'
import { mockScreen } from '../../distribution/testing'
import { nextDay } from '../quotas/format'
import { CENTRE, COUNTS, DAY, EST, notOpened, opened, ORDER_ID, ORDERS_URL, ordersPage, regional, REGIONS_URL, renderOrders, SECOND, summary } from './testing'

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

const broadcast = (channel: string, event: string, payload: unknown) =>
  act(() => fake.listeners.get(`${channel}:.${event}`)?.forEach((listener) => listener(payload)))

const where = () => screen.getByTestId('where').textContent
const tab = (name: RegExp) => screen.getByRole('tab', { name })
const findTable = () => screen.findByRole('table', { name: 'À valider — commandes du 08/10/2026, par heure d’envoi' })
const rowsOf = (table: HTMLElement) => within(table).getAllByRole('row').slice(1)

describe('regional orders — list', () => {
  it('lists the orders to validate of the day by submission time, with the counts of each tab', async () => {
    const { queries } = renderOrders()
    const table = await findTable()

    expect(screen.getByRole('heading', { name: 'Commandes reçues' })).toBeInTheDocument()
    expect(screen.getByText('Journée du jeudi 08/10/2026')).toBeInTheDocument()
    expect(queries(ORDERS_URL)).toEqual([{ day: DAY, 'filter[status]': 'SUBMITTED', page: '1' }])
    expect(queries(REGIONS_URL)).toEqual([{ day: DAY }])
    // Une seule région : ni liste des régions ni colonne Région.
    expect(screen.queryByRole('combobox', { name: 'Région' })).not.toBeInTheDocument()
    expect(within(table).queryByRole('columnheader', { name: 'Région' })).not.toBeInTheDocument()

    // Onglets et compteurs (meta.counts).
    expect(screen.getAllByRole('tab').map((element) => element.textContent)).toEqual(['À valider2', 'Validées0', 'Rejetées0', 'Annulées et expirées1'])
    expect(tab(/À valider/)).toHaveAttribute('aria-selected', 'true')
    expect(tab(/Validées/)).toHaveAttribute('aria-selected', 'false')
    expect(screen.getByRole('tabpanel')).toHaveAccessibleName(/À valider/)

    // Ordre du serveur (heure d'envoi croissante), heure d'Alger.
    const [first, second] = rowsOf(table) as [HTMLElement, HTMLElement]
    expect(rowsOf(table)).toHaveLength(2)
    expect(within(first).getAllByRole('cell').map((cell) => cell.textContent)).toEqual([
      'RMK-261008-00042',
      'C0012Superette El Baraka',
      '09:40',
      '2',
      '58\u00a0500,00\u00a0DA',
      'Envoyée',
    ])
    expect(within(first).getByRole('link', { name: 'RMK-261008-00042' })).toHaveAttribute('href', `/regional/commandes/${ORDER_ID}`)
    expect(within(first).queryByText('Bloqué')).not.toBeInTheDocument()
    expect(within(second).getByRole('link', { name: 'RMK-261008-00043' })).toBeInTheDocument()
    expect(within(second).getByText('Bloqué')).toBeInTheDocument()
    expect(within(second).getByText('10:05')).toBeInTheDocument()
    expect(within(second).getByText('1 250,50 DA')).toBeInTheDocument()
  })

  it('shows the cards of a phone and opens the detail of an order', async () => {
    mockScreen(false)
    renderOrders()
    const list = await screen.findByRole('list', { name: 'À valider — commandes du 08/10/2026, par heure d’envoi' })
    const items = within(list).getAllByRole('listitem')
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('RMK-261008-00042')
    expect(items[0]).toHaveTextContent('C0012Superette El Baraka')
    expect(items[0]).toHaveTextContent('09:40 · 2 lignes · 58 500,00 DA HT')
    expect(items[1]).toHaveTextContent('Bloqué')
    expect(items[1]).toHaveTextContent('10:05 · 1 ligne · 1 250,50 DA HT')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()

    await userEvent.setup().click(within(items[0]!).getByRole('link'))
    expect(where()).toBe(`/regional/commandes/${ORDER_ID}`)
    expect(await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })).toBeInTheDocument()
  })

  it('switches tab, keeps the counts while loading and explains an empty tab', async () => {
    let release: () => void = () => undefined
    const { queries } = renderOrders({
      routes: {
        [ORDERS_URL]: async ({ url }) => {
          const status = url.searchParams.get('filter[status]')
          if (status === 'SUBMITTED') return json(ordersPage([summary(), SECOND]))
          if (status?.startsWith('VALIDATED')) await new Promise<void>((resolve) => (release = resolve))
          return json(ordersPage([], { counts: { ...COUNTS, SUBMITTED: 3 } }))
        },
      },
    })
    await findTable()

    const user = userEvent.setup()
    await user.click(tab(/Validées/))
    expect(where()).toBe(`/regional/commandes?day=${DAY}&onglet=validees`)
    expect(tab(/Validées/)).toHaveAttribute('aria-selected', 'true')
    // Compteurs de la liste précédente, pas ses commandes.
    expect(tab(/À valider/)).toHaveTextContent('À valider2')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.getByText('Chargement…')).toBeInTheDocument()

    act(() => release())
    expect(await screen.findByText('Aucune commande validée pour cette journée.')).toBeInTheDocument()
    expect(tab(/À valider/)).toHaveTextContent('À valider3')
    expect(queries(ORDERS_URL).map((query) => query['filter[status]'])).toEqual(['SUBMITTED', 'VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,DELIVERED'])

    // Flèches du clavier (motif « onglets ») : onglet suivant, puis le dernier.
    await user.keyboard('{ArrowRight}')
    expect(tab(/Rejetées/)).toHaveFocus()
    expect(await screen.findByText('Aucune commande rejetée pour cette journée.')).toBeInTheDocument()
    await user.keyboard('{End}')
    expect(where()).toBe(`/regional/commandes?day=${DAY}&onglet=annulees`)
    expect(await screen.findByText('Aucune commande annulée ou expirée pour cette journée.')).toBeInTheDocument()
    expect(queries(ORDERS_URL).map((query) => query['filter[status]'])).toEqual([
      'SUBMITTED',
      'VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,DELIVERED',
      'REJECTED',
      'CANCELLED,EXPIRED',
    ])
  })

  it('opens the tab of the address and falls back to « À valider » for an unknown one', async () => {
    const cancelled = summary({ status: 'CANCELLED', status_label: 'Annulée' })
    const first = renderOrders({
      path: `/regional/commandes?day=${DAY}&onglet=annulees`,
      routes: { [ORDERS_URL]: () => json(ordersPage([cancelled])) },
    })
    const table = await screen.findByRole('table', { name: 'Annulées et expirées — commandes du 08/10/2026, par heure d’envoi' })
    expect(within(table).getByText('Annulée')).toBeInTheDocument()
    expect(first.queries(ORDERS_URL)).toEqual([{ day: DAY, 'filter[status]': 'CANCELLED,EXPIRED', page: '1' }])
    first.unmount()

    const second = renderOrders({ path: `/regional/commandes?day=${DAY}&onglet=inconnu` })
    await findTable()
    expect(tab(/À valider/)).toHaveAttribute('aria-selected', 'true')
    expect(second.queries(ORDERS_URL)).toEqual([{ day: DAY, 'filter[status]': 'SUBMITTED', page: '1' }])
  })

  it('lists all the regions of the perimeter, or the one chosen', async () => {
    const { queries } = renderOrders({
      routes: {
        [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])),
        [ORDERS_URL]: ({ url }) =>
          json(url.searchParams.get('filter[region]') === '4' ? ordersPage([summary({ region: EST })], { counts: { ...COUNTS, SUBMITTED: 1 } }) : ordersPage([summary(), SECOND])),
      },
    })
    const table = await findTable()
    const select = screen.getByRole('combobox', { name: 'Région' })
    expect(select).toHaveValue('')
    expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual(['Toutes mes régions', 'CENTRE — Centre', 'EST — Est'])
    // Toutes les régions : la région de chaque commande est indiquée.
    expect(within(table).getByRole('columnheader', { name: 'Région' })).toBeInTheDocument()
    expect(within(rowsOf(table)[0]!).getByText('Centre')).toBeInTheDocument()

    await userEvent.setup().selectOptions(select, '4')
    expect(where()).toBe(`/regional/commandes?day=${DAY}&region=4`)
    await waitFor(() => expect(tab(/À valider/)).toHaveTextContent('À valider1'))
    expect(screen.queryByRole('columnheader', { name: 'Région' })).not.toBeInTheDocument()
    expect(queries(ORDERS_URL)).toEqual([
      { day: DAY, 'filter[status]': 'SUBMITTED', page: '1' },
      { day: DAY, 'filter[status]': 'SUBMITTED', 'filter[region]': '4', page: '1' },
    ])
  })

  it('waits for the regions before reading the region of the address, and ignores one outside the perimeter', async () => {
    const first = renderOrders({ path: `/regional/commandes?day=${DAY}&region=4`, routes: { [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])) } })
    await findTable()
    expect(screen.getByRole('combobox', { name: 'Région' })).toHaveValue('4')
    expect(first.queries(ORDERS_URL)).toEqual([{ day: DAY, 'filter[status]': 'SUBMITTED', 'filter[region]': '4', page: '1' }])
    first.unmount()

    const second = renderOrders({ path: `/regional/commandes?day=${DAY}&region=99`, routes: { [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])) } })
    await findTable()
    expect(screen.getByRole('combobox', { name: 'Région' })).toHaveValue('')
    expect(second.queries(ORDERS_URL)).toEqual([{ day: DAY, 'filter[status]': 'SUBMITTED', page: '1' }])
  })

  it('shows today by default and switches to tomorrow', async () => {
    const today = businessToday()
    const tomorrow = nextDay(today)
    const { queries } = renderOrders({
      path: '/regional/commandes',
      routes: {
        [REGIONS_URL]: ({ url }) => json(url.searchParams.get('day') === today ? opened(today) : notOpened(tomorrow)),
        [ORDERS_URL]: () => json(ordersPage([summary({ day: today, submitted_at: `${today}T08:40:00+00:00` })])),
      },
    })
    expect(await screen.findByRole('table')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'false')

    await userEvent.setup().click(screen.getByRole('button', { name: /Demain/ }))
    expect(where()).toBe(`/regional/commandes?day=${tomorrow}`)
    expect(await screen.findByText('Cette journée n’est pas encore ouverte.')).toBeInTheDocument()
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument()
    expect(queries(REGIONS_URL)).toEqual([{ day: today }, { day: tomorrow }])
    // La liste part avec les régions ; journée non ouverte : plus rien à lire ensuite.
    expect(queries(ORDERS_URL).map((query) => query.day)).toEqual([today, tomorrow])
  })

  it('shows the day of submission of an order sent the day before', async () => {
    renderOrders({ routes: { [ORDERS_URL]: () => json(ordersPage([summary({ submitted_at: '2026-10-07T17:20:00+00:00' })])) } })
    const table = await findTable()
    expect(within(rowsOf(table)[0]!).getByText('07/10 18:20')).toBeInTheDocument()
  })

  it('explains when no region is assigned that day', async () => {
    const { requests } = renderOrders({ path: '/regional/commandes?day=2026-10-08&region=3', routes: { [REGIONS_URL]: () => json(opened(DAY, [])) } })
    expect(await screen.findByText('Aucune région ne vous est affectée ce jour-là.')).toBeInTheDocument()
    expect(requests(ORDERS_URL)).toHaveLength(0)
  })

  it('loads the next pages on demand', async () => {
    const third = summary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEH', number: 'RMK-261008-00044', submitted_at: '2026-10-08T09:30:00+00:00' })
    const { queries } = renderOrders({
      routes: {
        [ORDERS_URL]: ({ url }) =>
          // La page 2 répète la dernière commande de la page 1 (une commande est partie entre-temps).
          json(url.searchParams.get('page') === '2' ? ordersPage([SECOND, third], { page: 2, lastPage: 2, total: 3 }) : ordersPage([summary(), SECOND], { lastPage: 2, total: 3 })),
      },
    })
    const table = await findTable()
    expect(screen.getByText('2 affichées sur 3')).toBeInTheDocument()

    await userEvent.setup().click(screen.getByRole('button', { name: 'Afficher plus de commandes' }))
    await waitFor(() => expect(rowsOf(table)).toHaveLength(3))
    expect(rowsOf(table).map((row) => within(row).getByRole('link').textContent)).toEqual(['RMK-261008-00042', 'RMK-261008-00043', 'RMK-261008-00044'])
    expect(screen.queryByRole('button', { name: 'Afficher plus de commandes' })).not.toBeInTheDocument()
    expect(queries(ORDERS_URL).map((query) => query.page)).toEqual(['1', '2'])
  })

  it('offers to retry when the list cannot be read', async () => {
    let failing = true
    renderOrders({ routes: { [ORDERS_URL]: () => (failing ? problem(500, 'SERVER_ERROR', 'Erreur') : json(ordersPage([summary()]))) } })
    const retry = await screen.findByRole('button', { name: 'Réessayer' })
    expect(screen.getByRole('alert')).toBeInTheDocument()

    failing = false
    await userEvent.setup().click(retry)
    expect(await findTable()).toBeInTheDocument()
  })
})

describe('regional orders — realtime', () => {
  it('rereads the list on the order events of each region of the perimeter', async () => {
    let orders = [summary()]
    const { requests } = renderOrders({
      user: regional({ realtime: { key: 'app-key' } }),
      routes: {
        [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])),
        [ORDERS_URL]: () => json(ordersPage(orders, { counts: { ...COUNTS, SUBMITTED: orders.length } })),
      },
    })
    const table = await findTable()
    for (const channel of [`region-day.3.${DAY}`, `region-day.4.${DAY}`]) {
      for (const event of ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired']) {
        await waitFor(() => expect(fake.listeners.get(`${channel}:.${event}`)?.size).toBe(1))
      }
    }
    act(() => fake.stateChange?.({ current: 'connected' }))

    orders = [summary(), summary({ ...SECOND, region: EST })]
    broadcast(`region-day.4.${DAY}`, 'OrderSubmitted', { id: SECOND.id, number: SECOND.number, status: 'SUBMITTED', version: 1, day: DAY, client_id: 47 })
    await waitFor(() => expect(rowsOf(table)).toHaveLength(2))
    expect(tab(/À valider/)).toHaveTextContent('À valider2')
    expect(requests(ORDERS_URL)).toHaveLength(2)

    orders = [summary()]
    broadcast(`region-day.4.${DAY}`, 'OrdersExpired', { day: DAY, count: 1 })
    await waitFor(() => expect(rowsOf(table)).toHaveLength(1))
    expect(requests(ORDERS_URL)).toHaveLength(3)

    // Retour de la connexion après une coupure : des événements ont pu être manqués.
    act(() => fake.stateChange?.({ current: 'unavailable' }))
    act(() => fake.stateChange?.({ current: 'connected' }))
    await waitFor(() => expect(requests(ORDERS_URL).length).toBeGreaterThan(3))
  })

  it('listens only to the region chosen', async () => {
    renderOrders({
      path: `/regional/commandes?day=${DAY}&region=3`,
      user: regional({ realtime: { key: 'app-key' } }),
      routes: { [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])) },
    })
    await findTable()
    await waitFor(() => expect(fake.listeners.get(`region-day.3.${DAY}:.OrderSubmitted`)?.size).toBe(1))
    expect(fake.listeners.get(`region-day.4.${DAY}:.OrderSubmitted`)?.size ?? 0).toBe(0)
  })

  it('refreshes every 30 s without realtime', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderOrders()
    await findTable()
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(requests(ORDERS_URL)).toHaveLength(2))
  })
})

describe('regional orders — application', () => {
  it('is the « Commandes » tab of the regional', async () => {
    const noNotifications: NotificationsPage = {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, from: null, to: null, unread_count: 0 },
    }
    mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      'GET /api/v1/notifications': () => json(noNotifications),
      [REGIONS_URL]: ({ url }) => json(opened(url.searchParams.get('day') ?? DAY)),
      [ORDERS_URL]: () => json(ordersPage([summary()])),
    })
    renderApp(`/regional/commandes?day=${DAY}`)
    expect(await screen.findByRole('heading', { name: 'Commandes reçues' }, { timeout: 5000 })).toBeInTheDocument()
    expect(await findTable()).toBeInTheDocument()
  })
})
