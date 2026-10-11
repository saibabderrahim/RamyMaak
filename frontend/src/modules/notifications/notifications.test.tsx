import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { AppNotification, NotificationsPage, RegionalQuotas } from '../../shared/api/types'
import { internalPath } from '../../shared/notifications'
import { json, makeUser, mockApi, problem, renderApp } from '../../test/utils'

// Premier rendu : la page est chargée à la demande.
const slow = { timeout: 5000 }

const updated: AppNotification = {
  id: 'n-3',
  type: 'QUOTAS_UPDATED',
  code: 'N-02',
  title: 'Quotas du 08/10 modifiés — région Centre',
  body: 'L’ADV a modifié 3 quotas.',
  url: '/regional/quotas?day=2026-10-08',
  read_at: null,
  created_at: '2026-10-08T08:40:00+00:00',
}

const extended: AppNotification = {
  id: 'n-2',
  type: 'CUTOFF_EXTENDED',
  code: null,
  title: 'Heure limite prolongée — région Centre',
  body: 'Répartition jusqu’à 11:15.',
  url: null,
  read_at: null,
  created_at: '2026-10-08T08:20:00+00:00',
}

const received: AppNotification = {
  id: 'n-1',
  type: 'QUOTAS_PUBLISHED',
  code: 'N-01',
  title: 'Quotas du 08/10 reçus — région Centre',
  body: 'À répartir avant 10:00.',
  url: '/regional/quotas?day=2026-10-08',
  read_at: '2026-10-08T07:15:00+00:00',
  created_at: '2026-10-08T07:10:00+00:00',
}

const notOpened = (day: string): RegionalQuotas => ({ day, opened: false, status: null, cutoffs: null, regions: [] })

/**
 * API simulée : notifications de l'utilisateur (plus récentes d'abord),
 * pagination, `filter[unread]`, lecture d'une notification ou de toutes.
 */
function notificationsApi(initial: AppNotification[], options: { readFails?: boolean } = {}) {
  const store = initial.map((notification) => ({ ...notification }))
  const unreadCount = () => store.filter((notification) => notification.read_at === null).length

  return mockApi({
    'GET /api/v1/auth/me': () => json(makeUser('regional')),
    'GET /api/v1/notifications': ({ url }) => {
      const unreadOnly = url.searchParams.get('filter[unread]') === '1'
      const perPage = Number(url.searchParams.get('per_page') ?? 15)
      const page = Number(url.searchParams.get('page') ?? 1)
      const items = store.filter((notification) => !unreadOnly || notification.read_at === null)
      const data = items.slice((page - 1) * perPage, page * perPage)
      const body: NotificationsPage = {
        data,
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(items.length / perPage)),
          per_page: perPage,
          total: items.length,
          from: data.length ? (page - 1) * perPage + 1 : null,
          to: data.length ? (page - 1) * perPage + data.length : null,
          unread_count: unreadCount(),
        },
      }
      return json(body)
    },
    'POST /api/v1/notifications/read-all': () => {
      const count = unreadCount()
      store.forEach((notification) => (notification.read_at ??= '2026-10-08T09:00:00+00:00'))
      return json({ updated: count })
    },
    ...Object.fromEntries(
      store.map((notification) => [
        `POST /api/v1/notifications/${notification.id}/read`,
        () => {
          if (options.readFails) return problem(503, 'SERVER_ERROR', 'Service indisponible.')
          notification.read_at ??= '2026-10-08T09:00:00+00:00'
          return json(notification)
        },
      ]),
    ),
    'GET /api/v1/regional/quotas': ({ url }) => json(notOpened(url.searchParams.get('day') ?? '2026-10-08')),
  })
}

const listItems = () => within(screen.getByRole('list', { name: 'Liste des notifications' })).getAllByRole('listitem')

