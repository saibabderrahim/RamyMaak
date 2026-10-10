import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Client, CurrentUser, Paginated } from '../../../shared/api/types'
import { businessToday, formatDate } from '../../../shared/format'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'

// Premier rendu : le module du rôle est chargé à la demande.
const slow = { timeout: 5000 }

const tomorrow = (() => {
  const day = new Date(`${businessToday()}T00:00:00Z`)
  day.setUTCDate(day.getUTCDate() + 1)
  return day.toISOString().slice(0, 10)
})()

const centreRef = { id: 3, code: 'CENTRE', name: 'Centre' }
const estRef = { id: 4, code: 'EST', name: 'Est' }

/** Fiche sans clé `accounts` : celle-ci n'est fournie qu'à qui peut réinitialiser un mot de passe client. */
const baraka: Client = {
  id: 12,
  code: 'C0012',
  name: 'Superette El Baraka',
  trade_name: 'El Baraka',
  category: { id: 3, code: 'DETAILLANT', name: 'Détaillant' },
  region: centreRef,
  pending_region_change: null,
  address: '12 rue des Oliviers',
  wilaya: 'Alger',
  commune: 'Bab Ezzouar',
  lat: 36.7212345,
  lng: 3.1812345,
  phone: '0550123456',
  phone2: '0770 11 22 33',
  email: 'contact@elbaraka.dz',
  nif: '000016001234567',
  nis: null,
  rc: null,
  ai: null,
  status: 'ACTIVE',
  status_label: 'Actif',
  status_reason: null,
  status_changed_at: null,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
}

const benali: Client = {
  ...baraka,
  id: 13,
  code: 'C0013',
  name: 'Grossiste Benali',
  trade_name: null,
  category: null,
  pending_region_change: { region: estRef, effective_on: tomorrow },
  address: null,
  wilaya: null,
  commune: 'Blida',
  lat: null,
  lng: null,
  phone: null,
  phone2: '0661 98 76 54',
  email: null,
  nif: null,
  status: 'BLOCKED',
  status_label: 'Bloqué',
  status_reason: 'Impayés',
  status_changed_at: '2026-10-05T09:00:00+00:00',
}

const mansouri: Client = { ...baraka, id: 14, code: 'C0014', name: 'Alimentation Mansouri', trade_name: null, phone: null, phone2: null }

const page = (data: Client[], current = 1, last = 1, total = data.length): Paginated<Client> => ({
  data,
  meta: { current_page: current, last_page: last, per_page: 100, total, from: data.length ? 1 : null, to: data.length || null },
})

const regional = (overrides: Partial<CurrentUser> = {}) =>
  makeUser('regional', {
    name: 'Karim Saïdi',
    permissions: ['region.view', 'client.view', 'product.view'],
    perimeter: { all_regions: false, region_ids: [3] },
    ...overrides,
  })

/** Liste simulée : la recherche filtre sur le nom, le code et l'enseigne. */
function clientsApi(clients: Client[]) {
  const requests: URLSearchParams[] = []
  const routes = {
    'GET /api/v1/clients': ({ url }: { url: URL }) => {
      requests.push(url.searchParams)
      const search = (url.searchParams.get('filter[search]') ?? '').toLowerCase()
      return json(page(clients.filter((c) => [c.code, c.name, c.trade_name ?? ''].some((v) => v.toLowerCase().includes(search)))))
    },
  }
  return { routes, requests }
}

