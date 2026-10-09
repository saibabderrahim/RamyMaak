import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Assignment, Depot, Paginated, Region, User } from '../../../shared/api/types'
import { businessToday, formatDate } from '../../../shared/format'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { addDays } from '../shared/dates'

// Premier rendu : le module du rôle est chargé à la demande.
const slow = { timeout: 5000 }

const ADMIN_PERMISSIONS = ['user.manage', 'region.view', 'region.manage', 'client.view', 'client.manage', 'client.block']
const admin = () => makeUser('admin', { permissions: ADMIN_PERMISSIONS })
const adv = () => makeUser('adv', { permissions: ['region.view', 'client.view', 'product.view'] })

const today = businessToday()

const karim = { id: 12, username: 'k.saidi', name: 'Karim Saïdi' }
const nadia = { id: 21, username: 'n.bensalem', name: 'Nadia Bensalem' }

const titular: Assignment = {
  id: 7,
  region_id: 3,
  kind: 'TITULAR',
  kind_label: 'Titulaire',
  user: karim,
  starts_on: addDays(today, -8),
  ends_on: null,
  is_active: true,
  revoked_at: null,
  revoked_by: null,
  created_at: '2026-10-01T08:00:00+00:00',
}

const depot: Depot = { id: 1, code: 'BLIDA', name: 'Dépôt Blida', address: 'Zone industrielle, Blida', is_active: true, regions_count: 2 }

const centre: Region = {
  id: 3,
  code: 'CENTRE',
  name: 'Centre',
  is_active: true,
  depot: { id: 1, code: 'BLIDA', name: 'Dépôt Blida' },
  titular,
  deputies: [],
  clients_count: 142,
}

const sud: Region = { id: 4, code: 'SUD', name: 'Sud', is_active: false, depot: null, titular: null, deputies: [], clients_count: 0 }

const page = <T,>(data: T[]): Paginated<T> => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
})

const account = (id: number, username: string, name: string, role: User['role']): User => ({
  id,
  username,
  name,
  email: null,
  phone: null,
  role,
  client_id: null,
  client: null,
  is_active: true,
  is_locked: false,
  locked_until: null,
  must_change_password: false,
  last_login_at: null,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
})

const regionals = [account(12, 'k.saidi', 'Karim Saïdi', 'regional'), account(21, 'n.bensalem', 'Nadia Bensalem', 'regional')]
const advs = [account(31, 'l.hamdi', 'Lina Hamdi', 'adv')]

type Routes = Parameters<typeof mockApi>[0]

