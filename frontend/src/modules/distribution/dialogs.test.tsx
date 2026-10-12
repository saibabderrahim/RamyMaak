import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { DistributionGrid } from '../../shared/api/types'
import { json, problem } from '../../test/utils'
import { cellOf, COPY_URL, findGrid, GRID_URL, makeGrid, PUBLISH_URL, renderDistribution, SAVE_URL, savedCells } from './testing'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const order = (calls: Array<{ method: string; path: string }>) => calls.filter((call) => call.path !== '/sanctum/csrf-cookie').map((call) => `${call.method} ${call.path}`)

describe('distribution — « Reprendre J-1 »', () => {
  it('copies the previous distribution, overwriting, then reloads the grid', async () => {
    const { requests } = renderDistribution({ routes: { [COPY_URL]: () => json({ changed: 120, source_day: '2026-10-07' }) } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Reprendre J-1' }))
    const dialog = screen.getByRole('dialog', { name: 'Reprendre J-1' })
    // Compléter les cases vides par défaut.
    expect(within(dialog).getByRole('radio', { name: /Compléter les cases vides/ })).toBeChecked()
    await user.click(within(dialog).getByRole('radio', { name: /Écraser/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Reprendre' }))

    expect(await screen.findByText('120 bornes reprises de la répartition du 07/10.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(requests(COPY_URL)[0]!.body).toEqual({ region_id: 3, mode: 'REPLACE' })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(2))
  })

  it('fills the empty cells after saving the pending edits', async () => {
    const { calls, requests } = renderDistribution({
      routes: {
        [SAVE_URL]: ({ body }) => json(savedCells(body)),
        [COPY_URL]: () => json({ changed: 0, source_day: '2026-10-07' }),
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('12{Enter}')
    await user.click(screen.getByRole('button', { name: 'Reprendre J-1' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reprendre' }))

    expect(await screen.findByText('Aucune borne à reprendre de la répartition du 07/10.')).toBeInTheDocument()
    expect(requests(COPY_URL)[0]!.body).toEqual({ region_id: 3, mode: 'FILL' })
    const sequence = order(calls)
    expect(sequence.indexOf(SAVE_URL)).toBeLessThan(sequence.indexOf(COPY_URL))
  })

  it('explains that no distribution was sent before (422 on source)', async () => {
    renderDistribution({
      routes: {
        [COPY_URL]: () =>
          problem(422, 'VALIDATION_FAILED', 'Les données envoyées sont invalides.', {
            errors: { source: ["Aucune répartition n'a encore été envoyée pour cette région."] },
          }),
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Reprendre J-1' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reprendre' }))
    const dialog = screen.getByRole('dialog', { name: 'Reprendre J-1' })
    expect(await within(dialog).findByText("Aucune répartition n'a encore été envoyée pour cette région.")).toBeInTheDocument()

    // Échap ferme le dialogue.
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('distribution — « Valider et envoyer aux clients »', () => {
  it('saves, rereads the grid, shows the recap, then sends and reports the version', async () => {
    let published = false
    const fresh: DistributionGrid = makeGrid({
      publication: {
        first: false,
        drafts: 2,
        notified_clients: 2,
        overallocated: [{ product_id: 42, sku: 'P0042', quota: 500, sum_limits: 620 }],
      },
    })
    const { calls, requests } = renderDistribution({
      routes: {
        [SAVE_URL]: ({ body }) => json(savedCells(body)),
        [GRID_URL]: () => json(published ? makeGrid({ region: { ...makeGrid().region, distribution_version: 2 } }) : fresh),
        [PUBLISH_URL]: () => {
          published = true
          return json({ version: 2, first: false, changes: 2, notified_clients: 2, overallocated: [] })
        },
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('12{Enter}')
    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))

    const dialog = await screen.findByRole('dialog', { name: 'Valider et envoyer aux clients' })
    const recap = within(dialog).getByRole('region', { name: 'Récapitulatif de l’envoi' })
    expect(recap).toHaveTextContent('Modifications')
    expect(recap).toHaveTextContent('Seuls les clients dont une borne change sont notifiés.')
    expect(within(recap).getByText('2 bornes modifiées')).toBeInTheDocument()
    expect(within(recap).getByText('2 clients seront notifiés')).toBeInTheDocument()
    expect(within(recap).getByText('P0042 : Σ bornes 620 > quota 500')).toBeInTheDocument()
    expect(recap).toHaveTextContent('La surallocation est permise : la validation des commandes protège le quota de la région.')
    // Saisie enregistrée, puis grille relue, avant le récapitulatif.
    expect(order(calls)).toEqual(['GET /api/v1/regional/quotas', GRID_URL, SAVE_URL, GRID_URL])

    await user.click(within(dialog).getByRole('button', { name: 'Envoyer aux clients' }))
    expect(await screen.findByText('Répartition envoyée (version 2) : 2 clients notifiés.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(requests(PUBLISH_URL)[0]!.body).toEqual({ region_id: 3 })
    expect(await screen.findByText('Répartition envoyée (v2)')).toBeInTheDocument()
  })

  it('announces a first send without any limit', async () => {
    const grid = makeGrid({ cells: {}, publication: { first: true, drafts: 0, notified_clients: 3, overallocated: [] } })
    renderDistribution({
      routes: {
        [GRID_URL]: () => json({ ...grid, region: { ...grid.region, status: 'QUOTA_PUBLISHED', distribution_version: 0, distribution_published_at: null } }),
        [PUBLISH_URL]: () => json({ version: 1, first: true, changes: 0, notified_clients: 3, overallocated: [] }),
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    const dialog = await screen.findByRole('dialog', { name: 'Valider et envoyer aux clients' })
    expect(dialog).toHaveTextContent('Premier envoi')
    expect(dialog).toHaveTextContent('Aucune borne : chaque client commande dans la limite du reste région.')
    expect(dialog).toHaveTextContent('3 clients seront notifiés')
    await user.click(within(dialog).getByRole('button', { name: 'Envoyer aux clients' }))
    expect(await screen.findByText('Répartition envoyée (version 1) : 3 clients notifiés.')).toBeInTheDocument()
  })

  it('lists the refused limits and highlights their cells', async () => {
    renderDistribution({
      routes: {
        [PUBLISH_URL]: () =>
          problem(409, 'QUOTA_BELOW_VALIDATED', 'Des bornes sont inférieures aux quantités déjà validées : corrigez-les avant l\'envoi.', {
            violations: [{ client_id: 12, client: 'C0012', product_id: 42, sku: 'P0042', requested: 20, validated: 30 }],
          }),
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Envoyer aux clients' }))
    const alert = await within(dialog).findByRole('alert')
    expect(alert).toHaveTextContent("Des bornes sont inférieures aux quantités déjà validées : corrigez-les avant l'envoi.")
    expect(within(alert).getByText('C0012 · P0042 : borne 20 < 30 déjà validés')).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    const cell = cellOf('C0012', 'Orange 1 L')
    expect(cell).toHaveAttribute('data-highlighted', 'true')
    expect(cell).toHaveAttribute('title', 'envoyé : 50 · Refusée à l’envoi : corrigez cette borne.')
  })

  it('lists the limits above a quota decreased since (422)', async () => {
    renderDistribution({
      routes: {
        [PUBLISH_URL]: () =>
          problem(422, 'VALIDATION_FAILED', 'Des bornes dépassent le quota de la région.', {
            violations: [{ client_id: 51, client: 'C0051', product_id: 42, sku: 'P0042', requested: 400, quota: 300 }],
          }),
      },
    })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Envoyer aux clients' }))
    expect(await screen.findByText('C0051 · P0042 : borne 400 > quota 300')).toBeInTheDocument()
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAttribute('data-highlighted', 'true')
  })

  it('shows the message of an invalid transition', async () => {
    renderDistribution({ routes: { [PUBLISH_URL]: () => problem(409, 'INVALID_TRANSITION', 'Aucune modification à envoyer.') } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Envoyer aux clients' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Aucune modification à envoyer.')
  })

  it('is disabled with nothing to send, until a limit is edited', async () => {
    renderDistribution({
      routes: {
        [GRID_URL]: () =>
          json(makeGrid({ cells: { '51': { '42': { limit: 400, draft: null, pending: 0, validated: 0 } } }, publication: { ...makeGrid().publication, drafts: 0 } })),
      },
    })
    const user = userEvent.setup()
    await findGrid()
    expect(screen.getByRole('button', { name: 'Valider et envoyer aux clients' })).toBeDisabled()
    expect(screen.getByText(/Aucune modification à envoyer : les bornes affichées ont déjà été envoyées/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Annuler les modifications' })).toBeDisabled()

    await user.click(cellOf('C0051', 'Orange 1 L'))
    await user.keyboard('300{Enter}')
    expect(screen.getByRole('button', { name: 'Valider et envoyer aux clients' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Annuler les modifications' })).toBeEnabled()
  })

  it('does not open the recap when the reread grid has nothing to send', async () => {
    let reads = 0
    renderDistribution({
      routes: {
        [GRID_URL]: () => {
          reads++
          return json(reads === 1 ? makeGrid() : makeGrid({ publication: { ...makeGrid().publication, drafts: 0 } }))
        },
      },
    })
    const user = userEvent.setup()
    await findGrid()
    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    expect(await screen.findByText('Aucune modification à envoyer.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