describe('regional — my clients', () => {
  it('lists the perimeter clients with a one-gesture call, the status and the pending region change', async () => {
    const { routes, requests } = clientsApi([benali, baraka, mansouri])
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), ...routes })

    renderApp('/regional/clients')

    const list = await screen.findByRole('list', { name: 'Mes clients' }, slow)
    expect(screen.getByText('3 clients')).toBeInTheDocument()
    const [blocked, active, noPhone] = within(list).getAllByRole('listitem')

    // Client bloqué : clairement signalé, appel sur le second numéro (espaces retirés).
    expect(within(blocked!).getByText('Bloqué')).toBeInTheDocument()
    expect(within(blocked!).getByText(`Région Est à partir du ${formatDate(tomorrow)}`)).toBeInTheDocument()
    expect(within(blocked!).getByRole('link', { name: 'Appeler Grossiste Benali au 0661 98 76 54' })).toHaveAttribute('href', 'tel:0661987654')

    // Fiche : raison sociale, enseigne, code, commune ; appel en un geste.
    const fiche = within(active!).getByRole('link', { name: /^Superette El Baraka/ })
    expect(fiche).toHaveAttribute('href', '/regional/clients/12')
    expect(fiche).toHaveTextContent('El Baraka')
    expect(fiche).toHaveTextContent('C0012 · Bab Ezzouar')
    expect(within(active!).getByText('Actif')).toBeInTheDocument()
    expect(within(active!).getByRole('link', { name: 'Appeler Superette El Baraka au 0550123456' })).toHaveAttribute('href', 'tel:0550123456')

    // Sans numéro : pas de lien d'appel.
    expect(within(noPhone!).queryByRole('link', { name: /^Appeler/ })).not.toBeInTheDocument()
    expect(within(noPhone!).getByText('Pas de téléphone')).toBeInTheDocument()

    // Une page de 100, triée par raison sociale, sans filtre.
    expect(requests[0]?.get('per_page')).toBe('100')
    expect(requests[0]?.get('sort')).toBe('name')
    expect(requests[0]?.has('filter[search]')).toBe(false)
  })

  it('searches as the user types (debounced) and says when nothing matches', async () => {
    const { routes, requests } = clientsApi([benali, baraka])
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), ...routes })
    const user = userEvent.setup()

    renderApp('/regional/clients')
    await screen.findByRole('list', { name: 'Mes clients' }, slow)
    await user.type(screen.getByLabelText('Rechercher un client'), 'baraka')

    await waitFor(() => expect(within(screen.getByRole('list', { name: 'Mes clients' })).getAllByRole('listitem')).toHaveLength(1))
    expect(screen.getByText('1 client')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /^Superette El Baraka/ })).toBeInTheDocument()
    // Recherche différée : une seule requête filtrée, pas une par touche.
    const searches = requests.map((params) => params.get('filter[search]')).filter(Boolean)
    expect(searches).toEqual(['baraka'])
    expect(requests.at(-1)?.get('per_page')).toBe('100')

    await user.clear(screen.getByLabelText('Rechercher un client'))
    await user.type(screen.getByLabelText('Rechercher un client'), 'zzz')
    expect(await screen.findByText('Aucun client ne correspond à « zzz ».')).toBeInTheDocument()
  })

  it('loads the next page on demand', async () => {
    const requests: URLSearchParams[] = []
    mockApi({
      'GET /api/v1/auth/me': () => json(regional({ perimeter: { all_regions: false, region_ids: [3, 4] } })),
      'GET /api/v1/clients': ({ url }) => {
        requests.push(url.searchParams)
        return json(url.searchParams.get('page') === '2' ? page([mansouri], 2, 2, 3) : page([benali, baraka], 1, 2, 3))
      },
    })
    const user = userEvent.setup()

    renderApp('/regional/clients')
    expect(await screen.findByText('2 affichés sur 3', undefined, slow)).toBeInTheDocument()
    // Plusieurs régions : la région de chaque client est rappelée.
    expect(screen.getByRole('link', { name: /^Superette El Baraka/ })).toHaveTextContent('C0012 · Bab Ezzouar · Centre')

    await user.click(screen.getByRole('button', { name: 'Afficher plus de clients' }))

    expect(await screen.findByText('3 clients')).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: 'Mes clients' })).getAllByRole('listitem')).toHaveLength(3)
    expect(requests.at(-1)?.get('page')).toBe('2')
    expect(screen.queryByRole('button', { name: 'Afficher plus de clients' })).not.toBeInTheDocument()
  })

  it('explains the empty list of a regional without a region and does not query the clients', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(regional({ perimeter: { all_regions: false, region_ids: [] } })),
      'GET /api/v1/clients': () => json(page([])),
    })

    renderApp('/regional/clients')

    expect(await screen.findByText(/Aucune région ne vous est affectée aujourd’hui/, undefined, slow)).toBeInTheDocument()
    expect(screen.queryByLabelText('Rechercher un client')).not.toBeInTheDocument()
    expect(calls.some((call) => call.path === '/api/v1/clients')).toBe(false)
  })

  it('says so when the regions have no client', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), 'GET /api/v1/clients': () => json(page([])) })

    renderApp('/regional/clients')

    expect(await screen.findByText('Aucun client actif ou bloqué dans vos régions.', undefined, slow)).toBeInTheDocument()
  })

  it('shows the server error with a retry button', async () => {
    let fail = true
    mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      'GET /api/v1/clients': () => (fail ? problem(500, 'SERVER_ERROR', 'Service indisponible.') : json(page([baraka]))),
    })
    const user = userEvent.setup()

    renderApp('/regional/clients')
    expect(await screen.findByText('Service indisponible.', undefined, slow)).toBeInTheDocument()

    fail = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByRole('link', { name: /^Superette El Baraka/ })).toBeInTheDocument()
  })

  it('opens the fiche from the list', async () => {
    const { routes } = clientsApi([baraka])
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), ...routes, 'GET /api/v1/clients/12': () => json(baraka) })
    const user = userEvent.setup()

    renderApp('/regional/clients')
    await user.click(await screen.findByRole('link', { name: /^Superette El Baraka/ }, slow))

    expect(await screen.findByRole('heading', { name: 'Superette El Baraka' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/regional/clients/12')
  })
})

