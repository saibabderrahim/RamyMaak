import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { ReasonCode, ReasonCodeInput, ReasonCodeUpdate, ReasonType } from '../../../shared/api/types'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'

const admin = makeUser('admin', { permissions: ['settings.manage', 'dashboard.view'] })

const reason = (id: number, type: ReasonType, code: string, label: string, sortOrder: number, overrides: Partial<ReasonCode> = {}): ReasonCode => ({
  id,
  type,
  code,
  label,
  requires_comment: false,
  sort_order: sortOrder,
  is_active: true,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
  ...overrides,
})

const initial = (): ReasonCode[] => [
  reason(1, 'REJECTION', 'QUOTA_EPUISE', 'Quota épuisé', 10),
  reason(2, 'REJECTION', 'CLIENT_BLOQUE', 'Client bloqué', 20),
  reason(3, 'REJECTION', 'DOUBLON', 'Commande en double', 30, { is_active: false }),
  reason(4, 'REJECTION', 'AUTRE', 'Autre', 40, { requires_comment: true }),
  reason(5, 'ADJUSTMENT', 'QUOTA_INSUFFISANT', 'Quota insuffisant', 10),
  reason(6, 'SHIPPING_GAP', 'RUPTURE', 'Rupture de stock', 10),
  reason(7, 'SHIPPING_GAP', 'CASSE', 'Casse', 20),
  reason(8, 'CANCELLATION', 'ERREUR_SAISIE', 'Erreur de saisie', 10),
]

type Routes = Parameters<typeof mockApi>[0]

type ApiOptions = {
  user?: ReturnType<typeof makeUser>
  list?: () => Response
  post?: (body: ReasonCodeInput) => Response
  patch?: (id: number, body: ReasonCodeUpdate) => Response
}

/** API des motifs avec un état : les écritures se retrouvent à la relecture de la liste. */
function reasonsApi(options: ApiOptions = {}) {
  const store = initial()
  const patches: Array<{ id: number; body: ReasonCodeUpdate }> = []
  let nextId = 100
  const routes: Routes = {
    'GET /api/v1/auth/me': () => json(options.user ?? admin),
    'GET /api/v1/admin/reason-codes': ({ url }) => {
      if (options.list) return options.list()
      const types = (url.searchParams.get('filter[type]') ?? '').split(',').filter(Boolean)
      return json({ data: store.filter((item) => types.length === 0 || types.includes(item.type)) })
    },
    'POST /api/v1/admin/reason-codes': ({ body }) => {
      const input = body as ReasonCodeInput
      if (options.post) return options.post(input)
      const created = reason(nextId++, input.type, input.code, input.label, input.sort_order ?? 0, { requires_comment: input.requires_comment ?? false })
      store.push(created)
      return json(created, 201)
    },
  }
  // PATCH /admin/reason-codes/{id} : l'identifiant est dans le chemin.
  for (const item of store) {
    routes[`PATCH /api/v1/admin/reason-codes/${item.id}`] = ({ body }) => {
      const changes = body as ReasonCodeUpdate
      patches.push({ id: item.id, body: changes })
      if (options.patch) return options.patch(item.id, changes)
      Object.assign(item, changes)
      return json(item)
    }
  }
  return { ...mockApi(routes), store, patches }
}

