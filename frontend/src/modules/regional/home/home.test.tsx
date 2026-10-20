import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { businessToday } from '../../../shared/format'
import { disconnectRealtime, NOTIFICATION_EVENT } from '../../../shared/realtime'
import type { NotificationsPage } from '../../../shared/api/types'
import { json, mockApi, problem, renderApp } from '../../../test/utils'
import { nextDay } from '../quotas/format'
import { DASHBOARD_EVENTS } from './api'
import { client, DAY, DASHBOARD_URL, dashboard, EST, notOpened, region, regional, renderMyDay } from './testing'

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
const where = () => screen.getByTestId('where').textContent
const regionCard = (name: string) => screen.getByRole('region', { name })
const findCard = (name = 'Centre') => screen.findByRole('region', { name })
const withoutOrder = () => screen.getByRole('region', { name: 'Clients sans commande' })
const group = (name: string) => within(withoutOrder()).getByRole('group', { name })

describe('Ma journée', () => {
  it('shows a card per region: status, cutoffs with their extension, orders to validate, balance, clients', async () => {
    renderMyDay()
    const card = await findCard()

    expect(screen.getByRole('heading', { level: 1, name: 'Ma journée' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Journée affichée' })).toBeInTheDocument()
    expect(within(card).getByRole('heading', { level: 2, name: 'Centre' })).toBeInTheDocument()
    expect(screen.getByText('Journée du jeudi 08/10/2026')).toBeInTheDocument()
    expect(within(card).getByText('CENTRE')).toBeInTheDocument()
    expect(within(card).getByText('Répartition publiée')).toBeInTheDocument()

    // Heures limites de la région, prolongation comprise (commande prolongée à 13:30).
    const cutoffs = within(card).getByTestId('region-cutoffs')
    expect(cutoffs).toHaveTextContent('Répartition avant 10:00 · Commande avant 13:30 (prolongée) · Validation avant 15:00')
    expect(cutoffs).not.toHaveTextContent('bloquante')
    expect(within(card).queryByText(/la validation est refusée/)).not.toBeInTheDocument()

    // Commandes à valider : lien vers la liste de la région, la plus ancienne en premier.
    const toValidate = within(card).getByRole('link', { name: '4 à valider — Centre' })
    expect(toValidate).toHaveTextContent(/^4 à valider$/)
    expect(toValidate).toHaveAttribute('href', `/regional/commandes?day=${DAY}&onglet=a-valider&region=3`)
    expect(within(card).getByText('La plus ancienne attend depuis 09:40')).toBeInTheDocument()
    expect(within(card).getByText('Validées : 12 · Rejetées : 1 · Annulées ou expirées : 0')).toBeInTheDocument()
    expect(within(card).getByTestId('region-clients')).toHaveTextContent('Clients : 40 notifiés · 17 ont commandé · 23 sans commande')

    // Raccourci vers la grille de répartition (déjà envoyée : consultation).
    expect(within(card).getByRole('link', { name: 'Voir la répartition — Centre' })).toHaveAttribute('href', `/regional/repartition?day=${DAY}&region=3`)

    const shortcuts = screen.getByRole('navigation', { name: 'Raccourcis' })
    expect(within(shortcuts).getByRole('link', { name: 'Quotas du jour' })).toHaveAttribute('href', `/regional/quotas?day=${DAY}`)
    expect(within(shortcuts).getByRole('link', { name: 'Commandes reçues' })).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)
  })

  it('opens the list of the orders to validate of the region', async () => {
    const user = userEvent.setup()
    renderMyDay()
    await user.click(await screen.findByRole('link', { name: '4 à valider — Centre' }))
    expect(where()).toBe(`/regional/commandes?day=${DAY}&onglet=a-valider&region=3`)
  })

  it('asks for the dashboard of the day, today by default', async () => {
    const today = businessToday()
    const { queries } = renderMyDay({ path: '/regional' })
    await findCard()
    expect(queries(DASHBOARD_URL)).toEqual([{ day: today }])
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'false')
  })

  it('switches to tomorrow and back', async () => {
    const user = userEvent.setup()
    const today = businessToday()
    const tomorrow = nextDay(today)
    const { queries } = renderMyDay({
      path: '/regional',
      routes: { [DASHBOARD_URL]: ({ url }) => json(url.searchParams.get('day') === tomorrow ? notOpened(tomorrow) : dashboard({ day: today })) },
    })
    await findCard()

    await user.click(screen.getByRole('button', { name: /Demain/ }))
    expect(await screen.findByText('Cette journée n’est pas encore ouverte.')).toBeInTheDocument()
    expect(where()).toBe(`/regional?day=${tomorrow}`)
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.queryByRole('region')).not.toBeInTheDocument()
    expect(queries(DASHBOARD_URL)).toEqual([{ day: today }, { day: tomorrow }])

    await user.click(screen.getByRole('button', { name: /Aujourd’hui/ }))
    expect(await findCard()).toBeInTheDocument()
  })

  it('falls back on today for a malformed day', async () => {
    const { queries } = renderMyDay({ path: '/regional?day=2026-02-31' })
    await findCard()
    expect(queries(DASHBOARD_URL)).toEqual([{ day: businessToday() }])
  })

  it('says « bloquante » on the validation cutoff when the day refuses a late validation, and « prolongée » when it was extended', async () => {
    renderMyDay({
      routes: {
        [DASHBOARD_URL]: () =>
          json(dashboard({ validation_blocking: true, regions: [region({ extended: { distribution: false, ordering: false, validation: true } }), region({ region: EST, extended: { distribution: false, ordering: false, validation: false } })] })),
      },
    })
    await findCard()

    expect(within(regionCard('Centre')).getByTestId('region-cutoffs')).toHaveTextContent('Validation avant 15:00 (prolongée, bloquante)')
    expect(within(regionCard('Est')).getByTestId('region-cutoffs')).toHaveTextContent('Commande avant 13:30 · Validation avant 15:00 (bloquante)')
    expect(within(regionCard('Est')).getByTestId('region-cutoffs')).not.toHaveTextContent('13:30 (prolongée)')
    expect(within(regionCard('Est')).getByText('Après 15:00, la validation est refusée ; le rejet reste possible.')).toBeInTheDocument()
  })

  it('shows a region with nothing to validate, without link', async () => {
    renderMyDay({ routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [region({ orders: { SUBMITTED: 0, VALIDATED: 16, REJECTED: 0, CLOSED: 2 }, oldest_submitted_at: null })] })) } })
    const card = await findCard()

    expect(within(card).getByText('Aucune commande à valider')).toBeInTheDocument()
    expect(within(card).queryByRole('link', { name: /à valider/ })).not.toBeInTheDocument()
    expect(within(card).queryByText(/La plus ancienne/)).not.toBeInTheDocument()
    expect(within(card).getByText('Validées : 16 · Rejetées : 0 · Annulées ou expirées : 2')).toBeInTheDocument()
  })

  it('offers to distribute a region whose quotas are published, and says the distribution is not sent yet', async () => {
    renderMyDay({
      routes: {
        [DASHBOARD_URL]: () =>
          json(
            dashboard({
              regions: [
                region({ status: 'QUOTA_PUBLISHED', distribution_published_at: null, orders: { SUBMITTED: 0, VALIDATED: 0, REJECTED: 0, CLOSED: 0 }, oldest_submitted_at: null, clients: { notified: 0, ordered: 0, without_order: 0 } }),
                region({ region: EST, status: 'QUOTA_DRAFT', quota_published_at: null, distribution_published_at: null, orders: { SUBMITTED: 0, VALIDATED: 0, REJECTED: 0, CLOSED: 0 }, oldest_submitted_at: null, clients: { notified: 0, ordered: 0, without_order: 0 } }),
              ],
            }),
          ),
      },
    })
    await findCard()

    expect(within(regionCard('Centre')).getByText('À répartir')).toBeInTheDocument()
    const link = within(regionCard('Centre')).getByRole('link', { name: 'Répartir — Centre' })
    expect(link).toHaveAttribute('href', `/regional/repartition?day=${DAY}&region=3`)
    expect(within(regionCard('Centre')).getByTestId('region-clients')).toHaveTextContent('La répartition n’a pas encore été envoyée aux clients.')

    // Quotas pas encore publiés : rien à répartir.
    expect(within(regionCard('Est')).getByText('En attente des quotas')).toBeInTheDocument()
    expect(within(regionCard('Est')).queryByRole('link')).not.toBeInTheDocument()
  })

  it('tells that the day is closed, and only offers to view the distribution', async () => {
    renderMyDay({
      routes: {
        [DASHBOARD_URL]: () =>
          json(dashboard({ status: 'CLOSED', regions: [region({ status: 'CLOSED', distribution_published_at: null, orders: { SUBMITTED: 0, VALIDATED: 14, REJECTED: 1, CLOSED: 3 } })] })),
      },
    })
    const card = await findCard()

    expect(screen.getByText('Journée clôturée : plus aucune décision n’est possible.')).toBeInTheDocument()
    expect(within(card).getByText('Clôturée')).toBeInTheDocument()
    expect(within(card).getByRole('link', { name: 'Voir la répartition — Centre' })).toBeInTheDocument()
    expect(within(card).queryByRole('link', { name: /Répartir —/ })).not.toBeInTheDocument()
  })

  it('explains a day which is not opened, and a perimeter without region', async () => {
    const first = renderMyDay({ routes: { [DASHBOARD_URL]: () => json(notOpened(DAY)) } })
    expect(await screen.findByText('Cette journée n’est pas encore ouverte.')).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Raccourcis' })).not.toBeInTheDocument()
    first.unmount()

    renderMyDay({ routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [] })) } })
    expect(await screen.findByText('Aucune région ne vous est affectée ce jour-là.')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Clients sans commande' })).not.toBeInTheDocument()
  })

  it('shows an error with a retry', async () => {
    const user = userEvent.setup()
    let failing = true
    renderMyDay({ routes: { [DASHBOARD_URL]: () => (failing ? problem(503, 'SERVER_ERROR', 'Service indisponible.') : json(dashboard())) } })

    expect(await screen.findByText('Service indisponible.')).toBeInTheDocument()
    failing = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await findCard()).toBeInTheDocument()
  })

  it('keeps what it shows when a refresh fails, and says so', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let failing = false
    renderMyDay({ routes: { [DASHBOARD_URL]: () => (failing ? problem(503, 'SERVER_ERROR', 'Service indisponible.') : json(dashboard())) } })
    await findCard()

    failing = true
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    expect(await screen.findByText('Mise à jour impossible (Service indisponible.) : cette page n’est peut-être plus à jour.')).toBeInTheDocument()
    expect(regionCard('Centre')).toBeInTheDocument()
  })
})

