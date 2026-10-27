import { onlineManager, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AppRoutes } from '../../App'
import { createQueryClient } from '../../shared/api/queryClient'
import type { CurrentUser, DeviceSession, Role } from '../../shared/api/types'
import { meQueryKey } from '../../shared/auth/session'
import { db } from '../../shared/offline/db'
import { PUSH_OWNER_KEY } from '../../shared/push'
import { asIphone, ENDPOINT, installPush, PUBLIC_KEY, uninstallPush } from '../../shared/push/testing'
import { json, makeUser, mockApi, problem, renderApp } from '../../test/utils'

// Chargement à la demande du module du profil.
const slow = { timeout: 5000 }

const THIS_DEVICE: DeviceSession = {
  id: 's_3fa9c1d2e4b5',
  device: 'Chrome sur Android',
  ip: '41.200.10.7',
  last_active_at: '2026-10-08T09:12:00+00:00',
  current: true,
  push: true,
  remembered: true,
}
const OTHER_DEVICE: DeviceSession = {
  id: 's_0123456789ab',
  device: 'Edge sur Windows',
  ip: null,
  last_active_at: '2026-10-07T16:45:00+00:00',
  current: false,
  push: false,
  remembered: false,
}

const account = (role: Role = 'client', overrides: Partial<CurrentUser> = {}) =>
  makeUser(role, { id: 5, push: { public_key: PUBLIC_KEY }, ...overrides })

let restoreUserAgent: (() => void) | null = null

afterEach(() => {
  uninstallPush()
  restoreUserAgent?.()
  restoreUserAgent = null
  onlineManager.setOnline(true)
  vi.restoreAllMocks()
})

type Routes = Parameters<typeof mockApi>[0]

function profileApi(user: CurrentUser, routes: Routes = {}, sessions: () => DeviceSession[] = () => [THIS_DEVICE, OTHER_DEVICE]) {
  const mock = mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/auth/sessions': () => json({ data: sessions() }),
    'GET /api/v1/notifications': () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, from: null, to: null, unread_count: 0 } }),
    'POST /api/v1/push-subscriptions': ({ body }) => json({ endpoint: (body as { endpoint: string }).endpoint, created_at: '2026-10-08T09:00:00+00:00' }, 201),
    'DELETE /api/v1/push-subscriptions': () => new Response(null, { status: 204 }),
    ...routes,
  })
  const requests = (route: string) => mock.calls.filter((call) => `${call.method} ${call.path}` === route)
  return { ...mock, requests }
}

async function notificationsCard(): Promise<HTMLElement> {
  return (await screen.findByRole('heading', { level: 2, name: 'Notifications' }, slow)).closest('section')!
}

async function devicesCard(): Promise<HTMLElement> {
  return (await screen.findByRole('heading', { level: 2, name: 'Appareils connectés' }, slow)).closest('section')!
}