// Modules Admin et ADV chargés à la demande : compilés une fois avant les tests.
beforeAll(async () => {
  await Promise.all([import('../../admin'), import('../../adv')])
}, 30_000)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('back-office — reason codes', () => {
  it('has a tab per type and lists the reasons, the deactivated ones included', async () => {
    const { calls } = reasonsApi()
    renderApp('/admin/motifs')

    expect(await screen.findByRole('heading', { level: 1, name: 'Motifs' })).toBeInTheDocument()
    const tabs = within(screen.getByRole('tablist', { name: 'Types de motifs' })).getAllByRole('tab')
    expect(tabs.map((tab) => tab.textContent)).toEqual(['Rejet', 'Ajustement', 'Écart d’expédition', 'Annulation'])
    expect(tabs[0]).toHaveAttribute('aria-selected', 'true')

    const table = await screen.findByRole('table', { name: 'Rejet' })
    const rows = within(table).getAllByRole('row').slice(1)
    expect(rows.map((row) => within(row).getAllByRole('cell').slice(0, 5).map((cell) => cell.textContent))).toEqual([
      ['QUOTA_EPUISE', 'Quota épuisé', 'Facultatif', '10', 'Actif'],
      ['CLIENT_BLOQUE', 'Client bloqué', 'Facultatif', '20', 'Actif'],
      ['DOUBLON', 'Commande en double', 'Facultatif', '30', 'Désactivé'],
      ['AUTRE', 'Autre', 'Obligatoire', '40', 'Actif'],
    ])
    expect(calls.filter((call) => call.path === '/api/v1/admin/reason-codes' && call.method === 'GET')).toHaveLength(1)
  })

  it('reads one type per tab (filter[type])', async () => {
    const { fetchMock } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('tab', { name: 'Écart d’expédition' }))

    const table = await screen.findByRole('table', { name: 'Écart d’expédition' })
    // Un écart d'expédition n'a pas de commentaire : la colonne n'a rien à dire (I-95).
    expect(within(table).getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell').slice(0, 4).map((cell) => cell.textContent))).toEqual([
      ['RUPTURE', 'Rupture de stock', '—', '10'],
      ['CASSE', 'Casse', '—', '20'],
    ])
    expect(screen.getByRole('tab', { name: 'Écart d’expédition' })).toHaveAttribute('aria-selected', 'true')
    const reads = fetchMock.mock.calls.map(([input]) => decodeURIComponent(String(input))).filter((url) => url.includes('/admin/reason-codes'))
    expect(reads).toEqual(['/api/v1/admin/reason-codes?filter[type]=REJECTION', '/api/v1/admin/reason-codes?filter[type]=SHIPPING_GAP'])
  })

  it('opens the tab named by the address', async () => {
    reasonsApi()
    renderApp('/admin/motifs?onglet=annulation')
    expect(await screen.findByRole('table', { name: 'Annulation' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Annulation' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Erreur de saisie')).toBeInTheDocument()
  })

  it('creates a reason: type of the tab, code in capitals, next order proposed, then the list is read again', async () => {
    const { calls } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    expect(within(dialog).getByLabelText('Type')).toHaveValue('REJECTION')
    // Plus haut ordre du type : 40, donc 50.
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('50'))
    expect(within(dialog).getByLabelText('Code')).toHaveFocus()

    await user.type(within(dialog).getByLabelText('Code'), 'erreur_saisie')
    await user.type(within(dialog).getByLabelText('Libellé'), '  Erreur de saisie  ')
    await user.click(within(dialog).getByLabelText('Commentaire obligatoire avec ce motif'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Motif créé.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      type: 'REJECTION',
      code: 'ERREUR_SAISIE',
      label: 'Erreur de saisie',
      requires_comment: true,
      sort_order: 50,
    })
    const row = (await screen.findByText('ERREUR_SAISIE')).closest('tr')!
    expect(within(row).getAllByRole('cell').slice(0, 5).map((cell) => cell.textContent)).toEqual(['ERREUR_SAISIE', 'Erreur de saisie', 'Obligatoire', '50', 'Actif'])
  })

  it('keeps an order typed by the administrator when the type changes, and proposes the next one otherwise', async () => {
    reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('50'))

    // Ordre non saisi : celui du type choisi (Ajustement : 10, donc 20).
    await user.selectOptions(within(dialog).getByLabelText('Type'), 'ADJUSTMENT')
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('20'))

    // Ordre saisi : il reste.
    await user.clear(within(dialog).getByLabelText('Ordre'))
    await user.type(within(dialog).getByLabelText('Ordre'), '5')
    await user.selectOptions(within(dialog).getByLabelText('Type'), 'CANCELLATION')
    await screen.findByText('Annulation', { selector: 'option' })
    expect(within(dialog).getByLabelText('Ordre')).toHaveValue('5')
  })

  it('does not offer a mandatory comment for a shipping gap, and does not send one (I-95)', async () => {
    const { calls } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs?onglet=ecart')
    await screen.findByRole('table', { name: 'Écart d’expédition' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    expect(within(dialog).getByLabelText('Type')).toHaveValue('SHIPPING_GAP')
    expect(within(dialog).queryByLabelText('Commentaire obligatoire avec ce motif')).not.toBeInTheDocument()
    expect(within(dialog).getByText('Un écart d’expédition n’a pas de commentaire.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText('Code'), 'ERREUR_PREPARATION')
    await user.type(within(dialog).getByLabelText('Libellé'), 'Erreur de préparation')
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('30'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await screen.findByText('Motif créé.')
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ type: 'SHIPPING_GAP', code: 'ERREUR_PREPARATION', label: 'Erreur de préparation', sort_order: 30 })

    // Choisir un autre type rend la case.
    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const again = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    await user.selectOptions(within(again).getByLabelText('Type'), 'CANCELLATION')
    expect(within(again).getByLabelText('Commentaire obligatoire avec ce motif')).toBeInTheDocument()
  })

  it('checks the form before sending', async () => {
    const { calls } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    await user.type(within(dialog).getByLabelText('Code'), '1x')
    await user.clear(within(dialog).getByLabelText('Ordre'))
    await user.type(within(dialog).getByLabelText('Ordre'), '70000')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByText(/Code attendu : de 2 à 30 caractères/)).toBeInTheDocument()
    expect(within(dialog).getByText('Libellé obligatoire.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ordre : nombre entier de 0 à 65 535.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Code')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((call) => call.method === 'POST')).toBe(false)
  })

  it('shows the server refusals (code or label already taken, 422) on their fields', async () => {
    reasonsApi({
      post: () =>
        problem(422, 'VALIDATION_FAILED', 'Ce code existe déjà pour ce type de motif.', {
          errors: { code: ['Ce code existe déjà pour ce type de motif.'], label: ['Ce libellé existe déjà pour ce type de motif.'] },
        }),
    })
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    await user.type(within(dialog).getByLabelText('Code'), 'DOUBLON')
    await user.type(within(dialog).getByLabelText('Libellé'), 'Commande en double')
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('50'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByText('Ce code existe déjà pour ce type de motif.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ce libellé existe déjà pour ce type de motif.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Code')).toHaveAttribute('aria-invalid', 'true')
    expect(within(dialog).getByLabelText('Libellé')).toHaveAttribute('aria-invalid', 'true')
    // Les erreurs de champ ne sont pas répétées dans une alerte globale ; la boîte reste ouverte.
    expect(within(dialog).getAllByRole('alert')).toHaveLength(2)
    expect(screen.queryByText('Motif créé.')).not.toBeInTheDocument()
  })

  it('shows a refusal that concerns no field as a message of the dialog', async () => {
    reasonsApi({ post: () => problem(403, 'FORBIDDEN', 'Action non autorisée.') })
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Nouveau motif' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau motif' })
    await user.type(within(dialog).getByLabelText('Code'), 'NOUVEAU')
    await user.type(within(dialog).getByLabelText('Libellé'), 'Nouveau')
    await waitFor(() => expect(within(dialog).getByLabelText('Ordre')).toHaveValue('50'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByText('Action non autorisée.')).toBeInTheDocument()
  })

  it('edits a reason: code and type read-only, only the changes are sent', async () => {
    const { patches, calls } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Modifier le motif « Client bloqué »' }))
    const dialog = await screen.findByRole('dialog', { name: 'Modifier le motif CLIENT_BLOQUE' })
    expect(within(dialog).getByLabelText('Type')).toHaveAttribute('readonly')
    expect(within(dialog).getByLabelText('Type')).toHaveValue('Rejet')
    expect(within(dialog).getByLabelText('Code')).toHaveAttribute('readonly')
    expect(within(dialog).getByLabelText('Code')).toHaveValue('CLIENT_BLOQUE')
    expect(within(dialog).getByLabelText('Libellé')).toHaveValue('Client bloqué')
    expect(within(dialog).getByLabelText('Ordre')).toHaveValue('20')
    expect(within(dialog).getByText(/Le code et le type ne changent pas/)).toBeInTheDocument()

    await user.clear(within(dialog).getByLabelText('Libellé'))
    await user.type(within(dialog).getByLabelText('Libellé'), 'Compte bloqué')
    await user.click(within(dialog).getByLabelText('Commentaire obligatoire avec ce motif'))
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Motif modifié.')).toBeInTheDocument()
    expect(patches).toEqual([{ id: 2, body: { label: 'Compte bloqué', requires_comment: true } }])
    // La liste est relue : le nouveau libellé et la règle de commentaire y figurent.
    const row = (await screen.findByText('Compte bloqué')).closest('tr')!
    expect(within(row).getAllByRole('cell').slice(0, 3).map((cell) => cell.textContent)).toEqual(['CLIENT_BLOQUE', 'Compte bloqué', 'Obligatoire'])
    expect(calls.filter((call) => call.method === 'GET' && call.path.endsWith('/admin/reason-codes')).length).toBeGreaterThanOrEqual(2)
  })

  it('does not write when nothing changed', async () => {
    const { calls } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Modifier le motif « Quota épuisé »' }))
    const dialog = await screen.findByRole('dialog', { name: 'Modifier le motif QUOTA_EPUISE' })
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(calls.some((call) => call.method === 'PATCH')).toBe(false)
  })

  it('does not offer the comment rule when editing a shipping gap', async () => {
    reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs?onglet=ecart')
    await screen.findByRole('table', { name: 'Écart d’expédition' })

    await user.click(screen.getByRole('button', { name: 'Modifier le motif « Casse »' }))
    const dialog = await screen.findByRole('dialog', { name: 'Modifier le motif CASSE' })
    expect(within(dialog).queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('shows the server refusal of an edit on the label field', async () => {
    reasonsApi({
      patch: () =>
        problem(422, 'VALIDATION_FAILED', 'Ce libellé existe déjà pour ce type de motif.', { errors: { label: ['Ce libellé existe déjà pour ce type de motif.'] } }),
    })
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Modifier le motif « Client bloqué »' }))
    const dialog = await screen.findByRole('dialog', { name: 'Modifier le motif CLIENT_BLOQUE' })
    await user.clear(within(dialog).getByLabelText('Libellé'))
    await user.type(within(dialog).getByLabelText('Libellé'), 'Quota épuisé')
    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByText('Ce libellé existe déjà pour ce type de motif.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Libellé')).toHaveAttribute('aria-invalid', 'true')
  })

  it('deactivates and reactivates a reason', async () => {
    const { patches } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    const table = await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Désactiver le motif « Client bloqué »' }))
    expect(await screen.findByText(/Motif désactivé : il n’est plus proposé/)).toBeInTheDocument()
    await waitFor(() => expect(within(within(table).getByText('CLIENT_BLOQUE').closest('tr')!).getByText('Désactivé')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Activer le motif « Client bloqué »' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Activer le motif « Commande en double »' }))
    expect(await screen.findByText('Motif activé : il est de nouveau proposé.')).toBeInTheDocument()
    await waitFor(() => expect(within(within(table).getByText('DOUBLON').closest('tr')!).getByText('Actif')).toBeInTheDocument())

    expect(patches).toEqual([
      { id: 2, body: { is_active: false } },
      { id: 3, body: { is_active: true } },
    ])
  })

  it('asks for a confirmation before deactivating the last active reason of a type', async () => {
    const { patches } = reasonsApi()
    const user = userEvent.setup()
    renderApp('/admin/motifs?onglet=annulation')
    await screen.findByRole('table', { name: 'Annulation' })

    await user.click(screen.getByRole('button', { name: 'Désactiver le motif « Erreur de saisie »' }))
    const dialog = await screen.findByRole('dialog', { name: 'Désactiver le dernier motif actif ?' })
    expect(dialog).toHaveTextContent('« Erreur de saisie » est le dernier motif actif de ce type')
    expect(dialog).toHaveTextContent('l’ADV ne pourra plus annuler de commande validée')
    expect(patches).toEqual([])

    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(patches).toEqual([])

    await user.click(screen.getByRole('button', { name: 'Désactiver le motif « Erreur de saisie »' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Oui, désactiver' }))
    await waitFor(() => expect(patches).toEqual([{ id: 8, body: { is_active: false } }]))
    expect(await screen.findByText(/Motif désactivé/)).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows the refusal of a deactivation as a message, and no success', async () => {
    reasonsApi({ patch: () => problem(500, 'INTERNAL_ERROR', 'Erreur interne du serveur.') })
    const user = userEvent.setup()
    renderApp('/admin/motifs')
    await screen.findByRole('table', { name: 'Rejet' })

    await user.click(screen.getByRole('button', { name: 'Désactiver le motif « Client bloqué »' }))

    expect(await screen.findByText('Erreur interne du serveur.')).toBeInTheDocument()
    expect(screen.queryByText(/Motif désactivé/)).not.toBeInTheDocument()
  })

  it('says when a type has no reason', async () => {
    reasonsApi({ list: () => json({ data: [] }) })
    renderApp('/admin/motifs?onglet=ajustement')
    expect(await screen.findByText('Aucun motif de ce type.')).toBeInTheDocument()
  })

  it('shows the error when the list cannot be read', async () => {
    reasonsApi({ list: () => problem(403, 'FORBIDDEN', 'Action non autorisée.') })
    renderApp('/admin/motifs')
    expect(await screen.findByText('Action non autorisée.')).toBeInTheDocument()
  })

  it('is read only without the right to manage the settings', async () => {
    reasonsApi({ user: makeUser('admin', { permissions: ['dashboard.view'] }) })
    renderApp('/admin/motifs')

    await screen.findByRole('table', { name: 'Rejet' })
    expect(screen.getByText(/Consultation seule/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau motif' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Modifier le motif/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Désactiver le motif/ })).not.toBeInTheDocument()
  })

  it('is not part of the ADV space (Admin only)', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('adv', { permissions: ['order.view', 'dashboard.view'] })) })
    renderApp('/adv/motifs')
    await waitFor(() => expect(screen.queryByRole('heading', { level: 1, name: 'Motifs' })).not.toBeInTheDocument())
    expect(screen.queryByRole('tablist', { name: 'Types de motifs' })).not.toBeInTheDocument()
  })
})