/** API simulée d'une fiche région dont l'historique évolue avec les créations et révocations. */
function regionDetailApi(user = admin(), initial: Assignment[] = [titular]) {
  let history = [...initial]
  let postError: string | null = null
  const usersRequests: URLSearchParams[] = []

  const routes: Routes = {
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/regions/3': () => json(centre),
    'GET /api/v1/admin/depots': () => json({ data: [depot] }),
    'GET /api/v1/admin/regions/3/assignments': () => json({ data: history }),
    'GET /api/v1/admin/users': ({ url }) => {
      usersRequests.push(url.searchParams)
      return json(page(url.searchParams.get('filter[role]') === 'adv' ? advs : regionals))
    },
    'POST /api/v1/admin/regions/3/assignments': ({ body }) => {
      if (postError) {
        const message = postError
        postError = null
        return problem(422, 'VALIDATION_FAILED', message, { errors: { user_id: [message] } })
      }
      const input = body as { user_id: number; kind: Assignment['kind']; starts_on: string; ends_on: string | null; replace?: boolean }
      const account = [...regionals, ...advs].find((candidate) => candidate.id === input.user_id)!
      if (input.replace) {
        history = history.map((assignment) =>
          assignment.kind === 'TITULAR' && !assignment.revoked_at
            ? { ...assignment, is_active: false, revoked_at: '2026-10-09T09:00:00+00:00', revoked_by: { id: 1, username: 'admin', name: 'Admin' } }
            : assignment,
        )
      }
      const created: Assignment = {
        id: 40 + history.length,
        region_id: 3,
        kind: input.kind,
        kind_label: { TITULAR: 'Titulaire', DEPUTY: 'Suppléant', ADV: 'ADV' }[input.kind],
        user: { id: account.id, username: account.username, name: account.name },
        starts_on: input.starts_on,
        ends_on: input.ends_on,
        is_active: input.starts_on <= today,
        revoked_at: null,
        revoked_by: null,
        created_at: '2026-10-09T09:00:00+00:00',
      }
      history = [created, ...history]
      allowEnd(created.id)
      return json(created, 201)
    },
  }

  // DELETE /admin/assignments/{id} : révocation immédiate, 409 si déjà révoquée.
  function allowEnd(id: number) {
    routes[`DELETE /api/v1/admin/assignments/${id}`] = () => {
      const target = history.find((assignment) => assignment.id === id)!
      if (target.revoked_at) return problem(409, 'INVALID_TRANSITION', 'Cette affectation est déjà terminée.')
      const revoked = { ...target, is_active: false, revoked_at: '2026-10-09T10:00:00+00:00', revoked_by: { id: 1, username: 'admin', name: 'Admin' } }
      history = history.map((assignment) => (assignment.id === id ? revoked : assignment))
      return json(revoked)
    }
  }
  initial.forEach((assignment) => allowEnd(assignment.id))

  const mock = mockApi(routes)
  return {
    ...mock,
    usersRequests,
    history: () => history,
    failNextPost: (message: string) => {
      postError = message
    },
    /** Affectation créée par quelqu'un d'autre : l'écran n'en sait encore rien. */
    addElsewhere: (assignment: Assignment) => {
      history = [assignment, ...history]
      allowEnd(assignment.id)
    },
    /** Révocation faite par quelqu'un d'autre : l'écran n'en sait encore rien. */
    revokeElsewhere: (id: number) => {
      history = history.map((assignment) =>
        assignment.id === id ? { ...assignment, is_active: false, revoked_at: '2026-10-09T08:00:00+00:00', revoked_by: { id: 2, username: 'admin2', name: 'Autre admin' } } : assignment,
      )
    },
  }
}