describe('regional — client fiche', () => {
  it('shows the contact details with a call button per phone, the e-mail and the map', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), 'GET /api/v1/clients/12': () => json(baraka) })

    renderApp('/regional/clients/12')

    expect(await screen.findByRole('heading', { name: 'Superette El Baraka' }, slow)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Appeler Superette El Baraka au 0550123456' })).toHaveAttribute('href', 'tel:0550123456')
    expect(screen.getByRole('link', { name: 'Appeler Superette El Baraka au 0770 11 22 33' })).toHaveAttribute('href', 'tel:0770112233')
    expect(screen.getByRole('link', { name: 'contact@elbaraka.dz' })).toHaveAttribute('href', 'mailto:contact@elbaraka.dz')

    const map = screen.getByRole('link', { name: 'Voir sur la carte' })
    expect(map).toHaveAttribute('href', 'https://www.google.com/maps/search/?api=1&query=36.7212345,3.1812345')
    expect(map).toHaveAttribute('target', '_blank')
    expect(map).toHaveAttribute('rel', 'noopener noreferrer')

    expect(screen.getByText('12 rue des Oliviers')).toBeInTheDocument()
    expect(screen.getByText('Bab Ezzouar, Alger')).toBeInTheDocument()
    expect(screen.getByText('Détaillant')).toBeInTheDocument()
    expect(screen.getByText('Centre')).toBeInTheDocument()
    expect(screen.getByText('000016001234567')).toBeInTheDocument()
    expect(screen.getByText('Actif')).toBeInTheDocument()
    expect(screen.queryByText(/ne peut pas commander/)).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour à mes clients' })).toHaveAttribute('href', '/regional/clients')
  })

  it('lets the regional reset the password of an active client account (EF-AUTH-05)', async () => {
    const withAccounts: Client = {
      ...baraka,
      accounts: [
        { id: 41, username: 'c0012', is_active: true, last_login_at: null },
        { id: 42, username: 'c0012b', is_active: false, last_login_at: null },
      ],
    }
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      'GET /api/v1/clients/12': () => json(withAccounts),
      'POST /api/v1/admin/users/41/reset-password': () => json({ user: { id: 41, username: 'c0012' }, temporary_password: 'Kx7mPq9Rt2' }),
    })
    const user = userEvent.setup()

    renderApp('/regional/clients/12')
    const reset = await screen.findByRole('button', { name: 'Réinitialiser le mot de passe du compte c0012' }, slow)
    // Compte désactivé : pas de réinitialisation.
    expect(screen.queryByRole('button', { name: 'Réinitialiser le mot de passe du compte c0012b' })).not.toBeInTheDocument()

    await user.click(reset)
    expect(screen.getByText(/Le mot de passe actuel de c0012 ne fonctionnera plus/)).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'POST' && call.path.endsWith('/reset-password'))).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Réinitialiser' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Kx7mPq9Rt2')
    expect(screen.getByText('Mot de passe temporaire de c0012')).toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/admin/users/41/reset-password')).toHaveLength(1)
  })

  it('shows no account section to a viewer who cannot reset passwords', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), 'GET /api/v1/clients/12': () => json(baraka) })

    renderApp('/regional/clients/12')

    expect(await screen.findByRole('heading', { name: 'Superette El Baraka' }, slow)).toBeInTheDocument()
    expect(screen.queryByText('Accès à l’application')).not.toBeInTheDocument()
  })

  it('flags a blocked client with its reason and the region change to come', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), 'GET /api/v1/clients/13': () => json(benali) })

    renderApp('/regional/clients/13')

    expect(await screen.findByText('Client bloqué : il ne peut pas commander.', undefined, slow)).toBeInTheDocument()
    expect(screen.getByText('Motif : Impayés')).toBeInTheDocument()
    expect(screen.getByText('Depuis le 05/10/2026')).toBeInTheDocument()
    expect(screen.getByText(`Passera en région Est à partir du ${formatDate(tomorrow)}.`)).toBeInTheDocument()

    // Un seul numéro (le second) ; ni e-mail ni coordonnées GPS.
    expect(screen.getAllByRole('link', { name: /^Appeler/ })).toHaveLength(1)
    expect(screen.getByRole('link', { name: 'Appeler Grossiste Benali au 0661 98 76 54' })).toHaveAttribute('href', 'tel:0661987654')
    expect(screen.queryByRole('link', { name: 'Voir sur la carte' })).not.toBeInTheDocument()
    expect(screen.queryByText('Identifiants fiscaux')).not.toBeInTheDocument()
    expect(screen.getByText('Blida')).toBeInTheDocument()
  })

  it('says when no phone or e-mail is known', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional()), 'GET /api/v1/clients/14': () => json({ ...mansouri, email: null }) })

    renderApp('/regional/clients/14')

    expect(await screen.findByText('Aucun téléphone ni e-mail renseigné.', undefined, slow)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /^Appeler/ })).not.toBeInTheDocument()
  })

  it('answers a client outside the perimeter with a friendly not-found message', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      'GET /api/v1/clients/99': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
    })

    renderApp('/regional/clients/99')

    expect(await screen.findByText('Ce client est introuvable ou ne fait plus partie de vos régions.', undefined, slow)).toBeInTheDocument()
    expect(screen.queryByText('Ressource introuvable.')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour à mes clients' })).toHaveAttribute('href', '/regional/clients')
  })

  it('does not query an invalid identifier', async () => {
    const { calls } = mockApi({ 'GET /api/v1/auth/me': () => json(regional()) })

    renderApp('/regional/clients/abc')

    expect(await screen.findByText('Ce client est introuvable ou ne fait plus partie de vos régions.', undefined, slow)).toBeInTheDocument()
    expect(calls.some((call) => call.path.startsWith('/api/v1/clients'))).toBe(false)
  })
})

