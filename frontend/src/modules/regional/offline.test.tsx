import { onlineManager } from '@tanstack/react-query'
import { act, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Client } from '../../shared/api/types'
import { json, makeUser, mockApi, renderApp } from '../../test/utils'
import { renderMyDay } from './home/testing'

// Écrans du régional lus hors ligne (lot 8, contrat §6.1 et §8) : bandeau « données de HH:MM », écritures désactivées.

const slow = { timeout: 5000 }

afterEach(() => {
  onlineManager.setOnline(true)
  vi.restoreAllMocks()
})

function goOffline() {
  vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
  act(() => {
    onlineManager.setOnline(false)
    window.dispatchEvent(new Event('offline'))
  })
}

const baraka = {
  id: 12,
  code: 'C0012',
  name: 'Superette El Baraka',
  trade_name: null,
  category: null,
  region: { id: 3, code: 'CENTRE', name: 'Centre' },
  pending_region_change: null,
  address: null,
  wilaya: null,
  commune: 'Bab Ezzouar',
  lat: null,
  lng: null,
  phone: '0550123456',
  phone2: null,
  email: null,
  nif: null,
  nis: null,
  rc: null,
  ai: null,
  status: 'ACTIVE',
  status_label: 'Actif',
  status_reason: null,
  status_changed_at: null,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
  accounts: [{ id: 41, username: 'c0012', is_active: true, last_login_at: null }],
} satisfies Client

const regional = makeUser('regional', {
  name: 'Karim Saïdi',
  permissions: ['region.view', 'client.view', 'product.view'],
  perimeter: { all_regions: false, region_ids: [3] },
})

describe('regional screens offline', () => {
  it('« Ma journée » says from when the data shown offline dates', async () => {
    renderMyDay()
    expect(await screen.findByRole('region', { name: 'Centre' })).toBeInTheDocument()
    expect(screen.queryByText(/^Hors ligne/)).not.toBeInTheDocument()

    goOffline()
    expect(screen.getByText(/^Hors ligne — données de \d\d:\d\d$/)).toBeInTheDocument()
    expect(screen.getByText('Les actions qui modifient des données reprendront au retour du réseau.')).toBeInTheDocument()
  })

  it('« Ma journée » never read on the device says so offline, instead of waiting', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    onlineManager.setOnline(false)
    const { requests } = renderMyDay()

    expect(await screen.findByText('Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.')).toBeInTheDocument()
    expect(screen.queryByText('Chargement…')).not.toBeInTheDocument()
    expect(requests('GET /api/v1/regional/dashboard')).toHaveLength(0)
  })

  it('keeps the client card readable offline, its password reset waiting for the network', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(regional), 'GET /api/v1/clients/12': () => json(baraka) })
    renderApp('/regional/clients/12')
    const reset = await screen.findByRole('button', { name: 'Réinitialiser le mot de passe du compte c0012' }, slow)
    expect(reset).toBeEnabled()

    goOffline()
    expect(screen.getByText(/^Hors ligne — données de \d\d:\d\d$/)).toBeInTheDocument()
    expect(reset).toBeDisabled()
    expect(screen.getByRole('heading', { name: 'Superette El Baraka' })).toBeInTheDocument()
  })
})
