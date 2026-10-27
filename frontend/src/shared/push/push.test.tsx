import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import type { CurrentUser } from '../api/types'
import { meQueryKey } from '../auth/session'
import { json, makeUser, mockApi, problem } from '../../test/utils'
import { PUSH_OWNER_KEY, PushError } from './device'
import { base64UrlToBytes } from './encoding'
import { inviteKey } from './invite'
import { PushInvite } from './PushInvite'
import { PushSync } from './PushSync'
import { asIphone, ENDPOINT, installPush, OTHER_KEY, PUBLIC_KEY, uninstallPush } from './testing'
import { usePushState } from './usePushState'

const USER_ID = 5
const withPush = (overrides: Partial<CurrentUser> = {}) => makeUser('client', { id: USER_ID, client_id: 12, push: { public_key: PUBLIC_KEY }, ...overrides })

const SUBSCRIPTION_BODY = { endpoint: ENDPOINT, keys: { p256dh: 'BN-p256dh', auth: 'k-auth' }, content_encoding: 'aes128gcm' }

let restoreUserAgent: (() => void) | null = null

afterEach(() => {
  uninstallPush()
  restoreUserAgent?.()
  restoreUserAgent = null
  Reflect.deleteProperty(navigator, 'onLine')
})

function api(routes: Parameters<typeof mockApi>[0] = {}) {
  return mockApi({
    'POST /api/v1/push-subscriptions': ({ body }) => json({ endpoint: (body as { endpoint: string }).endpoint, created_at: '2026-10-08T09:00:00+00:00' }, 201),
    'DELETE /api/v1/push-subscriptions': () => new Response(null, { status: 204 }),
    ...routes,
  })
}

function renderWith(ui: ReactNode, user: CurrentUser = withPush()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(meQueryKey, user)
  const rendered = render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
  return { ...rendered, queryClient }
}

function Probe() {
  const push = usePushState()
  return (
    <div>
      <p data-testid="status">{push.status}</p>
      <p data-testid="busy">{String(push.busy)}</p>
      <p data-testid="error">{push.error instanceof PushError ? push.error.code : push.error ? 'api' : ''}</p>
      <button type="button" onClick={() => void push.enable()}>
        activer
      </button>
      <button type="button" onClick={() => void push.disable()}>
        désactiver
      </button>
    </div>
  )
}

const status = () => screen.getByTestId('status')
const ownedBy = (userId: number) => localStorage.setItem(PUSH_OWNER_KEY, JSON.stringify(userId))

