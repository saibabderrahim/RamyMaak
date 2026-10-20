import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createQueryClient } from '../../shared/api/queryClient'
import { meQueryKey } from '../../shared/auth/session'
import { json, makeUser, mockApi } from '../../test/utils'
import '../backoffice/i18n'
import { RoleHome } from './RoleHome'

afterEach(() => {
  vi.useRealTimers()
})

const ADV_PERMISSIONS = ['order.view', 'order.process', 'dashboard.view', 'quota.view', 'quota.edit']

function renderHome(role: 'client' | 'adv', permissions: string[] = []) {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, makeUser(role, { permissions }))
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <RoleHome role={role} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** Carte d'un titre donné (les cartes sont des <section> titrées). */
function card(title: string): HTMLElement {
  const section = screen.getByRole('heading', { name: title }).closest('section')
  if (section === null) throw new Error(`Carte « ${title} » introuvable`)
  return section
}

const upcomingItems = () =>
  within(card('Prochainement dans cet espace'))
    .getAllByRole('listitem')
    .map((item) => item.textContent)

describe('role home', () => {
  it('opens the ADV order queue and today’s monitoring, then the day calendar and today’s quotas', () => {
    // 23 h 30 UTC le 08/10 : déjà le 09/10 à Alger (jour métier).
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-08T23:30:00Z'))
    mockApi({ 'GET /api/v1/adv/orders': () => json({ data: [], meta: { counts: { VALIDATED: 0, IN_PREPARATION: 0, PREPARED: 0, SHIPPED: 0, CANCELLED: 0 } } }) })
    renderHome('adv', ADV_PERMISSIONS)

    const orders = within(card('Commandes et suivi'))
    expect(orders.getByRole('link', { name: 'File des commandes' })).toHaveAttribute('href', '/adv/commandes')
    expect(orders.getByRole('link', { name: 'Suivi de la journée' })).toHaveAttribute('href', '/adv/journees/2026-10-09/suivi')

    const shortcuts = within(card('Journées et quotas'))
    expect(shortcuts.getByRole('link', { name: 'Quotas du jour' })).toHaveAttribute('href', '/adv/journees/2026-10-09/quotas')
    expect(shortcuts.getByRole('link', { name: 'Calendrier des journées' })).toHaveAttribute('href', '/adv/journees')

    // Plus de « Prochainement » pour l'ADV : tout est livré.
    expect(screen.queryByText('Prochainement dans cet espace')).not.toBeInTheDocument()
    expect(screen.queryByText('Suivi de la journée en temps réel')).not.toBeInTheDocument()
    expect(screen.queryByText(/matrice des quotas/)).not.toBeInTheDocument()
  })

  it('shows how many orders are waiting to be prepared', async () => {
    const { calls } = mockApi({
      'GET /api/v1/adv/orders': () =>
        json({ data: [], meta: { counts: { VALIDATED: 12, IN_PREPARATION: 3, PREPARED: 0, SHIPPED: 0, CANCELLED: 0 } } }),
    })
    renderHome('adv', ADV_PERMISSIONS)

    expect(await within(card('Commandes et suivi')).findByText('12 commandes à préparer')).toBeInTheDocument()
    expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(1)
  })

  it('offers only the screens the account may open, and reads no queue without order.view', async () => {
    const { calls } = mockApi({})
    renderHome('adv', ['dashboard.view'])

    const orders = within(card('Commandes et suivi'))
    expect(orders.queryByRole('link', { name: 'File des commandes' })).not.toBeInTheDocument()
    expect(orders.getByRole('link', { name: 'Suivi de la journée' })).toBeInTheDocument()
    await waitFor(() => expect(calls).toHaveLength(0))
  })

  it('keeps the client home unchanged', () => {
    renderHome('client')

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(1)
    expect(upcomingItems()).toEqual([
      'Catalogue du jour avec vos quantités disponibles',
      'Panier, envoi et suivi de vos commandes',
      'Notifications de validation et d’expédition',
    ])
  })
})