describe('profile — notifications of this device (contract §8)', () => {
  it('enables then disables the notifications on this device', async () => {
    installPush()
    const { calls, requests } = profileApi(account())
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await notificationsCard())

    expect(await card.findByText('Désactivées')).toBeInTheDocument()
    expect(card.getByText(/ne sont pas activées sur cet appareil/)).toBeInTheDocument()
    await waitFor(() => expect(requests('GET /api/v1/auth/sessions')).toHaveLength(1))

    await user.click(card.getByRole('button', { name: 'Activer les notifications sur cet appareil' }))

    expect(await card.findByText('Activées')).toBeInTheDocument()
    expect(card.getByText(/Vous recevez les notifications de RamyMaak sur cet appareil/)).toBeInTheDocument()
    // Le bouton touché a disparu : le focus passe à celui qui le remplace, pas au corps de la page.
    await waitFor(() => expect(card.getByRole('button', { name: 'Désactiver sur cet appareil' })).toHaveFocus())
    expect(requests('POST /api/v1/push-subscriptions').map((call) => call.body)).toEqual([
      { endpoint: ENDPOINT, keys: { p256dh: 'BN-p256dh', auth: 'k-auth' }, content_encoding: 'aes128gcm' },
    ])
    // La liste des appareils est relue : celui-ci reçoit désormais les notifications.
    await waitFor(() => expect(requests('GET /api/v1/auth/sessions')).toHaveLength(2))

    await user.click(card.getByRole('button', { name: 'Désactiver sur cet appareil' }))

    expect(await card.findByText('Désactivées')).toBeInTheDocument()
    await waitFor(() => expect(card.getByRole('button', { name: 'Activer les notifications sur cet appareil' })).toHaveFocus())
    expect(requests('DELETE /api/v1/push-subscriptions').map((call) => call.body)).toEqual([{ endpoint: ENDPOINT }])
    expect(localStorage.getItem(PUSH_OWNER_KEY)).toBeNull()
    expect(calls.some((call) => call.method === 'PATCH')).toBe(false)
  })

  it('explains a refused activation', async () => {
    installPush()
    profileApi(account(), { 'POST /api/v1/push-subscriptions': () => problem(429, 'TOO_MANY_REQUESTS', 'Trop de tentatives, réessayez dans une minute.') })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await notificationsCard())
    await user.click(await card.findByRole('button', { name: 'Activer les notifications sur cet appareil' }))

    expect(await card.findByRole('alert')).toHaveTextContent('Activation impossible : Trop de tentatives, réessayez dans une minute.')
    expect(card.getByText('Désactivées')).toBeInTheDocument()
  })

  it('tells how to allow the notifications again once refused', async () => {
    installPush({ permission: 'denied' })
    profileApi(account())

    renderApp('/profil')
    const card = within(await notificationsCard())

    expect(card.getByText('Bloquées')).toBeInTheDocument()
    expect(card.getByText(/bloquées dans les réglages de ce navigateur/)).toBeInTheDocument()
    expect(card.getAllByRole('listitem')).toHaveLength(3)
    expect(card.queryByRole('button', { name: /Activer/ })).not.toBeInTheDocument()
  })

  it('guides the installation on an iPhone (iOS 16.4 or later)', async () => {
    installPush()
    restoreUserAgent = asIphone()
    profileApi(account())

    renderApp('/profil')
    const card = within(await notificationsCard())

    expect(card.getByText(/qu’avec l’application installée \(iOS 16\.4 ou plus récent\)/)).toBeInTheDocument()
    expect(card.getByText(/choisissez « Sur l’écran d’accueil »/)).toBeInTheDocument()
    expect(card.queryByRole('button', { name: /Activer/ })).not.toBeInTheDocument()
  })

  it('says when the browser cannot receive push', async () => {
    profileApi(account())

    renderApp('/profil')
    const card = within(await notificationsCard())

    expect(card.getByText(/Ce navigateur ne reçoit pas les notifications push/)).toBeInTheDocument()
    expect(card.queryByRole('button')).not.toBeInTheDocument()
  })

  it('says when push is not offered by the server', async () => {
    installPush()
    profileApi(account('adv', { push: null }))

    renderApp('/profil')
    const card = within(await notificationsCard())

    expect(card.getByText(/ne sont pas proposées pour le moment/)).toBeInTheDocument()
    expect(card.queryByRole('button')).not.toBeInTheDocument()
  })
})

describe('profile — reminders N-10 to N-12 (contract §4)', () => {
  it('turns the reminders off for a client and keeps the answer of the server', async () => {
    const { requests } = profileApi(account('client'), {
      'PATCH /api/v1/auth/me/preferences': ({ body }) => json(account('client', { preferences: body as { reminders: boolean } })),
    })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await notificationsCard())
    const reminders = card.getByRole('checkbox', { name: 'Recevoir les rappels avant les heures limites' })
    expect(reminders).toBeChecked()
    expect(reminders).toHaveAccessibleDescription(/avant l’heure limite de commande/)

    await user.click(reminders)

    expect(await card.findByText('Préférence enregistrée.')).toBeInTheDocument()
    expect(requests('PATCH /api/v1/auth/me/preferences').map((call) => call.body)).toEqual([{ reminders: false }])
    expect(reminders).not.toBeChecked()
  })

  it('keeps the stored value when the server refuses', async () => {
    profileApi(account('regional', { preferences: { reminders: false } }), {
      'PATCH /api/v1/auth/me/preferences': () => problem(500, 'SERVER_ERROR', 'Erreur du serveur.'),
    })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await notificationsCard())
    const reminders = card.getByRole('checkbox', { name: 'Recevoir les rappels avant les heures limites' })
    expect(reminders).not.toBeChecked()
    expect(reminders).toHaveAccessibleDescription(/heure limite de répartition/)

    await user.click(reminders)

    expect(await card.findByRole('alert')).toHaveTextContent('Erreur du serveur.')
    expect(reminders).not.toBeChecked()
  })

  it.each(['adv', 'admin'] as const)('has no reminders for a %s', async (role) => {
    profileApi(account(role))
    renderApp('/profil')
    const card = within(await notificationsCard())
    expect(card.queryByRole('checkbox')).not.toBeInTheDocument()
  })
})