describe('push — state of this device (contract §8)', () => {
  it('is unavailable when the server has no push (null), even in a capable browser', () => {
    installPush()
    renderWith(<Probe />, withPush({ push: null }))
    expect(status()).toHaveTextContent('unavailable')
  })

  it('asks for the installation on an iPhone outside of the installed application', () => {
    installPush()
    restoreUserAgent = asIphone()
    renderWith(<Probe />)
    expect(status()).toHaveTextContent('ios-install')
  })

  it('is unsupported without service worker or Push API', () => {
    renderWith(<Probe />)
    expect(status()).toHaveTextContent('unsupported')
  })

  it('is denied when the permission was refused', () => {
    installPush({ permission: 'denied' })
    renderWith(<Probe />)
    expect(status()).toHaveTextContent('denied')
  })

  it('reads the subscription of the browser: off, then on once enabled', async () => {
    const { pushManager, requestPermission } = installPush()
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<Probe />)

    expect(status()).toHaveTextContent('checking')
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(status()).toHaveTextContent('on'))
    expect(requestPermission).toHaveBeenCalledTimes(1)
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1)
    const options = pushManager.subscribe.mock.calls[0]![0]
    expect(options.userVisibleOnly).toBe(true)
    expect(Array.from(options.applicationServerKey)).toEqual(Array.from(base64UrlToBytes(PUBLIC_KEY)))
    const posts = calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/push-subscriptions')
    expect(posts.map((call) => call.body)).toEqual([SUBSCRIPTION_BODY])
    expect(localStorage.getItem(PUSH_OWNER_KEY)).toBe(String(USER_ID))
    expect(screen.getByTestId('busy')).toHaveTextContent('false')
  })

  it('sends aesgcm to a browser that only knows it', async () => {
    installPush({ encodings: ['aesgcm'] })
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(status()).toHaveTextContent('on'))
    expect(calls.find((call) => call.path === '/api/v1/push-subscriptions')?.body).toEqual({ ...SUBSCRIPTION_BODY, content_encoding: 'aesgcm' })
  })

  it('becomes denied when the user refuses, without subscribing', async () => {
    const { pushManager } = installPush({ answer: 'denied' })
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(status()).toHaveTextContent('denied'))
    expect(pushManager.subscribe).not.toHaveBeenCalled()
    expect(calls.some((call) => call.path === '/api/v1/push-subscriptions')).toBe(false)
  })

  it('stays off when the question is closed without an answer', async () => {
    installPush({ answer: 'default' })
    api()
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('dismissed'))
    expect(status()).toHaveTextContent('off')
  })

  it('removes the subscription of the browser when the server refuses it', async () => {
    const { state } = installPush()
    api({ 'POST /api/v1/push-subscriptions': () => problem(422, 'VALIDATION_FAILED', 'Adresse refusée.') })
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('api'))
    expect(status()).toHaveTextContent('off')
    expect(state.subscription).toBeNull()
    expect(localStorage.getItem(PUSH_OWNER_KEY)).toBeNull()
  })

  it('reports a browser that cannot subscribe', async () => {
    installPush({ subscribeFails: true })
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('off'))

    await user.click(screen.getByRole('button', { name: 'activer' }))

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('subscribe'))
    expect(calls.some((call) => call.path === '/api/v1/push-subscriptions')).toBe(false)
  })

  it('disables this device: DELETE with the endpoint, then unsubscribes the browser', async () => {
    const { state } = installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(USER_ID)
    const subscription = state.subscription!
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('on'))

    await user.click(screen.getByRole('button', { name: 'désactiver' }))

    await waitFor(() => expect(status()).toHaveTextContent('off'))
    const deletes = calls.filter((call) => call.method === 'DELETE')
    expect(deletes.map((call) => [call.path, call.body])).toEqual([['/api/v1/push-subscriptions', { endpoint: ENDPOINT }]])
    expect(subscription.unsubscribe).toHaveBeenCalledTimes(1)
    expect(localStorage.getItem(PUSH_OWNER_KEY)).toBeNull()
  })

  it('stays on when the server cannot be reached to disable', async () => {
    const { state } = installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(USER_ID)
    api({ 'DELETE /api/v1/push-subscriptions': () => problem(500, 'SERVER_ERROR', 'Erreur du serveur.') })
    const user = userEvent.setup()
    renderWith(<Probe />)
    await waitFor(() => expect(status()).toHaveTextContent('on'))

    await user.click(screen.getByRole('button', { name: 'désactiver' }))

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('api'))
    expect(status()).toHaveTextContent('on')
    expect(state.subscription?.unsubscribe).not.toHaveBeenCalled()
  })

  it('never takes over the subscription of another account on a shared device', async () => {
    const { state } = installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(99)
    const subscription = state.subscription!
    renderWith(<Probe />)

    await waitFor(() => expect(status()).toHaveTextContent('off'))
    expect(subscription.unsubscribe).toHaveBeenCalled()
  })
})

describe('push — resent at each opening (contract §2.2)', () => {
  it('posts the existing subscription once the user is loaded', async () => {
    installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(USER_ID)
    const { calls } = api()
    const { rerender, queryClient } = renderWith(<PushSync />)

    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/push-subscriptions')).toHaveLength(1))
    expect(calls[calls.length - 1]).toMatchObject({ method: 'POST', body: SUBSCRIPTION_BODY })

    // Nouveau rendu de la coquille : pas de nouvel envoi pendant cette ouverture.
    rerender(
      <QueryClientProvider client={queryClient}>
        <PushSync />
      </QueryClientProvider>,
    )
    await act(() => Promise.resolve())
    expect(calls.filter((call) => call.path === '/api/v1/push-subscriptions')).toHaveLength(1)
  })

  it('does nothing when push is disabled on the server', async () => {
    const { pushManager } = installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(USER_ID)
    const { calls } = api()
    renderWith(<PushSync />, withPush({ push: null }))

    await act(() => Promise.resolve())
    expect(pushManager.getSubscription).not.toHaveBeenCalled()
    expect(calls).toHaveLength(0)
  })

  it('does nothing for an account that has not enabled push on this device', async () => {
    const { pushManager } = installPush({ permission: 'granted' })
    const { calls } = api()
    renderWith(<PushSync />)

    await waitFor(() => expect(pushManager.getSubscription).toHaveBeenCalled())
    expect(pushManager.subscribe).not.toHaveBeenCalled()
    expect(calls).toHaveLength(0)
  })

  it('waits for the network', async () => {
    installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(USER_ID)
    let online = false
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => online })
    const { calls } = api()
    renderWith(<PushSync />)

    await act(() => Promise.resolve())
    expect(calls).toHaveLength(0)

    online = true
    act(() => {
      window.dispatchEvent(new Event('online'))
    })
    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/push-subscriptions')).toHaveLength(1))
  })

  it('subscribes again when the browser lost the subscription, then posts it', async () => {
    const { pushManager } = installPush({ permission: 'granted' })
    ownedBy(USER_ID)
    const { calls } = api()
    renderWith(<PushSync />)

    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/push-subscriptions')).toHaveLength(1))
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1)
  })

  it('subscribes again with the new public key after a key change', async () => {
    const { state, pushManager } = installPush({ permission: 'granted', subscribedWith: OTHER_KEY })
    ownedBy(USER_ID)
    const old = state.subscription!
    const { calls } = api()
    renderWith(<PushSync />)

    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/push-subscriptions')).toHaveLength(1))
    expect(old.unsubscribe).toHaveBeenCalled()
    expect(Array.from(pushManager.subscribe.mock.calls[0]![0].applicationServerKey)).toEqual(Array.from(base64UrlToBytes(PUBLIC_KEY)))
    expect(calls[calls.length - 1]!.body).toEqual({ ...SUBSCRIPTION_BODY, endpoint: `${ENDPOINT}-2` })
  })

  it('removes the subscription of another account instead of posting it', async () => {
    const { state } = installPush({ permission: 'granted', subscribedWith: PUBLIC_KEY })
    ownedBy(99)
    const subscription = state.subscription!
    const { calls } = api()
    renderWith(<PushSync />)

    await waitFor(() => expect(subscription.unsubscribe).toHaveBeenCalled())
    expect(calls).toHaveLength(0)
    expect(localStorage.getItem(PUSH_OWNER_KEY)).toBeNull()
  })
})

