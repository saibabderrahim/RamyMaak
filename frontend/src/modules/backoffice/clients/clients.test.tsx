import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Client, ClientCategory, Paginated, Region } from '../../../shared/api/types'
import { businessToday, formatDate } from '../../../shared/format'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { addDays } from '../shared/dates'

// Premier rendu : le module du rôle est chargé à la demande.
const slow = { timeout: 5000 }

const ADMIN_PERMISSIONS = ['user.manage', 'region.view', 'region.manage', 'client.view', 'client.manage', 'client.block']
const admin = () => makeUser('admin', { permissions: ADMIN_PERMISSIONS })
const adv = () => makeUser('adv', { permissions: ['region.view', 'client.view', 'product.view'] })

const today = businessToday()
const tomorrow = addDays(today, 1)

const centreRef = { id: 3, code: 'CENTRE', name: 'Centre' }
const estRef = { id: 4, code: 'EST', name: 'Est' }
const region = (ref: typeof centreRef, active = true): Region => ({ ...ref, is_active: active, depot: null, titular: null, deputies: [], clients_count: 1 })
const regions = [region(centreRef), region(estRef), region({ id: 5, code: 'SUD', name: 'Sud' }, false)]

const categories: ClientCategory[] = [
  { id: 1, code: 'GROSSISTE', name: 'Grossiste', clients_count: 1 },
  { id: 3, code: 'DETAILLANT', name: 'Détaillant', clients_count: 1 },
]

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
  phone2: null,
  email: null,
  nif: '000016001234567',
  nis: null,
  rc: null,
  ai: null,
  status: 'ACTIVE',
  status_label: 'Actif',
  status_reason: null,
  status_changed_at: null,
  accounts: [{ id: 5, username: 'c0012', is_active: true, last_login_at: null }],
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
}

const benali: Client = {
  ...baraka,
  id: 13,
  code: 'C0013',
  name: 'Grossiste Benali',
  trade_name: null,
  category: { id: 1, code: 'GROSSISTE', name: 'Grossiste' },
  pending_region_change: { region: estRef, effective_on: tomorrow },
  phone: '0661987654',
  status: 'BLOCKED',
  status_label: 'Bloqué',
  status_reason: 'Impayés',
  status_changed_at: '2026-10-05T09:00:00+00:00',
  accounts: [],
}

/** Vue d'un client pour un utilisateur sans user.manage : pas de clé `accounts`. */
const withoutAccounts = ({ accounts: _accounts, ...client }: Client): Client => client

const page = <T,>(data: T[]): Paginated<T> => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
})

const referenceRoutes = {
  'GET /api/v1/regions': () => json(page(regions)),
  'GET /api/v1/client-categories': () => json({ data: categories }),
}

describe('back-office — clients list', () => {
  it('lists clients with their status, pending region change and account', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': () => json(page([baraka, benali])),
    })

    renderApp('/admin/clients')

    const first = (await screen.findByRole('link', { name: 'C0012' }, slow)).closest('tr')!
    expect(first).toHaveTextContent('Superette El Baraka')
    expect(first).toHaveTextContent('El Baraka')
    expect(within(first).getByText('Détaillant')).toBeInTheDocument()
    expect(within(first).getByText('Actif')).toBeInTheDocument()
    expect(within(first).getByText('c0012')).toBeInTheDocument()

    const second = screen.getByRole('link', { name: 'C0013' }).closest('tr')!
    expect(within(second).getByText('Bloqué')).toBeInTheDocument()
    expect(within(second).getByText(`Vers EST le ${formatDate(tomorrow)}`)).toBeInTheDocument()
    expect(within(second).getByText('Aucun')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'C0013' })).toHaveAttribute('href', '/admin/clients/13')
  })

  it('filters by search, region, category and status', async () => {
    const requests: URLSearchParams[] = []
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': ({ url }) => {
        requests.push(url.searchParams)
        return json(page([baraka]))
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/clients')
    await screen.findByRole('link', { name: 'C0012' }, slow)
    expect(requests[0]?.get('sort')).toBe('code')
    expect(requests[0]?.has('filter[region_id]')).toBe(false)

    await screen.findByRole('option', { name: 'EST — Est' })
    await user.selectOptions(screen.getByLabelText('Région'), '4')
    await screen.findByRole('option', { name: 'Grossiste' })
    await user.selectOptions(screen.getByLabelText('Catégorie'), '1')
    await user.selectOptions(screen.getByLabelText('Statut'), 'BLOCKED')
    await user.type(screen.getByLabelText('Rechercher'), '0550 12')

    await waitFor(() => expect(requests.at(-1)?.get('filter[search]')).toBe('0550 12'))
    const last = requests.at(-1)!
    expect(last.get('filter[region_id]')).toBe('4')
    expect(last.get('filter[category_id]')).toBe('1')
    expect(last.get('filter[status]')).toBe('BLOCKED')
    expect(last.get('page')).toBe('1')
  })

  it('pre-filters on the region given in the address', async () => {
    const requests: URLSearchParams[] = []
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': ({ url }) => {
        requests.push(url.searchParams)
        return json(page([baraka]))
      },
    })

    renderApp('/admin/clients?region_id=3')

    await screen.findByRole('link', { name: 'C0012' }, slow)
    expect(requests[0]?.get('filter[region_id]')).toBe('3')
    await screen.findByRole('option', { name: 'CENTRE — Centre' })
    expect(screen.getByLabelText('Région')).toHaveValue('3')
  })

  it('gives the ADV a read-only list: no selection, no creation, no accounts', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/clients': () => json(page([withoutAccounts(baraka), withoutAccounts(benali)])),
    })

    renderApp('/adv/clients')

    expect(await screen.findByRole('link', { name: 'C0012' }, slow)).toHaveAttribute('href', '/adv/clients/12')
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau client' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Créer les comptes/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Compte' })).not.toBeInTheDocument()
  })
})

