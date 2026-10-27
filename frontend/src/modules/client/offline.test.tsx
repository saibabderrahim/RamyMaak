import { dehydrate, onlineManager, QueryClient, QueryClientProvider, type QueryKey } from '@tanstack/react-query'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest'
import { AppRoutes } from '../../App'
import { createQueryClient } from '../../shared/api/queryClient'
import type { CurrentUser } from '../../shared/api/types'
import { meQueryKey, useAuthenticatedUser } from '../../shared/auth/session'
import i18n from '../../shared/i18n'
import { db, type OutboxEntry } from '../../shared/offline/db'
import { getEntry, listEntries, writeSessionUser } from '../../shared/offline/outbox'
import { LAST_USER_KEY, PERSIST_VERSION, queryCacheKey, startQueryPersistence } from '../../shared/offline/persist'
import { purgeSessionStorage } from '../../shared/storage'
import { LocationProbe } from '../../test/LocationProbe'
import { json, mockApi, problem, renderApp } from '../../test/utils'
import { todayKey } from './api'
import { clientUser, DAY, makeOrder, makeToday, ORDER_ID, ORDER_NUMBER, ORDERS_URL, orange, pomme, storedCart, testCart, USER_ID } from './cart/testing'
import { LIST_URL, makePage, makeSummary } from './orders/testing'

// Hors ligne et boîte d'envoi du client dans l'application entière (lot 8, contrat §6.1 et §6.3).

const slow = { timeout: 5000 }

// Compte connecté sur l'appareil (noté par trackAccount au démarrage) : la boîte d'envoi n'envoie que ses commandes.
beforeEach(() => writeSessionUser(USER_ID))
afterEach(() => {
  purgeSessionStorage()
  onlineManager.setOnline(true)
  vi.restoreAllMocks()
})

function goOffline() {
  vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
  onlineManager.setOnline(false)
}

/** Cache enregistré sur l'appareil par une visite précédente du compte (§6.1). */
async function seedCache(user: CurrentUser, queries: Array<[QueryKey, unknown]>) {
  const source = new QueryClient()
  source.setQueryData(meQueryKey, user)
  queries.forEach(([key, data]) => source.setQueryData(key, data))
  await db.kv.put({ key: queryCacheKey(user.id), value: JSON.stringify({ buster: PERSIST_VERSION, timestamp: Date.now(), clientState: dehydrate(source) }) })
  localStorage.setItem(LAST_USER_KEY, String(user.id))
}

/** Démarrage de l'application comme main.tsx : cache relu, puis affichage. */
async function boot(path: string) {
  const queryClient = createQueryClient()
  await startQueryPersistence(queryClient)
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return queryClient
}

const entry = (overrides: Partial<OutboxEntry> & Pick<OutboxEntry, 'id' | 'day'>): OutboxEntry => ({
  user_id: USER_ID,
  client_id: 12,
  body: { client_request_id: overrides.id, day: overrides.day, comment: 'Livrer tôt', lines: [{ product_id: orange.id, quantity: 4 }, { product_id: pomme.id, quantity: 2 }] },
  created_at: new Date('2026-10-08T07:00:00Z').toISOString(),
  status: 'pending',
  attempts: 1,
  error: null,
  order: null,
  sending_at: null,
  sent_at: null,
  ...overrides,
})