describe('notification centre', () => {
  it('lists the notifications newest first, unread ones in bold with a dot', async () => {
    const { calls } = notificationsApi([updated, extended, received])

    renderApp('/notifications')

    expect(await screen.findByRole('heading', { name: 'Notifications' }, slow)).toBeInTheDocument()
    await screen.findByRole('list', { name: 'Liste des notifications' })
    const items = listItems()
    expect(items).toHaveLength(3)
    expect(items.map((item) => item.dataset.unread ?? 'read')).toEqual(['true', 'true', 'read'])
    const [first, second, third] = items as [HTMLElement, HTMLElement, HTMLElement]

    // Non lue : en gras, annoncée comme telle, code de la spécification et heure d'Alger.
    expect(within(first).getByText('Quotas du 08/10 modifiés — région Centre')).toHaveClass('font-bold')
    expect(within(first).getByText('Non lue', { exact: false })).toHaveClass('sr-only')
    expect(within(first).getByText('N-02')).toBeInTheDocument()
    expect(within(first).getByText('L’ADV a modifié 3 quotas.')).toBeInTheDocument()
    expect(within(first).getByText('08/10/2026 09:40')).toHaveAttribute('datetime', '2026-10-08T08:40:00+00:00')

    // Sans code (prolongation, RG-A05) : pas de pastille de code.
    expect(within(second).queryByText(/^N-/)).not.toBeInTheDocument()

    // Lue : texte normal.
    expect(within(third).getByText('Quotas du 08/10 reçus — région Centre')).not.toHaveClass('font-bold')
    expect(within(third).queryByText('Non lue', { exact: false })).not.toBeInTheDocument()

    expect(screen.getByText('2 notifications non lues')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Notifications (2 non lues)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Toutes' })).toHaveAttribute('aria-pressed', 'true')

    const listRequest = calls.find((call) => call.path === '/api/v1/notifications' && call.method === 'GET')
    expect(listRequest).toBeDefined()
  })

  it('marks a notification read when it is tapped and opens its screen', async () => {
    const user = userEvent.setup()
    const { calls } = notificationsApi([updated, extended, received])
    renderApp('/notifications')

    const link = await screen.findByRole('link', { name: /Quotas du 08\/10 modifiés — région Centre/ }, slow)
    expect(link).toHaveAttribute('href', '/regional/quotas?day=2026-10-08')
    await user.click(link)

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/regional/quotas'))
    await waitFor(() => expect(calls.some((call) => call.method === 'POST' && call.path === '/api/v1/notifications/n-3/read')).toBe(true))
    expect(await screen.findByRole('link', { name: 'Notifications (1 non lue)' }, slow)).toBeInTheDocument()
  })

  it('marks a notification without a screen read in place', async () => {
    const user = userEvent.setup()
    const { calls } = notificationsApi([updated, extended, received])
    renderApp('/notifications')

    // Sans écran à ouvrir : un bouton, pas un lien.
    const button = await screen.findByRole('button', { name: /Heure limite prolongée — région Centre/ }, slow)
    await user.click(button)

    await waitFor(() => expect(listItems()[1]).not.toHaveAttribute('data-unread'))
    expect(calls.some((call) => call.method === 'POST' && call.path === '/api/v1/notifications/n-2/read')).toBe(true)
    expect(screen.getByTestId('location')).toHaveTextContent('/notifications')
    expect(await screen.findByText('1 notification non lue')).toBeInTheDocument()

    // Une notification lue sans écran n'est plus actionnable.
    expect(screen.queryByRole('button', { name: /Heure limite prolongée/ })).not.toBeInTheDocument()
  })

  it('restores the notification when marking it read fails', async () => {
    const user = userEvent.setup()
    notificationsApi([extended], { readFails: true })
    renderApp('/notifications')

    await user.click(await screen.findByRole('button', { name: /Heure limite prolongée — région Centre/ }, slow))

    expect(await screen.findByText('Service indisponible.')).toBeInTheDocument()
    await waitFor(() => expect(listItems()[0]).toHaveAttribute('data-unread', 'true'))
    expect(screen.getByText('1 notification non lue')).toBeInTheDocument()
  })

  it('marks every notification read', async () => {
    const user = userEvent.setup()
    const { calls } = notificationsApi([updated, extended, received])
    renderApp('/notifications')

    await screen.findByRole('list', { name: 'Liste des notifications' }, slow)
    await user.click(screen.getByRole('button', { name: 'Tout marquer comme lu' }))

    expect(calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/notifications/read-all')).toHaveLength(1)
    await waitFor(() => expect(listItems().filter((item) => item.dataset.unread)).toHaveLength(0))
    expect(await screen.findByText('Toutes vos notifications sont lues.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tout marquer comme lu' })).toBeDisabled()
    expect(await screen.findByRole('link', { name: 'Notifications' })).toBeInTheDocument()
  })

  it('filters the unread notifications', async () => {
    const user = userEvent.setup()
    const { fetchMock } = notificationsApi([updated, extended, received])
    renderApp('/notifications')

    await screen.findByRole('list', { name: 'Liste des notifications' }, slow)
    await user.click(screen.getByRole('button', { name: 'Non lues' }))

    expect(screen.getByRole('button', { name: 'Non lues' })).toHaveAttribute('aria-pressed', 'true')
    await waitFor(() => expect(listItems()).toHaveLength(2))
    const filtered = fetchMock.mock.calls
      .map(([input]) => new URL(String(input), 'http://localhost'))
      .filter((url) => url.pathname === '/api/v1/notifications' && url.searchParams.get('filter[unread]') === '1')
    expect(filtered).not.toHaveLength(0)
    expect(screen.queryByText('Quotas du 08/10 reçus — région Centre')).not.toBeInTheDocument()

    // Tout lu : la liste des non lues est vide.
    await user.click(screen.getByRole('button', { name: 'Tout marquer comme lu' }))
    expect(await screen.findByText('Aucune notification non lue.')).toBeInTheDocument()

    // Retour à toutes les notifications.
    await user.click(screen.getByRole('button', { name: 'Toutes' }))
    await waitFor(() => expect(listItems()).toHaveLength(3))
  })

  it('keeps the unread filter of the address', async () => {
    notificationsApi([updated, extended, received])
    renderApp('/notifications?filtre=non-lues')

    await screen.findByRole('list', { name: 'Liste des notifications' }, slow)
    expect(screen.getByRole('button', { name: 'Non lues' })).toHaveAttribute('aria-pressed', 'true')
    expect(listItems()).toHaveLength(2)
  })

  it('loads the next page with « Afficher plus »', async () => {
    const user = userEvent.setup()
    const many = Array.from({ length: 25 }, (_, index) => ({
      ...received,
      id: `m-${index + 1}`,
      title: `Notification ${index + 1}`,
      created_at: new Date(Date.UTC(2026, 9, 8, 9, 0) - index * 60_000).toISOString(),
    }))
    const { fetchMock } = notificationsApi(many)
    renderApp('/notifications')

    await screen.findByRole('list', { name: 'Liste des notifications' }, slow)
    expect(listItems()).toHaveLength(20)

    await user.click(screen.getByRole('button', { name: 'Afficher plus' }))

    await waitFor(() => expect(listItems()).toHaveLength(25))
    expect(listItems()[24]).toHaveTextContent('Notification 25')
    expect(screen.queryByRole('button', { name: 'Afficher plus' })).not.toBeInTheDocument()
    const pages = fetchMock.mock.calls
      .map(([input]) => new URL(String(input), 'http://localhost'))
      .filter((url) => url.pathname === '/api/v1/notifications' && url.searchParams.get('per_page') === '20')
      .map((url) => url.searchParams.get('page'))
    expect(pages).toEqual(['1', '2'])
  })

  it('shows an empty state', async () => {
    notificationsApi([])
    renderApp('/notifications')

    expect(await screen.findByText('Aucune notification pour le moment.', {}, slow)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tout marquer comme lu' })).toBeDisabled()
    expect(screen.queryByRole('list', { name: 'Liste des notifications' })).not.toBeInTheDocument()
  })

  it('is available to every role, clients included', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('client')),
      'GET /api/v1/notifications': () =>
        json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, unread_count: 0 },
        } satisfies NotificationsPage),
    })
    renderApp('/notifications')

    expect(await screen.findByText('Aucune notification pour le moment.', {}, slow)).toBeInTheDocument()
  })
})

describe('internal path of a notification', () => {
  it('only follows paths of the application', () => {
    expect(internalPath('/regional/quotas?day=2026-10-08')).toBe('/regional/quotas?day=2026-10-08')
    expect(internalPath(null)).toBeNull()
    expect(internalPath('https://example.com/phishing')).toBeNull()
    expect(internalPath('//example.com/phishing')).toBeNull()
    expect(internalPath('/\\example.com')).toBeNull()
    expect(internalPath('javascript:alert(1)')).toBeNull()
  })
})