describe('profile — connected devices (contract §5)', () => {
  it('lists the sessions of the account with this device, the last activity and push', async () => {
    profileApi(account())

    renderApp('/profil')
    const card = within(await devicesCard())
    const items = await card.findAllByRole('listitem')

    expect(items).toHaveLength(2)
    const current = within(items[0]!)
    expect(current.getByText('Chrome sur Android')).toBeInTheDocument()
    expect(current.getByText('Cet appareil')).toBeInTheDocument()
    // Connecté avec « Rester connecté » : il reste dans la liste même inactif (I-113).
    expect(current.getByText('Reste connecté')).toBeInTheDocument()
    expect(current.getByText('Dernière activité : 08/10/2026 10:12')).toBeInTheDocument()
    expect(current.getByText('Adresse IP 41.200.10.7')).toBeInTheDocument()
    expect(current.getByText('Notifications activées')).toBeInTheDocument()
    // La session courante ne se déconnecte pas depuis la liste (I-110).
    expect(current.queryByRole('button')).not.toBeInTheDocument()

    const other = within(items[1]!)
    expect(other.getByText('Dernière activité : 07/10/2026 17:45')).toBeInTheDocument()
    expect(other.queryByText('Cet appareil')).not.toBeInTheDocument()
    expect(other.queryByText('Reste connecté')).not.toBeInTheDocument()
    expect(other.queryByText('Notifications activées')).not.toBeInTheDocument()
    expect(other.getByRole('button', { name: 'Déconnecter Edge sur Windows' })).toBeEnabled()
  })

  it('disconnects another device after confirmation, then rereads the list', async () => {
    let sessions = [THIS_DEVICE, OTHER_DEVICE]
    const { requests } = profileApi(
      account(),
      {
        [`DELETE /api/v1/auth/sessions/${OTHER_DEVICE.id}`]: () => {
          sessions = [THIS_DEVICE]
          return new Response(null, { status: 204 })
        },
      },
      () => sessions,
    )
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(await card.findByRole('button', { name: 'Déconnecter Edge sur Windows' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Déconnecter cet appareil ?' }))
    expect(dialog.getByText(/Le compte sera fermé sur « Edge sur Windows » \(dernière activité : 07\/10\/2026 17:45\)/)).toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Déconnecter' }))

    expect(await card.findByText('« Edge sur Windows » est déconnecté.')).toBeInTheDocument()
    await waitFor(() => expect(card.queryByText('Edge sur Windows')).not.toBeInTheDocument())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(requests(`DELETE /api/v1/auth/sessions/${OTHER_DEVICE.id}`)).toHaveLength(1)
    expect(requests('GET /api/v1/auth/sessions')).toHaveLength(2)
  })

  it('does nothing when the confirmation is cancelled', async () => {
    const { calls } = profileApi(account())
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(await card.findByRole('button', { name: 'Déconnecter Edge sur Windows' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((call) => call.method === 'DELETE')).toBe(false)
  })

  it('shows the refusal of the server in the confirmation', async () => {
    profileApi(account(), {
      [`DELETE /api/v1/auth/sessions/${OTHER_DEVICE.id}`]: () => problem(422, 'CURRENT_SESSION', 'Utilisez « Me déconnecter » pour cet appareil.'),
    })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(await card.findByRole('button', { name: 'Déconnecter Edge sur Windows' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(dialog.getByRole('button', { name: 'Déconnecter' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('Utilisez « Me déconnecter » pour cet appareil.')
  })

  it('treats a device already disconnected (404) as disconnected', async () => {
    let sessions = [THIS_DEVICE, OTHER_DEVICE]
    profileApi(
      account(),
      {
        [`DELETE /api/v1/auth/sessions/${OTHER_DEVICE.id}`]: () => {
          sessions = [THIS_DEVICE]
          return problem(404, 'NOT_FOUND', 'Session introuvable.')
        },
      },
      () => sessions,
    )
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(await card.findByRole('button', { name: 'Déconnecter Edge sur Windows' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Déconnecter' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await waitFor(() => expect(card.queryByText('Edge sur Windows')).not.toBeInTheDocument())
  })

  it('offers to retry when the list cannot be read', async () => {
    let failing = true
    profileApi(account(), {
      'GET /api/v1/auth/sessions': () => (failing ? problem(500, 'SERVER_ERROR', 'Erreur du serveur.') : json({ data: [THIS_DEVICE] })),
    })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    expect(await card.findByText('Erreur du serveur.')).toBeInTheDocument()

    failing = false
    await user.click(card.getByRole('button', { name: 'Réessayer' }))

    expect(await card.findByText('Chrome sur Android')).toBeInTheDocument()
  })

  it('says when no device is connected', async () => {
    profileApi(account(), {}, () => [])
    renderApp('/profil')
    const card = within(await devicesCard())
    expect(await card.findByText('Aucun appareil connecté.')).toBeInTheDocument()
  })

  it('says offline that the list is not saved on the device, instead of loading endlessly', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    onlineManager.setOnline(false)
    const { requests } = profileApi(account())
    // Utilisateur relu du cache de l'appareil, hors ligne.
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, account())
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/profil']}>
          <AppRoutes />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    const card = within(await devicesCard())
    expect(card.getByText('Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.')).toBeInTheDocument()
    expect(card.queryByText('Chargement des appareils…')).not.toBeInTheDocument()
    expect(requests('GET /api/v1/auth/sessions')).toHaveLength(0)
  })

  it('warns that the orders waiting on this device will be removed with all the devices (§6.3, point 7)', async () => {
    await db.outbox.put({
      id: 'w',
      user_id: 5,
      client_id: 12,
      day: '2026-10-08',
      body: { client_request_id: 'w', day: '2026-10-08', comment: null, lines: [{ product_id: 42, quantity: 1 }] },
      created_at: '2026-10-08T07:00:00.000Z',
      status: 'pending',
      attempts: 1,
      error: null,
      order: null,
    })
    profileApi(account())
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(card.getByRole('button', { name: 'Me déconnecter de tous mes appareils' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Me déconnecter de tous mes appareils' }))
    expect(dialog.getByText(/y compris celui-ci/)).toBeInTheDocument()
    expect(dialog.getByText('1 commande en attente d’envoi sera supprimée de cet appareil. Se déconnecter quand même ?')).toBeInTheDocument()
  })

  it('disconnects from all devices after confirmation (EF-AUTH-07)', async () => {
    let session = true
    const { requests } = profileApi(account(), {
      'GET /api/v1/auth/me': () => (session ? json(account()) : problem(401, 'UNAUTHENTICATED', 'Authentification requise')),
      'POST /api/v1/auth/logout-all': () => {
        session = false
        return new Response(null, { status: 204 })
      },
    })
    const user = userEvent.setup()

    renderApp('/profil')
    const card = within(await devicesCard())
    await user.click(card.getByRole('button', { name: 'Me déconnecter de tous mes appareils' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Me déconnecter de tous mes appareils' }))
    expect(dialog.getByText(/y compris celui-ci/)).toBeInTheDocument()
    // Aucune commande en attente d'envoi : rien à annoncer.
    expect(dialog.queryByText(/en attente d’envoi/)).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Tout déconnecter' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'))
    expect(requests('POST /api/v1/auth/logout-all')).toHaveLength(1)
  })
})