describe('profile — my company', () => {
  it('shows the company of a client account, and that ordering is suspended when blocked', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('client', { name: 'Superette El Baraka', client_id: 12 })),
      'GET /api/v1/clients/12': () => json({ ...baraka, status: 'BLOCKED', status_label: 'Bloqué', status_reason: 'Impayés' }),
    })

    renderApp('/profil')

    const card = (await screen.findByRole('heading', { name: 'Mon entreprise' }, slow)).closest('section')!
    expect(await within(card).findByText('Superette El Baraka')).toBeInTheDocument()
    expect(within(card).getByText('El Baraka')).toBeInTheDocument()
    expect(within(card).getByText('C0012')).toBeInTheDocument()
    expect(within(card).getByText('Centre')).toBeInTheDocument()
    expect(within(card).getByText('12 rue des Oliviers')).toBeInTheDocument()
    expect(within(card).getByText('0550123456')).toBeInTheDocument()
    expect(within(card).getByText('0770 11 22 33')).toBeInTheDocument()
    expect(within(card).getByText('Bloqué')).toBeInTheDocument()
    expect(within(card).getByText(/la prise de commande est suspendue/)).toBeInTheDocument()
    // Le motif interne du blocage n'est pas affiché au client.
    expect(within(card).queryByText(/Impayés/)).not.toBeInTheDocument()
    expect(calls.some((call) => call.path === '/api/v1/clients/12')).toBe(true)
  })

  it('shows an active company without the suspension notice', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('client', { client_id: 12 })),
      'GET /api/v1/clients/12': () => json({ ...baraka, pending_region_change: { region: estRef, effective_on: tomorrow } }),
    })

    renderApp('/profil')

    const card = (await screen.findByRole('heading', { name: 'Mon entreprise' }, slow)).closest('section')!
    expect(await within(card).findByText('Actif')).toBeInTheDocument()
    expect(within(card).getByText(`Région Est à partir du ${formatDate(tomorrow)}`)).toBeInTheDocument()
    expect(within(card).queryByText(/suspendue/)).not.toBeInTheDocument()
  })

  it.each([
    ['regional', regional()],
    ['admin', makeUser('admin')],
    ['client without a company', makeUser('client', { client_id: null })],
  ])('has no company card for a %s', async (_label, account) => {
    const { calls } = mockApi({ 'GET /api/v1/auth/me': () => json(account) })

    renderApp('/profil')

    expect(await screen.findByRole('heading', { name: 'Mon compte' }, slow)).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Mon entreprise' })).not.toBeInTheDocument()
    expect(calls.some((call) => call.path.startsWith('/api/v1/clients'))).toBe(false)
  })
})