describe('offline start-up (§6.1)', () => {
  it('opens on the account and the screens saved on the device, without endless waiting, then checks the session back online', async () => {
    await seedCache(clientUser(), [[todayKey(), makeToday()]])
    goOffline()
    const { calls } = mockApi({ 'GET /api/v1/auth/me': () => problem(401, 'UNAUTHENTICATED', 'Authentification requise') })

    await boot('/client')

    expect(await screen.findByRole('heading', { name: 'Bonjour Gérant C0012' }, slow)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Commande du jeudi 08/10/2026' })).toBeInTheDocument()
    expect(screen.getByText(/^Hors ligne — données de \d\d:\d\d$/)).toBeInTheDocument()
    expect(screen.queryByText('Chargement…')).not.toBeInTheDocument()
    expect(calls.filter((call) => call.path === '/api/v1/auth/me')).toHaveLength(0)

    // Retour du réseau : la session est vérifiée ; expirée, retour à la connexion et cache effacé.
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true)
    act(() => {
      onlineManager.setOnline(true)
      window.dispatchEvent(new Event('online'))
    })
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'), slow)
    await waitFor(async () => expect(await db.kv.get(queryCacheKey(USER_ID))).toBeUndefined())
  })

  it('checks the session again online after a reload, although the saved user is recent: revoked, back to the login', async () => {
    // Session de ce PC révoquée depuis un autre appareil ; l'utilisateur enregistré date de quelques secondes.
    await seedCache(clientUser(), [[todayKey(), makeToday()]])
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => problem(401, 'UNAUTHENTICATED', 'Authentification requise'),
      'GET /api/v1/client/today': () => problem(401, 'UNAUTHENTICATED', 'Authentification requise'),
    })

    await boot('/client')

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'), slow)
    expect(calls.filter((call) => call.path === '/api/v1/auth/me').length).toBeGreaterThan(0)
    await waitFor(async () => expect(await db.kv.get(queryCacheKey(USER_ID))).toBeUndefined())
  })

  it('says offline that nothing is saved on the device when no account was signed in', async () => {
    goOffline()
    mockApi({})

    await boot('/client')

    expect(await screen.findByText('Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.')).toBeInTheDocument()
    expect(screen.queryByText('Chargement…')).not.toBeInTheDocument()
  })

  it('shows the cached user even when its reading fails for lack of network', async () => {
    await seedCache(clientUser(), [[todayKey(), makeToday()]])
    // Réseau annoncé mais injoignable : la relecture échoue, l'utilisateur enregistré reste affiché.
    mockApi({
      'GET /api/v1/auth/me': () => {
        throw new TypeError('Failed to fetch')
      },
      'GET /api/v1/client/today': () => {
        throw new TypeError('Failed to fetch')
      },
    })

    await boot('/client')
    expect(await screen.findByRole('heading', { name: 'Bonjour Gérant C0012' }, slow)).toBeInTheDocument()
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(screen.getByRole('heading', { name: 'Bonjour Gérant C0012' })).toBeInTheDocument()
  })
})