describe('push — invitation on the home screen (contract §8)', () => {
  const invite = () => screen.queryByRole('heading', { name: 'Recevez vos notifications' })

  it('invites once push is available and the permission not yet asked', async () => {
    installPush()
    renderWith(<PushInvite />)

    expect(await screen.findByRole('heading', { name: 'Recevez vos notifications' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Activer' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Plus tard' })).toBeEnabled()
  })

  it('« Plus tard » dismisses it for this account on this device', async () => {
    installPush()
    const user = userEvent.setup()
    const { unmount } = renderWith(<PushInvite />)

    await user.click(await screen.findByRole('button', { name: 'Plus tard' }))

    expect(invite()).not.toBeInTheDocument()
    expect(localStorage.getItem(inviteKey(USER_ID))).not.toBeNull()
    unmount()

    renderWith(<PushInvite />)
    await act(() => Promise.resolve())
    expect(invite()).not.toBeInTheDocument()
    // Un autre compte sur le même appareil est invité.
    renderWith(<PushInvite />, withPush({ id: 6 }))
    expect(await screen.findByRole('heading', { name: 'Recevez vos notifications' })).toBeInTheDocument()
  })

  it('« Activer » enables the notifications and hides the invitation', async () => {
    installPush()
    const { calls } = api()
    const user = userEvent.setup()
    renderWith(<PushInvite />)

    await user.click(await screen.findByRole('button', { name: 'Activer' }))

    await waitFor(() => expect(invite()).not.toBeInTheDocument())
    expect(calls.find((call) => call.path === '/api/v1/push-subscriptions')?.body).toEqual(SUBSCRIPTION_BODY)
  })

  it('keeps the invitation with the reason when the activation fails', async () => {
    installPush()
    api({ 'POST /api/v1/push-subscriptions': () => problem(503, 'SERVER_ERROR', 'Service indisponible.') })
    const user = userEvent.setup()
    renderWith(<PushInvite />)

    await user.click(await screen.findByRole('button', { name: 'Activer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Activation impossible : Service indisponible.')
    expect(invite()).toBeInTheDocument()
  })

  it('does not come back when the question of the browser is closed without an answer', async () => {
    installPush({ answer: 'default' })
    api()
    const user = userEvent.setup()
    renderWith(<PushInvite />)

    await user.click(await screen.findByRole('button', { name: 'Activer' }))

    await waitFor(() => expect(invite()).not.toBeInTheDocument())
    expect(localStorage.getItem(inviteKey(USER_ID))).not.toBeNull()
  })

  it.each([
    ['push is disabled on the server', () => installPush(), withPush({ push: null })],
    ['the permission was already granted', () => installPush({ permission: 'granted' }), withPush()],
    ['the permission was refused', () => installPush({ permission: 'denied' }), withPush()],
    ['the browser has no push', () => undefined, withPush()],
    ['it was dismissed before', () => (installPush(), localStorage.setItem(inviteKey(USER_ID), '{"dismissed_at":"2026-10-08T09:00:00Z"}')), withPush()],
  ])('is not shown when %s', async (_, setup, account) => {
    setup()
    renderWith(<PushInvite />, account)
    await act(() => Promise.resolve())
    await act(() => Promise.resolve())
    expect(invite()).not.toBeInTheDocument()
  })

  it('is not shown on an iPhone outside of the installed application', async () => {
    installPush()
    restoreUserAgent = asIphone()
    renderWith(<PushInvite />)
    await act(() => Promise.resolve())
    expect(invite()).not.toBeInTheDocument()
  })
})
