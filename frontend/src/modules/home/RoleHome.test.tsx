import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createQueryClient } from '../../shared/api/queryClient'
import { meQueryKey } from '../../shared/auth/session'
import { makeUser } from '../../test/utils'
import { RoleHome } from './RoleHome'

afterEach(() => {
  vi.useRealTimers()
})

function renderHome(role: 'client' | 'regional' | 'adv') {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, makeUser(role))
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
  it('opens the ADV day calendar and today’s quota matrix, no longer announced as upcoming', () => {
    // 23 h 30 UTC le 08/10 : déjà le 09/10 à Alger (jour métier).
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-08T23:30:00Z'))
    renderHome('adv')

    const shortcuts = within(card('Journées et quotas'))
    expect(shortcuts.getByRole('link', { name: 'Quotas du jour' })).toHaveAttribute('href', '/adv/journees/2026-10-09/quotas')
    expect(shortcuts.getByRole('link', { name: 'Calendrier des journées' })).toHaveAttribute('href', '/adv/journees')

    expect(upcomingItems()).toEqual(['Suivi de la journée en temps réel', 'Préparation, expédition, documents et exports'])
    expect(screen.queryByText(/matrice des quotas/)).not.toBeInTheDocument()
  })

  it('opens the regional « Quotas du jour » and « Répartition », no longer announced as upcoming', () => {
    renderHome('regional')

    const shortcuts = within(card('Quotas et répartition'))
    expect(shortcuts.getByRole('link', { name: 'Quotas du jour' })).toHaveAttribute('href', '/regional/quotas')
    expect(shortcuts.getByRole('link', { name: 'Répartition' })).toHaveAttribute('href', '/regional/repartition')
    expect(upcomingItems()).toEqual(['Ma journée : clients sans commande', 'Validation des commandes une par une'])
    expect(screen.queryByText(/quotas reçus/)).not.toBeInTheDocument()
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