describe('outbox of the client (§6.3)', () => {
  it('lists the orders of the outbox on the home page with their state, and badges the Orders tab', async () => {
    await db.outbox.bulkPut([
      entry({ id: 'p', day: '2026-10-08', created_at: '2026-10-08T07:00:00.000Z' }),
      entry({ id: 'u', day: '2026-10-09', created_at: '2026-10-08T07:01:00.000Z', error: { code: 'UNAUTHENTICATED', detail: '' } }),
      entry({ id: 's', day: '2026-10-10', created_at: '2026-10-08T07:02:00.000Z', status: 'sending', sending_at: new Date().toISOString() }),
      entry({ id: 'o', day: '2026-10-07', created_at: '2026-10-08T07:03:00.000Z', status: 'sent', order: { id: ORDER_ID, number: ORDER_NUMBER }, sent_at: new Date().toISOString() }),
      entry({ id: 'r', day: '2026-10-11', created_at: '2026-10-08T07:04:00.000Z', status: 'refused', error: { code: 'CUTOFF_PASSED', detail: 'L’heure limite de commande (13:00) est passée.' } }),
    ])
    mockApi({
      'GET /api/v1/auth/me': () => json(clientUser()),
      'GET /api/v1/client/today': () => json(makeToday()),
      // Envois relancés à l'ouverture : le réseau ne répond pas.
      [ORDERS_URL]: () => {
        throw new TypeError('Failed to fetch')
      },
    })

    renderApp('/client')

    const block = await screen.findByRole('region', { name: 'Commandes en attente d’envoi' }, slow)
    const row = (name: string) => within(block).getByRole('listitem', { name })
    expect(row('Commande du jeudi 08/10/2026')).toHaveTextContent('En attente d’envoi')
    expect(row('Commande du jeudi 08/10/2026')).toHaveTextContent('2 produits')
    expect(row('Commande du jeudi 08/10/2026')).toHaveTextContent('Elle partira d’elle-même dès que le réseau le permettra.')
    expect(row('Commande du vendredi 09/10/2026')).toHaveTextContent('Reconnectez-vous pour envoyer.')
    expect(row('Commande du samedi 10/10/2026')).toHaveTextContent('Envoi en cours…')
    expect(row('Commande du mercredi 07/10/2026')).toHaveTextContent(`Envoyée — ${ORDER_NUMBER}`)
    expect(within(row('Commande du mercredi 07/10/2026')).getByRole('link', { name: `Voir la commande ${ORDER_NUMBER}` })).toHaveAttribute(
      'href',
      `/client/commandes/${ORDER_ID}`,
    )
    expect(row('Commande du dimanche 11/10/2026')).toHaveTextContent('Refusée : L’heure limite de commande (13:00) est passée.')
    // Pastille de l'onglet « Commandes » : à envoyer, en cours et refusées (4), pas la commande envoyée ; son nom
    // accessible dit ce qu'elle compte.
    expect(screen.getAllByRole('link', { name: 'Commandes (4 commandes à envoyer ou refusées)' }).length).toBeGreaterThan(0)
    expect(screen.queryByText('Commandes (4)')).not.toBeInTheDocument()
  })

  it('removes a refused order after confirmation, and resumes another one in the cart in place of the current cart', async () => {
    const user = userEvent.setup()
    await db.outbox.bulkPut([
      entry({ id: 'r1', day: DAY, status: 'refused', error: { code: 'CUTOFF_PASSED', detail: '' } }),
      entry({ id: 'r2', day: '2026-10-09', status: 'refused', error: { code: 'DAY_NOT_OPEN', detail: 'Journée fermée.' } }),
    ])
    testCart().setQuantity(DAY, pomme.id, 9)
    mockApi({ 'GET /api/v1/auth/me': () => json(clientUser()), 'GET /api/v1/client/today': () => json(makeToday()) })

    renderApp('/client')
    const block = await screen.findByRole('region', { name: 'Commandes en attente d’envoi' }, slow)
    // Sans motif du serveur : le texte de l'application pour ce refus.
    expect(block).toHaveTextContent('Refusée : L’heure limite de commande est dépassée : la commande n’a pas été envoyée.')

    await user.click(within(block).getByRole('button', { name: 'Retirer de cet appareil la commande du vendredi 09/10/2026' }))
    const removal = screen.getByRole('dialog', { name: 'Retirer cette commande ?' })
    expect(removal).toHaveTextContent('La commande refusée du vendredi 09/10/2026 sera retirée de cet appareil.')
    await user.click(within(removal).getByRole('button', { name: 'Retirer' }))
    await waitFor(async () => expect(await getEntry('r2')).toBeUndefined())

    await user.click(within(block).getByRole('button', { name: 'Reprendre dans le panier la commande du jeudi 08/10/2026' }))
    const replace = screen.getByRole('dialog', { name: 'Remplacer le panier ?' })
    expect(replace).toHaveTextContent('Votre panier contient 1 produit : il sera remplacé par la commande refusée.')
    await user.click(within(replace).getByRole('button', { name: 'Remplacer le panier' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/client/panier'), slow)
    expect(storedCart()).toMatchObject({
      day: DAY,
      comment: 'Livrer tôt',
      pending: null,
      lines: [
        { product_id: orange.id, quantity: 4 },
        { product_id: pomme.id, quantity: 2 },
      ],
    })
    expect(await listEntries(USER_ID)).toEqual([])
  })

  it('sends the waiting orders again when the network comes back, then forgets them once « Mes commandes » is read again', async () => {
    await db.outbox.put(entry({ id: 'w', day: DAY }))
    let online = false
    let listed = [makeSummary({ id: 'older', number: 'RMK-261007-00003' })]
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(clientUser()),
      [LIST_URL]: () => json(makePage(listed)),
      [ORDERS_URL]: () => {
        if (!online) throw new TypeError('Failed to fetch')
        listed = [makeSummary(), ...listed]
        return json(makeOrder(), 201)
      },
    })
    const posts = () => calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/client/orders')

    renderApp('/client/commandes')

    const block = await screen.findByRole('region', { name: 'Commandes en attente d’envoi' }, slow)
    await waitFor(() => expect(posts()).toHaveLength(1))
    expect(await within(block).findByText('En attente d’envoi')).toBeInTheDocument()
    await waitFor(async () => expect(await getEntry('w')).toMatchObject({ status: 'pending', attempts: 2 }))

    online = true
    act(() => {
      window.dispatchEvent(new Event('online'))
    })
    await waitFor(() => expect(posts()).toHaveLength(2))
    expect(posts().map((call) => (call.body as { client_request_id: string }).client_request_id)).toEqual(['w', 'w'])
    // Commande relue dans la liste : elle quitte la boîte d'envoi et apparaît parmi les commandes.
    expect(await screen.findByRole('link', { name: new RegExp(ORDER_NUMBER) }, slow)).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Commandes en attente d’envoi' })).not.toBeInTheDocument(), slow)
    expect(await listEntries(USER_ID)).toEqual([])
  })
})

describe('logout with orders waiting (§6.3, point 7)', () => {
  /** Erreurs remontées à la page pendant le test (page blanche : rendu de la coquille tombé). */
  function pageErrors(): unknown[] {
    const errors: unknown[] = []
    const listener = (event: ErrorEvent) => errors.push(event.error)
    window.addEventListener('error', listener)
    onTestFinished(() => window.removeEventListener('error', listener))
    return errors
  }

  it('keeps the last user for a component of the shell drawn again on its own before the guard, at the end of the session', async () => {
    // Composant redessiné par sa propre lecture suivie (boîte d'envoi) après `setQueryData(meQueryKey, null)`.
    function Name() {
      return <p>{useAuthenticatedUser().name}</p>
    }
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, clientUser())
    render(
      <QueryClientProvider client={queryClient}>
        <Name />
      </QueryClientProvider>,
    )
    expect(screen.getByText('Gérant C0012')).toBeInTheDocument()

    // Notifications de TanStack Query différées (notifyManager) : le composant se redessine après.
    await act(async () => {
      queryClient.setQueryData(meQueryKey, null)
      await new Promise((resolve) => setTimeout(resolve, 20))
    })
    expect(screen.getByText('Gérant C0012')).toBeInTheDocument()
  })

  it('asks before logging out, then removes the outbox, the saved data and the drafts of the account from the device', async () => {
    const errors = pageErrors()
    const user = userEvent.setup()
    await db.outbox.bulkPut([entry({ id: 'a', day: DAY }), entry({ id: 'b', day: '2026-10-09' })])
    await db.kv.put({ key: queryCacheKey(USER_ID), value: '{}' })
    localStorage.setItem(`ramymaak:limit-drafts:${USER_ID}:${DAY}:3`, '[]')
    localStorage.setItem(`ramymaak:limit-drafts:77:${DAY}:3`, '[]')
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(clientUser()),
      'GET /api/v1/client/today': () => json(makeToday()),
      [ORDERS_URL]: () => {
        throw new TypeError('Failed to fetch')
      },
      'POST /api/v1/auth/logout': () => new Response(null, { status: 204 }),
    })

    renderApp('/client')
    await screen.findByRole('region', { name: 'Commandes en attente d’envoi' }, slow)

    await user.click(screen.getByRole('button', { name: 'Me déconnecter' }))
    let dialog = screen.getByRole('dialog', { name: 'Se déconnecter ?' })
    expect(dialog).toHaveTextContent('2 commandes en attente d’envoi seront supprimées de cet appareil. Se déconnecter quand même ?')
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(calls.some((call) => call.path === '/api/v1/auth/logout')).toBe(false)
    expect(await listEntries(USER_ID)).toHaveLength(2)

    await user.click(screen.getByRole('button', { name: 'Me déconnecter' }))
    dialog = screen.getByRole('dialog', { name: 'Se déconnecter ?' })
    await user.click(within(dialog).getByRole('button', { name: 'Se déconnecter' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'), slow)
    expect(calls.some((call) => call.method === 'POST' && call.path === '/api/v1/auth/logout')).toBe(true)
    await waitFor(async () => expect(await listEntries(USER_ID)).toEqual([]))
    expect(await db.kv.get(queryCacheKey(USER_ID))).toBeUndefined()
    expect(localStorage.getItem(`ramymaak:limit-drafts:${USER_ID}:${DAY}:3`)).toBeNull()
    // Brouillons d'un autre compte de l'appareil : gardés.
    expect(localStorage.getItem(`ramymaak:limit-drafts:77:${DAY}:3`)).toBe('[]')
    // Boîte d'envoi relue après la fin de session : la coquille ne tombe pas avant d'être retirée par le garde.
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(errors).toEqual([])
  })

  it('says it for a single order, and logs out at once without any order waiting', async () => {
    const errors = pageErrors()
    expect(i18n.t('offline.logout.pending', { count: 1 })).toBe('1 commande en attente d’envoi sera supprimée de cet appareil. Se déconnecter quand même ?')

    const user = userEvent.setup()
    await db.outbox.put(entry({ id: 'r', day: DAY, status: 'refused', error: { code: 'CUTOFF_PASSED', detail: 'Trop tard.' } }))
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(clientUser()),
      'GET /api/v1/client/today': () => json(makeToday()),
      'POST /api/v1/auth/logout': () => new Response(null, { status: 204 }),
    })

    renderApp('/client')
    await screen.findByRole('region', { name: 'Commandes en attente d’envoi' }, slow)
    // Une commande refusée n'attend plus rien : pas de confirmation.
    await user.click(screen.getByRole('button', { name: 'Me déconnecter' }))
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'), slow)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((call) => call.path === '/api/v1/auth/logout')).toBe(true)
    await waitFor(async () => expect(await listEntries(USER_ID)).toEqual([]))
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(errors).toEqual([])
  })
})