describe('back-office — regions list', () => {
  it('lists regions with depot, titular, deputies and clients count, and filters them', async () => {
    const requests: URLSearchParams[] = []
    mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions': ({ url }) => {
        requests.push(url.searchParams)
        return json(page([centre, sud]))
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/regions')

    const row = (await screen.findByRole('link', { name: 'CENTRE' }, slow)).closest('tr')!
    expect(within(row).getByText('Centre')).toBeInTheDocument()
    expect(within(row).getByText('Dépôt Blida')).toBeInTheDocument()
    expect(within(row).getByText('Karim Saïdi')).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: /Clients de la région Centre/ })).toHaveAttribute('href', '/admin/clients?region_id=3')
    expect(within(row).getByText('Active')).toBeInTheDocument()

    const inactive = screen.getByRole('link', { name: 'SUD' }).closest('tr')!
    expect(within(inactive).getByText('Aucun titulaire')).toBeInTheDocument()
    expect(within(inactive).getByText('Inactive')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Statut'), '0')
    await user.type(screen.getByLabelText('Rechercher'), 'cen')
    await waitFor(() => expect(requests.at(-1)?.get('filter[search]')).toBe('cen'))
    expect(requests.at(-1)?.get('filter[is_active]')).toBe('0')
    expect(requests.at(-1)?.get('sort')).toBe('code')
  })

  it('creates a region and opens its detail page', async () => {
    const created: Region = { id: 5, code: 'EST', name: 'Est', is_active: true, depot: centre.depot, titular: null, deputies: [], clients_count: 0 }
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions': () => json(page([centre])),
      'GET /api/v1/admin/depots': () => json({ data: [depot, { ...depot, id: 2, code: 'ORAN', name: 'Dépôt Oran', is_active: false }] }),
      'POST /api/v1/admin/regions': () => json(created, 201),
      'GET /api/v1/regions/5': () => json(created),
      'GET /api/v1/admin/regions/5/assignments': () => json({ data: [] }),
      'GET /api/v1/admin/users': () => json(page(regionals)),
    })
    const user = userEvent.setup()

    renderApp('/admin/regions')
    await user.click(await screen.findByRole('button', { name: 'Nouvelle région' }, slow))

    const dialog = await screen.findByRole('dialog', { name: 'Nouvelle région' })
    await user.type(within(dialog).getByLabelText('Code'), 'est')
    await user.type(within(dialog).getByLabelText('Nom'), 'Est')
    await within(dialog).findByRole('option', { name: 'BLIDA — Dépôt Blida' })
    // Un dépôt désactivé n'est pas proposé.
    expect(within(dialog).queryByRole('option', { name: /ORAN/ })).not.toBeInTheDocument()
    await user.selectOptions(within(dialog).getByLabelText('Dépôt d’expédition'), '1')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/admin/regions/5'))
    expect(await screen.findByText('Région créée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ code: 'EST', name: 'Est', depot_id: 1, is_active: true })
  })

  it('shows server validation errors on the creation form', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions': () => json(page([centre])),
      'GET /api/v1/admin/depots': () => json({ data: [depot] }),
      'POST /api/v1/admin/regions': () =>
        problem(422, 'VALIDATION_FAILED', 'La valeur du champ code est déjà utilisée.', {
          errors: { code: ['La valeur du champ code est déjà utilisée.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/regions')
    await user.click(await screen.findByRole('button', { name: 'Nouvelle région' }, slow))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Code'), 'CENTRE')
    await user.type(within(dialog).getByLabelText('Nom'), 'Centre bis')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(within(dialog).getByLabelText('Code')).toHaveAttribute('aria-invalid', 'true'))
    expect(within(dialog).getByText('La valeur du champ code est déjà utilisée.')).toBeInTheDocument()
  })

  it('rejects a malformed code before calling the API', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions': () => json(page([centre])),
      'GET /api/v1/admin/depots': () => json({ data: [depot] }),
    })
    const user = userEvent.setup()

    renderApp('/admin/regions')
    await user.click(await screen.findByRole('button', { name: 'Nouvelle région' }, slow))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Code'), 'C')
    await user.type(within(dialog).getByLabelText('Nom'), 'Centre')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByText(/2 à 20 caractères/, { selector: '[role=alert]' })).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'POST')).toBe(false)
  })

  it('manages depots in their own tab', async () => {
    let depots = [depot]
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions': () => json(page([centre])),
      'GET /api/v1/admin/depots': () => json({ data: depots }),
      'POST /api/v1/admin/depots': ({ body }) => {
        const created = { ...(body as Depot), id: 2, regions_count: 0 }
        depots = [...depots, created]
        return json(created, 201)
      },
      'PATCH /api/v1/admin/depots/1': ({ body }) => {
        depots = depots.map((item) => (item.id === 1 ? { ...item, ...(body as Partial<Depot>) } : item))
        return json(depots[0])
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/regions')
    // Onglets au clavier (motif ARIA « tabs »).
    const regionsTab = await screen.findByRole('tab', { name: 'Régions' }, slow)
    expect(regionsTab).toHaveAttribute('aria-selected', 'true')
    regionsTab.focus()
    await user.keyboard('{ArrowRight}')
    const depotsTab = screen.getByRole('tab', { name: 'Dépôts' })
    expect(depotsTab).toHaveAttribute('aria-selected', 'true')
    expect(depotsTab).toHaveFocus()
    expect(screen.getByRole('tabpanel', { name: 'Dépôts' })).toBeInTheDocument()
    const row = (await screen.findByText('BLIDA')).closest('tr')!
    expect(within(row).getByText('Zone industrielle, Blida')).toBeInTheDocument()
    expect(within(row).getByText('2')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Nouveau dépôt' }))
    let dialog = await screen.findByRole('dialog', { name: 'Nouveau dépôt' })
    await user.type(within(dialog).getByLabelText('Code'), 'oran')
    await user.type(within(dialog).getByLabelText('Nom'), 'Dépôt Oran')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Dépôt créé.')).toBeInTheDocument()
    expect(await screen.findByText('ORAN')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ code: 'ORAN', name: 'Dépôt Oran', address: null, is_active: true })

    await user.click(screen.getByRole('button', { name: 'Modifier le dépôt BLIDA' }))
    dialog = await screen.findByRole('dialog', { name: 'Modifier le dépôt BLIDA' })
    expect(within(dialog).getByLabelText('Code')).toHaveAttribute('readonly')
    await user.click(within(dialog).getByLabelText('Dépôt actif'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Dépôt mis à jour.')).toBeInTheDocument()
    // Le code ne change pas : il n'est pas envoyé.
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ name: 'Dépôt Blida', address: 'Zone industrielle, Blida', is_active: false })
  })

  it('gives the ADV a read-only list without depots tab nor creation', async () => {
    const requests: URLSearchParams[] = []
    mockApi({
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/regions': ({ url }) => {
        requests.push(url.searchParams)
        return json(page([centre]))
      },
    })

    renderApp('/adv/regions')

    expect(await screen.findByRole('link', { name: 'CENTRE' }, slow)).toHaveAttribute('href', '/adv/regions/3')
    expect(screen.queryByRole('button', { name: 'Nouvelle région' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Dépôts' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Statut')).not.toBeInTheDocument()
    expect(requests[0]?.has('filter[is_active]')).toBe(false)
  })
})

