import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, problem } from '../../test/utils'
import { createQueryClient } from '../api/queryClient'
import { enqueue, getEntry, OUTBOX_MESSAGE, OUTBOX_SYNC_TAG } from './outbox'
import { OUTBOX_RETRY_MS, OutboxSync } from './OutboxSync'
import { registerBackgroundSync, sendOutboxEntry } from './useOutbox'

// Nouvelles tentatives de la boîte d'envoi dans l'application et Background Sync (lot 8, contrat §6.3, point 2).

const USER = 9
const DAY = '2026-10-08'
const ORDERS = 'POST /api/v1/client/orders'

afterEach(() => {
  vi.restoreAllMocks()
  delete (navigator as { serviceWorker?: unknown }).serviceWorker
})

const waiting = () => enqueue({ user_id: USER, client_id: 12, body: { client_request_id: 'w', day: DAY, comment: null, lines: [{ product_id: 42, quantity: 1 }] } })
const unreachable = () => {
  throw new TypeError('Failed to fetch')
}

/** Service worker simulé : Background Sync et messages vers la page. */
function fakeServiceWorker(sync = true) {
  const register = vi.fn(async () => undefined)
  const target = new EventTarget()
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: Object.assign(target, { ready: Promise.resolve(sync ? { sync: { register } } : {}) }),
  })
  const post = (data: unknown) => target.dispatchEvent(new MessageEvent('message', { data }))
  return { register, post }
}

function renderSync() {
  const queryClient = createQueryClient()
  render(
    <QueryClientProvider client={queryClient}>
      <OutboxSync userId={USER} />
    </QueryClientProvider>,
  )
  return queryClient
}

const posts = (calls: ReturnType<typeof mockApi>['calls']) => calls.filter((call) => `${call.method} ${call.path}` === ORDERS)

describe('outbox retries in the application', () => {
  it('tries at start-up, then every 30 seconds while an order waits', async () => {
    const intervals = vi.spyOn(window, 'setInterval')
    await waiting()
    const { calls } = mockApi({ [ORDERS]: unreachable })

    renderSync()
    await waitFor(() => expect(posts(calls)).toHaveLength(1))
    await waitFor(async () => expect((await getEntry('w'))?.status).toBe('pending'))

    const retry = intervals.mock.calls.find(([, delay]) => delay === OUTBOX_RETRY_MS)?.[0] as (() => void) | undefined
    expect(retry).toBeDefined()
    act(() => retry?.())
    await waitFor(() => expect(posts(calls)).toHaveLength(2))
  })

  it('tries again when the application comes back to the foreground', async () => {
    await waiting()
    const { calls } = mockApi({ [ORDERS]: unreachable })
    renderSync()
    await waitFor(() => expect(posts(calls)).toHaveLength(1))
    await waitFor(async () => expect((await getEntry('w'))?.status).toBe('pending'))

    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    act(() => {
      document.dispatchEvent(new Event('visibilitychange'))
    })
    await waitFor(() => expect(posts(calls)).toHaveLength(2))
  })

  it('reads the outbox and the data of the client again when the service worker has sent an order', async () => {
    const { post } = fakeServiceWorker()
    mockApi({})
    const queryClient = renderSync()
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    act(() => {
      post({ type: 'autre' })
    })
    expect(invalidate).not.toHaveBeenCalled()
    act(() => {
      post({ type: OUTBOX_MESSAGE })
    })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['client'] })
  })
})

describe('Background Sync', () => {
  it('registers the outbox tag when the browser supports it', async () => {
    const { register } = fakeServiceWorker()
    expect(await registerBackgroundSync()).toBe(true)
    expect(register).toHaveBeenCalledWith(OUTBOX_SYNC_TAG)
  })

  it('does without it elsewhere (Safari, Firefox, no service worker)', async () => {
    fakeServiceWorker(false)
    expect(await registerBackgroundSync()).toBe(false)
    delete (navigator as { serviceWorker?: unknown }).serviceWorker
    expect('serviceWorker' in navigator).toBe(false)
    expect(await registerBackgroundSync()).toBe(false)
  })

  it('asks for a background sending when an order could not leave, not for an expired session', async () => {
    const { register } = fakeServiceWorker()
    const queryClient = createQueryClient()
    await waiting()
    let answer: () => Response = unreachable
    mockApi({ [ORDERS]: () => answer() })

    await sendOutboxEntry(queryClient, 'w')
    await waitFor(() => expect(register).toHaveBeenCalledTimes(1))

    answer = () => problem(401, 'UNAUTHENTICATED', 'Session expirée')
    await sendOutboxEntry(queryClient, 'w')
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(register).toHaveBeenCalledTimes(1)
  })
})
