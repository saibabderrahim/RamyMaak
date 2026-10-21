import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AppNotification, CurrentUser, NotificationsPage } from '../api/types'
import { disconnectRealtime, NOTIFICATION_EVENT } from '../realtime'
import { json, makeUser, mockApi, renderApp } from '../../test/utils'

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
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
})

// Premier rendu : la page est chargée à la demande.
const slow = { timeout: 5000 }

// Notifications du client (lot 4, §7.8) : titre et corps rédigés par le serveur, écran du client.
const updated: AppNotification = {
  id: 'n-12',
  type: 'LIMITS_UPDATED',
  code: 'N-04',
  title: 'Quotas du 08/10 mis à jour',
  body: 'Vos quotas du jour ont été mis à jour.',
  url: '/client',
  read_at: null,
  created_at: '2026-10-08T09:30:00+00:00',
}

const available: AppNotification = {
  id: 'n-11',
  type: 'DISTRIBUTION_PUBLISHED',
  code: 'N-03',
  title: 'Quotas du 08/10 disponibles',
  body: 'Vos quotas du 08/10 sont disponibles. Commandez avant 13:00.',
  url: '/client',
  read_at: null,
  created_at: '2026-10-08T08:40:00+00:00',
}

/** API simulée : notifications du client, plus récentes d'abord ; lecture d'une notification. */
function clientApi(user: CurrentUser, initial: AppNotification[]) {
  const store = initial.map((notification) => ({ ...notification }))
  const page = (): NotificationsPage => {
    const unread = store.filter((notification) => notification.read_at === null).length
    const range = { from: store.length ? 1 : null, to: store.length || null }
    return { data: store, meta: { current_page: 1, last_page: 1, per_page: 20, total: store.length, ...range, unread_count: unread } }
  }
  return mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () => json(page()),
    ...Object.fromEntries(
      store.map((notification) => [
        `POST /api/v1/notifications/${notification.id}/read`,
        () => {
          notification.read_at ??= '2026-10-08T09:45:00+00:00'
          return json(notification)
        },
      ]),
    ),
  })
}

const client = (overrides: Partial<CurrentUser> = {}) => makeUser('client', { id: 9, client_id: 12, ...overrides })

describe('client notifications of the distribution (N-03, N-04)', () => {
  it('lists them like the others, with their code, and opens the client space', async () => {
    const user = userEvent.setup()
    const { calls } = clientApi(client(), [updated, available])

    renderApp('/notifications')

    await screen.findByRole('list', { name: 'Liste des notifications' }, slow)
    const items = within(screen.getByRole('list', { name: 'Liste des notifications' })).getAllByRole('listitem')
    expect(items).toHaveLength(2)
    const [n04, n03] = items as [HTMLElement, HTMLElement]

    expect(within(n04).getByText('Quotas du 08/10 mis à jour')).toHaveClass('font-bold')
    expect(within(n04).getByText('N-04')).toBeInTheDocument()
    expect(within(n04).getByText('Vos quotas du jour ont été mis à jour.')).toBeInTheDocument()
    expect(within(n03).getByText('N-03')).toBeInTheDocument()
    expect(within(n03).getByText('Vos quotas du 08/10 sont disponibles. Commandez avant 13:00.')).toBeInTheDocument()
    expect(within(n03).getByText('08/10/2026 09:40')).toHaveAttribute('datetime', '2026-10-08T08:40:00+00:00')
    expect(screen.getByText('2 notifications non lues')).toBeInTheDocument()

    const link = within(n03).getByRole('link', { name: /Quotas du 08\/10 disponibles/ })
    expect(link).toHaveAttribute('href', '/client')
    await user.click(link)

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/client$/))
    await waitFor(() => expect(calls.some((call) => call.method === 'POST' && call.path === '/api/v1/notifications/n-11/read')).toBe(true))
    expect(await screen.findByRole('link', { name: 'Notifications (1 non lue)' }, slow)).toBeInTheDocument()
  })

  it('announces an N-03 received in real time on the account of the client', async () => {
    const user = client({ realtime: { key: 'app-key' } })
    let notifications: AppNotification[] = []
    mockApi({
      'GET /api/v1/auth/me': () => json(user),
      'GET /api/v1/notifications': () => {
        const total = notifications.length
        const meta = { current_page: 1, last_page: 1, per_page: 1, total, from: null, to: null, unread_count: total }
        return json({ data: notifications, meta } satisfies NotificationsPage)
      },
    })

    renderApp('/client')

    expect(await screen.findByRole('link', { name: 'Notifications' }, slow)).toBeInTheDocument()
    await waitFor(() => expect(fake.listeners.get(`user.9:.${NOTIFICATION_EVENT}`)?.size).toBeGreaterThan(0))

    notifications = [available]
    act(() => fake.listeners.get(`user.9:.${NOTIFICATION_EVENT}`)?.forEach((listener) => listener(available)))

    expect(await screen.findByRole('link', { name: 'Notifications (1 non lue)' })).toBeInTheDocument()
    expect(screen.getByText('Nouvelle notification : Quotas du 08/10 disponibles')).toBeInTheDocument()
  })
})
