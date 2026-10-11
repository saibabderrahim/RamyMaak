import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AppNotification, CurrentUser, NotificationsPage, Role } from '../api/types'
import { createQueryClient } from '../api/queryClient'
import { meQueryKey } from '../auth/session'
import { disconnectRealtime, NOTIFICATION_EVENT } from '../realtime'
import { json, makeUser, mockApi, problem, renderApp } from '../../test/utils'
import { NotificationBell } from '.'

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

// Premier rendu d'un module : chargé à la demande.
const slow = { timeout: 5000 }

function unreadPage(unread: number, data: AppNotification[] = []): NotificationsPage {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 1, total: data.length, from: data.length ? 1 : null, to: data.length || null, unread_count: unread },
  }
}

/** Cloche seule, sous un routeur, pour un utilisateur connecté. */
function renderBell(user: CurrentUser) {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <NotificationBell />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function countApi(user: CurrentUser, unread: () => number) {
  return mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () => json(unreadPage(unread())),
  })
}

const countRequests = (calls: ReturnType<typeof mockApi>['calls']) => calls.filter((call) => call.path === '/api/v1/notifications').length

describe('notification bell', () => {
  it.each<[Role, string]>([
    ['client', '/client'],
    ['regional', '/regional'],
    ['adv', '/adv'],
    ['admin', '/notifications'],
  ])('sits in the header of the %s space with the unread count', async (role, path) => {
    const { fetchMock } = countApi(makeUser(role), () => 3)

    renderApp(path)

    const bell = await screen.findByRole('link', { name: 'Notifications (3 non lues)' }, slow)
    expect(bell).toHaveAttribute('href', '/notifications')
    // En-tête de l'application (le premier ; les cartes de la page d'accueil ont aussi un <header>).
    expect(bell.closest('header')).toBe(screen.getAllByRole('banner')[0])
    expect(within(bell).getByTestId('notification-badge')).toHaveTextContent('3')

    // Une page d'un seul élément suffit : seul `meta.unread_count` est lu.
    const url = fetchMock.mock.calls.map(([input]) => new URL(String(input), 'http://localhost')).find((u) => u.pathname === '/api/v1/notifications')
    expect(url?.searchParams.get('per_page')).toBe('1')
  })

  it('uses the singular and caps the badge at 99+', async () => {
    let unread = 1
    countApi(makeUser('regional'), () => unread)
    const { unmount } = renderBell(makeUser('regional'))
    expect(await screen.findByRole('link', { name: 'Notifications (1 non lue)' })).toBeInTheDocument()
    unmount()

    unread = 120
    renderBell(makeUser('regional'))
    const bell = await screen.findByRole('link', { name: 'Notifications (120 non lues)' })
    expect(within(bell).getByTestId('notification-badge')).toHaveTextContent('99+')
  })

  it('shows no badge when everything is read, nor when the count cannot be loaded', async () => {
    const { calls } = countApi(makeUser('client'), () => 0)
    const { unmount } = renderBell(makeUser('client'))
    await waitFor(() => expect(countRequests(calls)).toBe(1))
    const bell = await screen.findByRole('link', { name: 'Notifications' })
    expect(within(bell).queryByTestId('notification-badge')).not.toBeInTheDocument()
    unmount()

    mockApi({ 'GET /api/v1/notifications': () => problem(503, 'SERVER_ERROR', 'Indisponible') })
    renderBell(makeUser('client'))
    expect(await screen.findByRole('link', { name: 'Notifications' })).toBeInTheDocument()
    expect(screen.queryByTestId('notification-badge')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('refreshes the count on a notification broadcast to the user and announces it', async () => {
    let unread = 1
    const user = makeUser('regional', { id: 7, realtime: { key: 'app-key' } })
    countApi(user, () => unread)
    renderBell(user)
    await screen.findByRole('link', { name: 'Notifications (1 non lue)' })
    await waitFor(() => expect(fake.listeners.get(`user.7:.${NOTIFICATION_EVENT}`)?.size).toBe(1))

    unread = 2
    act(() =>
      fake.listeners.get(`user.7:.${NOTIFICATION_EVENT}`)?.forEach((listener) =>
        listener({ id: 'n-2', type: 'QUOTAS_UPDATED', code: 'N-02', title: 'Quotas du 08/10 modifiés — région Centre', body: 'L’ADV a modifié 3 quotas.' }),
      ),
    )

    expect(await screen.findByRole('link', { name: 'Notifications (2 non lues)' })).toBeInTheDocument()
    expect(screen.getByText('Nouvelle notification : Quotas du 08/10 modifiés — région Centre')).toBeInTheDocument()
  })

  it('polls every 30 s without realtime', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { calls } = countApi(makeUser('client'), () => 0)
    renderBell(makeUser('client'))
    await waitFor(() => expect(countRequests(calls)).toBe(1))

    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(countRequests(calls)).toBe(2))
  })

  it('stops polling while the realtime connection is up', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const user = makeUser('client', { id: 9, realtime: { key: 'app-key' } })
    const { calls } = countApi(user, () => 0)
    renderBell(user)
    await waitFor(() => expect(countRequests(calls)).toBe(1))
    await waitFor(() => expect(fake.stateChange).not.toBeNull())

    act(() => fake.stateChange?.({ current: 'connected' }))
    await act(() => vi.advanceTimersByTimeAsync(65_000))
    expect(countRequests(calls)).toBe(1)

    // Coupure : retour au rafraîchissement de secours.
    act(() => fake.stateChange?.({ current: 'unavailable' }))
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(countRequests(calls)).toBe(2))
  })
})