describe('Ma journée — clients without order', () => {
  const clients = [
    client({ id: 12, code: 'C0012', name: 'Superette El Baraka', phone: '0550 12 34 56', phone2: '0770112233' }),
    client({ id: 47, code: 'C0047', name: 'Grossiste Benali', phone: null, phone2: ' ' }),
    client({ id: 51, code: 'C0051', name: 'Epicerie du Port', region_id: 4, phone: '0661 00 11 22' }),
  ]
  const OUEST = { id: 5, code: 'OUEST', name: 'Ouest' }
  const NORD = { id: 6, code: 'NORD', name: 'Nord' }
  const routes = {
    [DASHBOARD_URL]: () =>
      json(
        dashboard({
          regions: [
            region(),
            region({ region: EST, clients: { notified: 8, ordered: 7, without_order: 1 } }),
            region({ region: OUEST, clients: { notified: 10, ordered: 10, without_order: 0 } }),
            region({ region: NORD, distribution_published_at: null, clients: { notified: 0, ordered: 0, without_order: 0 } }),
          ],
          clients_without_order: clients,
        }),
      ),
  }

  it('lists them by region, with a call in one gesture on each phone and the link to the sheet', async () => {
    renderMyDay({ routes })
    await findCard()

    expect(within(withoutOrder()).getByRole('heading', { level: 2, name: 'Clients sans commande' })).toBeInTheDocument()
    const centre = group('Centre')
    expect(within(centre).getByText('2 clients sans commande')).toBeInTheDocument()
    const rows = [...centre.querySelectorAll<HTMLElement>(':scope > ul > li')]
    expect(rows).toHaveLength(2)

    const baraka = rows[0]!
    expect(baraka).toHaveTextContent(/^Superette El Baraka C0012/)
    expect(within(baraka).getByRole('link', { name: 'Appeler Superette El Baraka au 0550 12 34 56' })).toHaveAttribute('href', 'tel:0550123456')
    expect(within(baraka).getByRole('link', { name: 'Appeler Superette El Baraka au 0770112233' })).toHaveAttribute('href', 'tel:0770112233')
    expect(within(baraka).getByRole('link', { name: 'Voir la fiche de Superette El Baraka' })).toHaveAttribute('href', '/regional/clients/12')

    // Aucun téléphone renseigné : la mention, pas de lien d'appel ; la fiche reste accessible.
    const benali = rows[1]!
    expect(within(benali).queryByRole('link', { name: /Appeler/ })).not.toBeInTheDocument()
    expect(within(benali).getByText('Pas de téléphone')).toBeInTheDocument()
    expect(within(benali).getByRole('link', { name: 'Voir la fiche de Grossiste Benali' })).toHaveAttribute('href', '/regional/clients/47')
  })

  it('says why a region has none: distribution not sent yet, or everybody ordered', async () => {
    renderMyDay({ routes })
    await findCard()

    expect(within(group('Nord')).getByText('Les clients sans commande seront listés une fois la répartition envoyée.')).toBeInTheDocument()
    expect(within(group('Nord')).queryByText(/clients? sans commande$/)).not.toBeInTheDocument()
    expect(within(group('Ouest')).getByText('Tous les clients notifiés ont commandé.')).toBeInTheDocument()
    expect(within(group('Est')).getByText('1 client sans commande')).toBeInTheDocument()
  })

  it('opens the sheet of a client', async () => {
    const user = userEvent.setup()
    renderMyDay({ routes })
    await findCard()
    await user.click(screen.getByRole('link', { name: 'Voir la fiche de Epicerie du Port' }))
    expect(where()).toBe('/regional/clients/51')
  })

  it('counts a single client in the singular', async () => {
    renderMyDay({ routes: { [DASHBOARD_URL]: () => json(dashboard({ clients_without_order: [client()] })) } })
    await findCard()
    expect(within(group('Centre')).getByText('1 client sans commande')).toBeInTheDocument()
  })
})