describe('back-office — region detail and assignments', () => {
  it('shows the titular, deputies, ADV and the full history', async () => {
    const deputy: Assignment = {
      ...titular,
      id: 8,
      kind: 'DEPUTY',
      kind_label: 'Suppléant',
      user: nadia,
      starts_on: addDays(today, 2),
      ends_on: addDays(today, 12),
      is_active: false,
    }
    const former: Assignment = {
      ...titular,
      id: 3,
      user: { id: 9, username: 'o.ziani', name: 'Omar Ziani' },
      starts_on: '2026-01-01',
      ends_on: null,
      is_active: false,
      revoked_at: '2026-09-30T16:00:00+00:00',
      revoked_by: { id: 1, username: 'admin', name: 'Admin' },
    }
    regionDetailApi(admin(), [deputy, titular, former])

    renderApp('/admin/regions/3')

    expect(await screen.findByRole('heading', { name: 'CENTRE — Centre' }, slow)).toBeInTheDocument()
    const titularSection = screen.getByRole('region', { name: 'Titulaire' })
    expect(await within(titularSection).findByText('Karim Saïdi')).toBeInTheDocument()
    expect(within(titularSection).getByText('En cours')).toBeInTheDocument()

    const deputies = screen.getByRole('region', { name: 'Suppléants' })
    expect(within(deputies).getByText('Nadia Bensalem')).toBeInTheDocument()
    expect(within(deputies).getByText(`Du ${formatDate(addDays(today, 2))} au ${formatDate(addDays(today, 12))}`)).toBeInTheDocument()
    expect(within(deputies).getByText('À venir')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'ADV' })).getByText('Aucun ADV affecté à cette région.')).toBeInTheDocument()

    const history = screen.getByRole('table', { name: 'Historique des affectations' })
    expect(within(history).getAllByRole('row')).toHaveLength(4)
    const revoked = within(history).getByText('Omar Ziani').closest('tr')!
    expect(within(revoked).getByText('Révoquée')).toBeInTheDocument()
    expect(within(revoked).getByText(/par Admin/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir les clients' })).toHaveAttribute('href', '/admin/clients?region_id=3')
  })

  it('adds a deputy assignment, then ends it after confirmation', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!

    await user.selectOptions(within(form).getByLabelText('Nature'), 'DEPUTY')
    await within(form).findByRole('option', { name: 'Nadia Bensalem (n.bensalem)' })
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '21')
    await user.type(within(form).getByLabelText('Fin'), addDays(today, 10))
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    expect(await screen.findByText('Affectation ajoutée.')).toBeInTheDocument()
    expect(api.calls.find((call) => call.method === 'POST')?.body).toEqual({
      user_id: 21,
      kind: 'DEPUTY',
      starts_on: today,
      ends_on: addDays(today, 10),
    })
    // Choix limité aux régionaux actifs.
    expect(api.usersRequests.at(-1)?.get('filter[role]')).toBe('regional')
    expect(api.usersRequests.at(-1)?.get('filter[is_active]')).toBe('1')

    const deputies = screen.getByRole('region', { name: 'Suppléants' })
    await user.click(await within(deputies).findByRole('button', { name: 'Mettre fin à l’affectation de Nadia Bensalem (Suppléant)' }))

    const dialog = await screen.findByRole('dialog', { name: 'Mettre fin à l’affectation' })
    expect(dialog).toHaveTextContent('L’affectation de Nadia Bensalem (Suppléant) prendra fin immédiatement.')
    await user.click(within(dialog).getByRole('button', { name: 'Mettre fin à l’affectation' }))

    expect(await screen.findByText('Affectation terminée.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    const created = api.history().find((assignment) => assignment.user.id === 21)!
    expect(api.calls.some((call) => call.method === 'DELETE' && call.path === `/api/v1/admin/assignments/${created.id}`)).toBe(true)
    await waitFor(() => expect(within(deputies).getByText('Aucun suppléant en cours ou à venir.')).toBeInTheDocument())
  })

  it('reports an assignment already ended elsewhere', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const titularSection = await screen.findByRole('region', { name: 'Titulaire' }, slow)
    const end = await within(titularSection).findByRole('button', { name: 'Mettre fin à l’affectation de Karim Saïdi (Titulaire)' })
    api.revokeElsewhere(7)
    await user.click(end)
    const dialog = await screen.findByRole('dialog', { name: 'Mettre fin à l’affectation' })
    await user.click(within(dialog).getByRole('button', { name: 'Mettre fin à l’affectation' }))

    expect(await within(dialog).findByText('Cette affectation est déjà terminée.')).toBeInTheDocument()
    // Écran rafraîchi : l'affectation n'est plus en cours.
    expect(await within(titularSection).findByText('Aucun titulaire en ce moment.')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('requires an end date for a deputy', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!
    await user.selectOptions(within(form).getByLabelText('Nature'), 'DEPUTY')
    await within(form).findByRole('option', { name: 'Nadia Bensalem (n.bensalem)' })
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '21')
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    expect(await within(form).findByText('Une suppléance exige une date de fin.')).toBeInTheDocument()
    expect(within(form).getByLabelText('Fin')).toHaveAttribute('aria-invalid', 'true')
    expect(api.calls.some((call) => call.method === 'POST')).toBe(false)
  })

  it('asks for an explicit confirmation before replacing the current titular', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!
    await within(form).findByRole('option', { name: 'Nadia Bensalem (n.bensalem)' })
    // Les données de l'historique doivent être chargées pour détecter le titulaire en place.
    await within(screen.getByRole('region', { name: 'Titulaire' })).findByText('Karim Saïdi')
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '21')
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    let dialog = await screen.findByRole('dialog', { name: 'Remplacer le titulaire' })
    expect(dialog).toHaveTextContent('La région a déjà un titulaire sur cette période : Karim Saïdi.')
    expect(dialog).toHaveTextContent(`Nadia Bensalem devient titulaire à partir du ${formatDate(today)}`)

    // Annuler n'envoie rien.
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.calls.some((call) => call.method === 'POST')).toBe(false)

    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))
    dialog = await screen.findByRole('dialog', { name: 'Remplacer le titulaire' })
    await user.click(within(dialog).getByRole('button', { name: 'Remplacer le titulaire' }))

    expect(await screen.findByText('Affectation ajoutée.')).toBeInTheDocument()
    expect(api.calls.find((call) => call.method === 'POST')?.body).toEqual({
      user_id: 21,
      kind: 'TITULAR',
      starts_on: today,
      ends_on: null,
      replace: true,
    })
  })

  it('offers the replacement once a titular appointed meanwhile is known', async () => {
    const api = regionDetailApi(admin(), [])
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!
    const titularSection = screen.getByRole('region', { name: 'Titulaire' })
    await within(titularSection).findByText('Aucun titulaire en ce moment.')
    await within(form).findByRole('option', { name: 'Nadia Bensalem (n.bensalem)' })
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '21')

    api.addElsewhere(titular)
    api.failNextPost('La région a déjà un titulaire sur cette période (Karim Saïdi). Confirmez le remplacement pour mettre fin à son affectation.')
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    expect(await within(form).findByText(/Confirmez le remplacement/)).toBeInTheDocument()
    expect(await within(titularSection).findByText('Karim Saïdi')).toBeInTheDocument()

    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))
    const dialog = await screen.findByRole('dialog', { name: 'Remplacer le titulaire' })
    await user.click(within(dialog).getByRole('button', { name: 'Remplacer le titulaire' }))

    expect(await screen.findByText('Affectation ajoutée.')).toBeInTheDocument()
    expect(api.calls.filter((call) => call.method === 'POST').at(-1)?.body).toMatchObject({ user_id: 21, kind: 'TITULAR', replace: true })
  })

  it('does not ask for a confirmation for an ADV assignment', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!
    await user.selectOptions(within(form).getByLabelText('Nature'), 'ADV')
    await within(form).findByRole('option', { name: 'Lina Hamdi (l.hamdi)' })
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '31')
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    expect(await screen.findByText('Affectation ajoutée.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.usersRequests.at(-1)?.get('filter[role]')).toBe('adv')
    expect(api.calls.find((call) => call.method === 'POST')?.body).toMatchObject({ user_id: 31, kind: 'ADV', ends_on: null })
    expect(await within(screen.getByRole('region', { name: 'ADV' })).findByText('Lina Hamdi')).toBeInTheDocument()
  })

  it('shows the server error on the user field', async () => {
    const api = regionDetailApi()
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Ajouter une affectation' }, slow)).closest('section')!
    await user.selectOptions(within(form).getByLabelText('Nature'), 'ADV')
    await within(form).findByRole('option', { name: 'Lina Hamdi (l.hamdi)' })
    await user.selectOptions(within(form).getByLabelText('Utilisateur'), '31')
    api.failNextPost('Cet utilisateur a déjà une affectation « ADV » sur cette région pour une période qui chevauche celle-ci.')
    await user.click(within(form).getByRole('button', { name: 'Ajouter l’affectation' }))

    await waitFor(() => expect(within(form).getByLabelText('Utilisateur')).toHaveAttribute('aria-invalid', 'true'))
    expect(within(form).getByText(/déjà une affectation « ADV »/)).toBeInTheDocument()
  })

  it('edits the region name, depot and status', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(admin()),
      'GET /api/v1/regions/3': () => json(centre),
      'GET /api/v1/admin/depots': () => json({ data: [depot] }),
      'GET /api/v1/admin/regions/3/assignments': () => json({ data: [titular] }),
      'GET /api/v1/admin/users': () => json(page(regionals)),
      'PATCH /api/v1/admin/regions/3': () =>
        problem(422, 'VALIDATION_FAILED', 'La région compte encore 142 clients actifs ou bloqués.', {
          errors: { is_active: ['La région compte encore 142 clients actifs ou bloqués.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/regions/3')
    const form = (await screen.findByRole('heading', { name: 'Modifier la région' }, slow)).closest('section')!
    expect(within(form).getByLabelText('Code')).toHaveAttribute('readonly')
    await user.clear(within(form).getByLabelText('Nom'))
    await user.type(within(form).getByLabelText('Nom'), 'Centre-Alger')
    await user.click(within(form).getByLabelText('Région active'))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(form).findByText('La région compte encore 142 clients actifs ou bloqués.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ name: 'Centre-Alger', depot_id: 1, is_active: false })
  })

  it('gives the ADV a read-only detail page', async () => {
    regionDetailApi(adv())

    renderApp('/adv/regions/3')

    expect(await screen.findByRole('heading', { name: 'CENTRE — Centre' }, slow)).toBeInTheDocument()
    expect(await within(screen.getByRole('region', { name: 'Titulaire' })).findByText('Karim Saïdi')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Historique des affectations' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Ajouter une affectation' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Modifier la région' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Mettre fin/ })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Toutes les régions' })).toHaveAttribute('href', '/adv/regions')
  })

  it('answers 404 for a region outside the perimeter', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/regions/9': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
      'GET /api/v1/admin/regions/9/assignments': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
    })

    renderApp('/adv/regions/9')

    expect(await screen.findByText('Ressource introuvable.', undefined, slow)).toBeInTheDocument()
  })
})