describe('back-office — access sheets (EF-ADM-02)', () => {
  const downloads: string[] = []
  const blobs: Blob[] = []

  beforeEach(() => {
    downloads.length = 0
    blobs.length = 0
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      writable: true,
      value: vi.fn((blob: Blob) => {
        blobs.push(blob)
        return 'blob:fiches'
      }),
    })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, writable: true, value: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      downloads.push(this.download)
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  const pdf = (created: number) =>
    new Response('%PDF-1.7 fiches', {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': 'attachment; filename="fiches-acces-20261009.pdf"',
        'X-Accounts-Created': String(created),
        'Cache-Control': 'no-store, private',
      },
    })

  it('creates the accounts of the selected clients and downloads the PDF', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': () => json(page([baraka, benali, { ...benali, id: 14, code: 'C0014', pending_region_change: null, status: 'ACTIVE' }])),
      'POST /api/v1/admin/client-accounts': () => pdf(2),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients')
    await user.click(await screen.findByRole('checkbox', { name: 'Sélectionner le client C0013' }, slow))
    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner le client C0014' }))
    expect(screen.getByText('2 clients sélectionnés')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Sélectionner les clients de la page' })).toHaveProperty('indeterminate', true)

    await user.click(screen.getByRole('button', { name: 'Créer les comptes et imprimer les fiches d’accès' }))

    expect(await screen.findByText('2 comptes créés : les fiches d’accès ont été téléchargées.')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'POST')!
    expect(post.path).toBe('/api/v1/admin/client-accounts')
    expect(post.body).toEqual({ client_ids: [13, 14] })
    // Session Sanctum : jeton anti-CSRF repris du cookie.
    expect(post.headers.get('X-XSRF-TOKEN')).toBe('token=1')
    expect(downloads).toEqual(['fiches-acces-20261009.pdf'])
    expect(await blobs[0]?.text()).toBe('%PDF-1.7 fiches')
    // Sélection vidée après la création.
    expect(screen.queryByText(/sélectionnés?$/)).not.toBeInTheDocument()
  })

  it('selects the whole page at once', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': () => json(page([baraka, benali])),
      'POST /api/v1/admin/client-accounts': () => pdf(1),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients')
    await user.click(await screen.findByRole('checkbox', { name: 'Sélectionner les clients de la page' }, slow))
    expect(screen.getByText('2 clients sélectionnés')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Créer les comptes et imprimer les fiches d’accès' }))

    expect(await screen.findByText('1 compte créé : la fiche d’accès a été téléchargée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ client_ids: [12, 13] })
  })

  it('shows why no account was created', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': () => json(page([baraka])),
      'POST /api/v1/admin/client-accounts': () =>
        problem(422, 'VALIDATION_FAILED', 'Aucun compte créé : les clients sélectionnés ont déjà un compte, sont inactifs, ou leur identifiant est déjà utilisé.', {
          errors: { client_ids: ['Aucun compte créé.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients')
    await user.click(await screen.findByRole('checkbox', { name: 'Sélectionner le client C0012' }, slow))
    await user.click(screen.getByRole('button', { name: 'Créer les comptes et imprimer les fiches d’accès' }))

    expect(await screen.findByText(/Aucun compte créé : les clients sélectionnés ont déjà un compte/)).toBeInTheDocument()
    expect(downloads).toEqual([])
    // La sélection est conservée pour corriger et réessayer.
    expect(screen.getByText('1 client sélectionné')).toBeInTheDocument()
  })

  it('creates the account of a client without one from its detail page', async () => {
    let current = benali
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/13': () => json(current),
      'POST /api/v1/admin/client-accounts': () => {
        current = { ...benali, accounts: [{ id: 9, username: 'c0013', is_active: true, last_login_at: null }] }
        return pdf(1)
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/13')
    const accounts = (await screen.findByRole('heading', { name: 'Comptes' }, slow)).closest('section')!
    expect(within(accounts).getByText('Aucun compte pour ce client.')).toBeInTheDocument()
    await user.click(within(accounts).getByRole('button', { name: 'Créer le compte' }))

    expect(await within(accounts).findByText('Compte créé : la fiche d’accès a été téléchargée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ client_ids: [13] })
    expect(downloads).toEqual(['fiches-acces-20261009.pdf'])
    expect(await within(accounts).findByRole('link', { name: 'c0013' })).toHaveAttribute('href', '/admin/comptes/9')
  })
})

describe('back-office — client detail', () => {
  it('shows every field, the accounts and the pending region change', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/12': () => json({ ...baraka, pending_region_change: { region: estRef, effective_on: tomorrow } }),
    })

    renderApp('/admin/clients/12')

    expect(await screen.findByRole('heading', { name: 'C0012 — Superette El Baraka' }, slow)).toBeInTheDocument()
    expect(screen.getByText(`Changement de région programmé : Centre → Est, effectif le ${formatDate(tomorrow)}.`)).toBeInTheDocument()
    expect(screen.getByText('12 rue des Oliviers')).toBeInTheDocument()
    expect(screen.getByText('36.7212345, 3.1812345')).toBeInTheDocument()
    expect(screen.getByText('000016001234567')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '0550123456' })).toHaveAttribute('href', 'tel:0550123456')
    expect(screen.getByRole('link', { name: 'CENTRE — Centre' })).toHaveAttribute('href', '/admin/regions/3')
    expect(screen.getByRole('link', { name: 'c0012' })).toHaveAttribute('href', '/admin/comptes/5')
    expect(screen.queryByRole('button', { name: 'Créer le compte' })).not.toBeInTheDocument()
  })

  it('blocks a client only with a reason', async () => {
    let current = baraka
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/12': () => json(current),
      'PATCH /api/v1/clients/12': ({ body }) => {
        const input = body as { status: Client['status']; status_reason: string }
        current = { ...current, ...input, status_label: 'Bloqué', status_changed_at: '2026-10-09T10:00:00+00:00' }
        return json(current)
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/12')
    await user.click(await screen.findByRole('button', { name: 'Bloquer' }, slow))

    const dialog = await screen.findByRole('dialog', { name: 'Bloquer le client' })
    expect(within(dialog).getByLabelText('Motif')).toHaveFocus()
    await user.click(within(dialog).getByRole('button', { name: 'Bloquer le client' }))
    expect(await within(dialog).findByText('Le motif est obligatoire pour bloquer un client.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Motif')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((call) => call.method === 'PATCH')).toBe(false)

    await user.type(within(dialog).getByLabelText('Motif'), 'Impayés au 30/09')
    await user.click(within(dialog).getByRole('button', { name: 'Bloquer le client' }))

    expect(await screen.findByText('Statut mis à jour.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ status: 'BLOCKED', status_reason: 'Impayés au 30/09' })
    const status = screen.getByRole('heading', { name: 'Statut' }).closest('section')!
    expect(within(status).getByText('Bloqué')).toBeInTheDocument()
    expect(within(status).getByText('Impayés au 30/09')).toBeInTheDocument()
    expect(within(status).getByRole('button', { name: 'Débloquer' })).toBeInTheDocument()
  })

  it('unblocks a client without requiring a reason', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/13': () => json(benali),
      'PATCH /api/v1/clients/13': () => json({ ...benali, status: 'ACTIVE', status_reason: null }),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/13')
    await user.click(await screen.findByRole('button', { name: 'Débloquer' }, slow))
    const dialog = await screen.findByRole('dialog', { name: 'Débloquer le client' })
    await user.click(within(dialog).getByRole('button', { name: 'Débloquer le client' }))

    expect(await screen.findByText('Statut mis à jour.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ status: 'ACTIVE', status_reason: null })
  })

  it('edits the fiche and announces a region change effective the next day', async () => {
    let current = baraka
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/12': () => json(current),
      'PATCH /api/v1/clients/12': ({ body }) => {
        const input = body as Partial<Client> & { region_id?: number }
        current = {
          ...current,
          phone2: input.phone2 ?? null,
          pending_region_change: input.region_id ? { region: estRef, effective_on: tomorrow } : null,
        }
        return json(current)
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/12')
    await user.click(await screen.findByRole('button', { name: 'Modifier la fiche' }, slow))
    expect(screen.getByLabelText('Code client')).toHaveAttribute('readonly')
    await screen.findByRole('option', { name: 'EST — Est' })
    // Une région inactive n'est pas proposée.
    expect(screen.queryByRole('option', { name: /SUD/ })).not.toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Région'), '4')
    expect(screen.getByText(`Changement de région effectif le ${formatDate(tomorrow)} (journée suivante) : la journée en cours n’est pas modifiée.`)).toBeInTheDocument()
    await user.type(screen.getByLabelText('Second téléphone'), '0770112233')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Fiche mise à jour.')).toBeInTheDocument()
    const body = calls.find((call) => call.method === 'PATCH')?.body as Record<string, unknown>
    expect(body).toMatchObject({ region_id: 4, phone2: '0770112233', name: 'Superette El Baraka', lat: 36.7212345, lng: 3.1812345 })
    expect(body).not.toHaveProperty('code')
    expect(screen.getByText(`Changement de région programmé : Centre → Est, effectif le ${formatDate(tomorrow)}.`)).toBeInTheDocument()
  })

  it('does not send the region when it was not changed', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/13': () => json(benali),
      'PATCH /api/v1/clients/13': ({ body }) => json({ ...benali, ...(body as Partial<Client>) }),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/13')
    await user.click(await screen.findByRole('button', { name: 'Modifier la fiche' }, slow))
    // Le formulaire propose la région programmée.
    await screen.findByRole('option', { name: 'EST — Est' })
    expect(screen.getByLabelText('Région')).toHaveValue('4')
    await user.clear(screen.getByLabelText('Raison sociale'))
    await user.type(screen.getByLabelText('Raison sociale'), 'Grossiste Benali et fils')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Fiche mise à jour.')).toBeInTheDocument()
    const body = calls.find((call) => call.method === 'PATCH')?.body as Record<string, unknown>
    expect(body.name).toBe('Grossiste Benali et fils')
    expect(body).not.toHaveProperty('region_id')
    expect(body).not.toHaveProperty('status')
  })

  it('cancels a pending region change', async () => {
    let current = benali
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/13': () => json(current),
      'PATCH /api/v1/clients/13': () => {
        current = { ...benali, pending_region_change: null }
        return json(current)
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/13')
    await user.click(await screen.findByRole('button', { name: 'Annuler le changement de région' }, slow))

    expect(await screen.findByText('Changement de région annulé.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ region_id: 3 })
    expect(screen.queryByText(/Changement de région programmé/)).not.toBeInTheDocument()
  })

  it('validates the coordinates pair and shows server errors on the fields', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients/12': () => json(baraka),
      'PATCH /api/v1/clients/12': () =>
        problem(422, 'VALIDATION_FAILED', 'Le champ adresse e-mail doit être une adresse e-mail valide.', {
          errors: { email: ['Le champ adresse e-mail doit être une adresse e-mail valide.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/12')
    await user.click(await screen.findByRole('button', { name: 'Modifier la fiche' }, slow))
    await user.clear(screen.getByLabelText('Longitude'))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Renseignez la latitude et la longitude ensemble.')).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'PATCH')).toBe(false)

    await user.type(screen.getByLabelText('Longitude'), '3,18')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(screen.getByLabelText('Adresse e-mail')).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('Le champ adresse e-mail doit être une adresse e-mail valide.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toMatchObject({ lat: 36.7212345, lng: 3.18 })
  })

  it('gives the ADV a read-only fiche', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/clients/13': () => json(withoutAccounts(benali)),
    })

    renderApp('/adv/clients/13')

    expect(await screen.findByRole('heading', { name: 'C0013 — Grossiste Benali' }, slow)).toBeInTheDocument()
    expect(screen.getByText('Impayés')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier la fiche' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Débloquer' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Annuler le changement de région' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Comptes' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Tous les clients' })).toHaveAttribute('href', '/adv/clients')
  })

  it('answers 404 for a client outside the perimeter', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/clients/99': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
    })

    renderApp('/adv/clients/99')

    expect(await screen.findByText('Ressource introuvable.', undefined, slow)).toBeInTheDocument()
  })
})

describe('back-office — client creation', () => {
  it('creates a client and opens its fiche', async () => {
    const created: Client = { ...baraka, id: 20, code: 'C0099', name: 'Alimentation Nour', accounts: [] }
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'POST /api/v1/clients': () => json(created, 201),
      'GET /api/v1/clients/20': () => json(created),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/nouveau')
    await user.type(await screen.findByLabelText('Code client', undefined, slow), 'c0099')
    await user.type(screen.getByLabelText('Raison sociale'), 'Alimentation Nour')
    await screen.findByRole('option', { name: 'CENTRE — Centre' })
    await user.selectOptions(screen.getByLabelText('Région'), '3')
    await user.selectOptions(screen.getByLabelText('Catégorie'), '3')
    await user.type(screen.getByLabelText('Téléphone'), '0550000099')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/admin/clients/20'))
    expect(await screen.findByText('Client créé.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      code: 'C0099',
      name: 'Alimentation Nour',
      trade_name: null,
      category_id: 3,
      region_id: 3,
      address: null,
      wilaya: null,
      commune: null,
      lat: null,
      lng: null,
      phone: '0550000099',
      phone2: null,
      email: null,
      nif: null,
      nis: null,
      rc: null,
      ai: null,
    })
  })

  it('requires a region and a well-formed code', async () => {
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients/nouveau')
    await user.type(await screen.findByLabelText('Code client', undefined, slow), 'C 12')
    await user.type(screen.getByLabelText('Raison sociale'), 'Client sans région')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Choisissez une région.')).toBeInTheDocument()
    expect(screen.getByText(/Lettres, chiffres, « - » et « _ » uniquement/)).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'POST')).toBe(false)
  })
})

describe('back-office — client categories', () => {
  it('lists and creates categories', async () => {
    let list = [...categories]
    const { calls } = mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/clients': () => json(page([baraka])),
      'GET /api/v1/client-categories': () => json({ data: list }),
      'POST /api/v1/admin/client-categories': ({ body }) => {
        const created = { ...(body as ClientCategory), id: 7, clients_count: 0 }
        list = [...list, created]
        return json(created, 201)
      },
      'PATCH /api/v1/admin/client-categories/1': ({ body }) => json({ ...categories[0], ...(body as object) }),
    })
    const user = userEvent.setup()

    renderApp('/admin/clients')
    await user.click(await screen.findByRole('tab', { name: 'Catégories' }, slow))
    expect(screen.getByRole('tab', { name: 'Catégories' })).toHaveAttribute('aria-selected', 'true')
    expect((await screen.findByText('GROSSISTE')).closest('tr')).toHaveTextContent('Grossiste')

    await user.click(screen.getByRole('button', { name: 'Nouvelle catégorie' }))
    let dialog = await screen.findByRole('dialog', { name: 'Nouvelle catégorie' })
    await user.type(within(dialog).getByLabelText('Code'), 'distrib')
    await user.type(within(dialog).getByLabelText('Libellé'), 'Distributeur')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Catégorie créée.')).toBeInTheDocument()
    expect(await screen.findByText('DISTRIB')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ code: 'DISTRIB', name: 'Distributeur' })

    await user.click(screen.getByRole('button', { name: 'Modifier la catégorie GROSSISTE' }))
    dialog = await screen.findByRole('dialog', { name: 'Modifier la catégorie GROSSISTE' })
    await user.clear(within(dialog).getByLabelText('Libellé'))
    await user.type(within(dialog).getByLabelText('Libellé'), 'Grossistes')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Catégorie mise à jour.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ name: 'Grossistes' })
  })

  it('lets the ADV read the categories without editing them', async () => {
    mockApi({
      ...referenceRoutes,
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/clients': () => json(page([withoutAccounts(baraka)])),
    })
    const user = userEvent.setup()

    renderApp('/adv/clients')
    await user.click(await screen.findByRole('tab', { name: 'Catégories' }, slow))

    expect(await screen.findByText('DETAILLANT')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouvelle catégorie' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Modifier la catégorie/ })).not.toBeInTheDocument()
  })
})