describe('Ma journée — real time', () => {
  const live = () => regional({ realtime: { key: 'app-key' } })

  it('listens to the orders, the decisions, the quotas and the distribution of each region, and to the notifications of the user', async () => {
    renderMyDay({ user: live(), routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [region(), region({ region: EST })] })) } })
    await findCard()

    for (const channel of [`region-day.3.${DAY}`, `region-day.4.${DAY}`]) {
      for (const event of DASHBOARD_EVENTS) {
        await waitFor(() => expect(fake.listeners.get(`${channel}:.${event}`)?.size).toBe(1))
      }
    }
    expect(DASHBOARD_EVENTS).toEqual(
      expect.arrayContaining([
        'OrderSubmitted',
        'OrderUpdated',
        'OrderCancelled',
        'OrdersExpired',
        'OrderValidated',
        'OrderRejected',
        'OrdersProcessed',
        'OrderCancelledByAdv',
        'DistributionPublished',
        'QuotaPublished',
      ]),
    )
    await waitFor(() => expect(fake.listeners.get(`user.7:.${NOTIFICATION_EVENT}`)?.size).toBeGreaterThan(0))
  })

  it('rereads the dashboard on an order event of a region', async () => {
    let toValidate = 4
    const { requests } = renderMyDay({
      user: live(),
      routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [region({ orders: { SUBMITTED: toValidate, VALIDATED: 12, REJECTED: 1, CLOSED: 0 } })] })) },
    })
    await findCard()
    await waitFor(() => expect(fake.listeners.get(`region-day.3.${DAY}:.OrderSubmitted`)?.size).toBe(1))
    act(() => fake.stateChange?.({ current: 'connected' }))

    toValidate = 5
    broadcast(`region-day.3.${DAY}:.OrderSubmitted`, { id: 'x', number: 'RMK-261008-00050', status: 'SUBMITTED', version: 1, day: DAY, client_id: 12 })
    expect(await screen.findByRole('link', { name: '5 à valider — Centre' })).toBeInTheDocument()
    expect(requests(DASHBOARD_URL)).toHaveLength(2)

    // Décision d'un autre compte, traitement ADV en masse, annulation ADV : le bilan se relit.
    toValidate = 3
    broadcast(`region-day.3.${DAY}:.OrderValidated`, { id: 'y', number: 'RMK-261008-00051', status: 'VALIDATED', version: 2, day: DAY, client_id: 13 })
    expect(await screen.findByRole('link', { name: '3 à valider — Centre' })).toBeInTheDocument()
    broadcast(`region-day.3.${DAY}:.OrdersProcessed`, { day: DAY, status: 'IN_PREPARATION', count: 12 })
    await waitFor(() => expect(requests(DASHBOARD_URL)).toHaveLength(4))
    broadcast(`region-day.3.${DAY}:.OrderRejected`, { id: 'z', number: 'RMK-261008-00052', status: 'REJECTED', version: 2, day: DAY, client_id: 14 })
    await waitFor(() => expect(requests(DASHBOARD_URL)).toHaveLength(5))
    broadcast(`region-day.3.${DAY}:.OrderCancelledByAdv`, { id: 'z', number: 'RMK-261008-00052', status: 'CANCELLED', version: 3, day: DAY, client_id: 14 })
    await waitFor(() => expect(requests(DASHBOARD_URL)).toHaveLength(6))
  })

  it('rereads the dashboard when the distribution or the quotas are published', async () => {
    let region3 = region({ status: 'QUOTA_PUBLISHED', distribution_published_at: null, clients: { notified: 0, ordered: 0, without_order: 0 } })
    renderMyDay({ user: live(), routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [region3] })) } })
    await findCard()
    await waitFor(() => expect(fake.listeners.get(`region-day.3.${DAY}:.DistributionPublished`)?.size).toBe(1))
    act(() => fake.stateChange?.({ current: 'connected' }))
    expect(within(regionCard('Centre')).getByRole('link', { name: 'Répartir — Centre' })).toBeInTheDocument()

    region3 = region()
    broadcast(`region-day.3.${DAY}:.DistributionPublished`, { region_id: 3, day: DAY, version: 1, first: true })
    expect(await within(regionCard('Centre')).findByRole('link', { name: 'Voir la répartition — Centre' })).toBeInTheDocument()
    expect(within(regionCard('Centre')).getByTestId('region-clients')).toHaveTextContent('40 notifiés')

    region3 = region({ cutoffs: { distribution: '10:00', ordering: '13:30', validation: '15:00' }, quota_published_at: '2026-10-08T09:00:00+00:00' })
    broadcast(`region-day.3.${DAY}:.QuotaPublished`, { region_id: 3, day: DAY, version: 2, first: false })
    await waitFor(() => expect(within(regionCard('Centre')).getByTestId('region-cutoffs')).toBeInTheDocument())
  })

  it('rereads the dashboard when a cutoff extension is notified to the user', async () => {
    let validation = '15:00'
    const { requests } = renderMyDay({
      user: live(),
      routes: { [DASHBOARD_URL]: () => json(dashboard({ regions: [region({ cutoffs: { distribution: '10:00', ordering: '13:30', validation }, extended: { distribution: false, ordering: true, validation: validation !== '15:00' } })] })) },
    })
    await findCard()
    await waitFor(() => expect(fake.listeners.get(`user.7:.${NOTIFICATION_EVENT}`)?.size).toBeGreaterThan(0))

    // Une notification qui ne concerne pas la journée ne relit rien.
    broadcast(`user.7:.${NOTIFICATION_EVENT}`, { id: 'n-1', type: 'ORDER_SUBMITTED', title: 'Nouvelle commande', body: '', url: '/regional/commandes', read_at: null, created_at: '2026-10-08T08:20:00+00:00' })
    expect(requests(DASHBOARD_URL)).toHaveLength(1)

    validation = '15:30'
    broadcast(`user.7:.${NOTIFICATION_EVENT}`, {
      id: 'n-2',
      type: 'CUTOFF_EXTENDED',
      code: null,
      title: 'Heure limite de validation prolongée',
      body: 'Validez jusqu’à 15:30.',
      url: '/regional/commandes',
      read_at: null,
      created_at: '2026-10-08T14:20:00+00:00',
    })
    await waitFor(() => expect(within(regionCard('Centre')).getByTestId('region-cutoffs')).toHaveTextContent('Validation avant 15:30 (prolongée)'))
    expect(requests(DASHBOARD_URL)).toHaveLength(2)
  })

  it('refreshes every 30 s without real time, and stops once connected', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderMyDay({ user: live() })
    await findCard()
    await waitFor(() => expect(fake.stateChange).not.toBeNull())
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(requests(DASHBOARD_URL)).toHaveLength(2))

    act(() => fake.stateChange?.({ current: 'connected' }))
    await act(() => vi.advanceTimersByTimeAsync(65_000))
    expect(requests(DASHBOARD_URL)).toHaveLength(2)
  })

  it('keeps only the channels of the day shown', async () => {
    renderMyDay({ user: live(), path: '/regional?day=2026-10-09', routes: { [DASHBOARD_URL]: ({ url }) => json(dashboard({ day: url.searchParams.get('day') ?? DAY })) } })
    await findCard()
    await waitFor(() => expect(fake.listeners.get('region-day.3.2026-10-09:.OrderSubmitted')?.size).toBe(1))
    expect(fake.listeners.get(`region-day.3.${DAY}:.OrderSubmitted`)?.size ?? 0).toBe(0)
  })
})

describe('Ma journée — application', () => {
  it('is the home of the regional, in the menu as « Ma journée »', async () => {
    const noNotifications: NotificationsPage = {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, from: null, to: null, unread_count: 0 },
    }
    mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      'GET /api/v1/notifications': () => json(noNotifications),
      [DASHBOARD_URL]: () => json(dashboard({ day: businessToday() })),
    })
    renderApp('/regional')

    expect(await screen.findByRole('heading', { level: 1, name: 'Ma journée' }, { timeout: 5000 })).toBeInTheDocument()
    expect(await findCard()).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Ma journée' }).every((link) => link.getAttribute('href') === '/regional')).toBe(true)
  })
})
