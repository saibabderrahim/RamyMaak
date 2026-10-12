import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { CurrentUser } from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { cellOf, DAY, makeGrid, summaryOf } from '../../distribution/testing'
import BackOfficeDistributionPage from './BackOfficeDistributionPage'

const GRID_URL = `GET /api/v1/adv/days/${DAY}/regions/3/grid`

const adv = () => makeUser('adv', { permissions: ['quota.view', 'quota.edit', 'quota.publish', 'limit.view', 'day.manage'], perimeter: { all_regions: true, region_ids: [] } })
const admin = () => makeUser('admin', { permissions: ['quota.view', 'limit.view', 'day.extend-cutoff'] })

// Lecture seule : jamais de saisie ni d'envoi pour le back-office.
const backOfficeGrid = () => makeGrid({ can_edit: false, can_publish: false })

afterEach(() => {
  vi.restoreAllMocks()
})

function renderPage(user: CurrentUser, routes: Parameters<typeof mockApi>[0] = {}, path = `/${user.role}/journees/${DAY}/repartition/3`) {
  const mock = mockApi({ 'GET /api/v1/auth/me': () => json(user), [GRID_URL]: () => json(backOfficeGrid()), ...routes })
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/adv/journees/:day/repartition/:regionId" element={<BackOfficeDistributionPage />} />
          <Route path="/admin/journees/:day/repartition/:regionId" element={<BackOfficeDistributionPage />} />
          <Route path="*" element={null} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return mock
}

describe('back-office — distribution grid (read-only)', () => {
  it('shows the grid of a region for the ADV, without entry nor actions', async () => {
    renderPage(adv())
    expect(await screen.findByRole('heading', { name: 'Répartition — Centre — 08/10/2026' })).toBeInTheDocument()
    const grid = screen.getByRole('grid', { name: 'Répartition de la région Centre du 08/10/2026, par client et par produit' })
    expect(grid).toHaveAttribute('aria-readonly', 'true')
    expect(screen.getByRole('link', { name: 'Retour à la journée du 08/10/2026' })).toHaveAttribute('href', `/adv/journees/${DAY}`)

    // En-tête et récapitulatif de l'envoi.
    expect(screen.getByText('Répartition publiée')).toBeInTheDocument()
    expect(screen.getByText('Répartition avant 11:15 (prolongée) · Commande avant 13:00')).toBeInTheDocument()
    const recap = screen.getByRole('region', { name: 'Envoi aux clients' })
    expect(recap).toHaveTextContent('Répartition envoyée aux clients (version 1)')
    expect(recap).toHaveTextContent('1 borne en attente d’envoi')
    expect(within(recap).getByText('P0042 : Σ bornes 560 > quota 500')).toBeInTheDocument()

    // Synthèse et surallocation.
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).toHaveAttribute('data-overallocated', 'true')
    expect(summaryOf('Reste (R)', 'Pomme 1 L')).toHaveAttribute('data-exhausted', 'true')
    expect(cellOf('C0012', 'Orange 1 L')).toHaveAttribute('title', 'envoyé : 50')

    // Aucune action de saisie.
    expect(screen.queryByRole('button', { name: 'Valider et envoyer aux clients' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reprendre J-1' })).not.toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Actions de masse' })).not.toBeInTheDocument()
    const user = userEvent.setup()
    await user.click(cellOf('C0051', 'Lben 1 L'))
    expect(cellOf('C0051', 'Lben 1 L')).toHaveFocus()
    await user.keyboard('4{Delete}')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
    expect(cellOf('C0051', 'Lben 1 L')).toHaveAccessibleName(/pas de borne, lecture seule$/)
  })

  it('offers the three display modes, the search and the category filter', async () => {
    renderPage(adv())
    const user = userEvent.setup()
    await screen.findByRole('grid')

    await user.click(screen.getByRole('button', { name: 'Commandes' }))
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^S80 · V0$/)
    await user.click(screen.getByRole('button', { name: 'Reste' }))
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^20$/)

    await user.selectOptions(screen.getByRole('combobox', { name: 'Catégorie' }), 'Grossiste')
    expect(await screen.findByText('1 client affiché sur 3')).toBeInTheDocument()
    await user.selectOptions(screen.getByRole('combobox', { name: 'Catégorie' }), '')
    await user.type(screen.getByRole('searchbox', { name: 'Rechercher un client' }), 'c0051')
    expect(await screen.findByText('1 client affiché sur 3')).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: /C0051/ })).toBeInTheDocument()
  })

  it('links back to the day for the Admin', async () => {
    renderPage(admin())
    expect(await screen.findByRole('grid')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour à la journée du 08/10/2026' })).toHaveAttribute('href', `/admin/journees/${DAY}`)
  })

  it('tells when the distribution has not been sent yet and the quotas are awaited', async () => {
    const grid = backOfficeGrid()
    renderPage(adv(), {
      [GRID_URL]: () =>
        json({
          ...grid,
          region: { ...grid.region, status: 'QUOTA_DRAFT', distribution_version: 0, distribution_published_at: null },
          products: [],
          cells: {},
          publication: { first: true, drafts: 0, notified_clients: 3, overallocated: [] },
        }),
    })
    expect(await screen.findByText('En attente des quotas : la répartition commencera après leur publication.')).toBeInTheDocument()
    expect(screen.getByText('Répartition pas encore envoyée aux clients')).toBeInTheDocument()
    expect(screen.getByText('Aucune borne en attente d’envoi')).toBeInTheDocument()
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('answers « Région ou journée introuvable » with the link back to the day', async () => {
    renderPage(admin(), { [GRID_URL]: () => problem(404, 'NOT_FOUND', 'Ressource introuvable.') })
    expect(await screen.findByRole('heading', { name: 'Région ou journée introuvable' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour à la journée du 08/10/2026' })).toHaveAttribute('href', `/admin/journees/${DAY}`)
  })

  it('is wired to the ADV and Admin routes', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(adv()), [GRID_URL]: () => json(backOfficeGrid()) })
    renderApp(`/adv/journees/${DAY}/repartition/3`)
    expect(await screen.findByRole('grid', {}, { timeout: 10_000 })).toBeInTheDocument()
  }, 20_000)
})
