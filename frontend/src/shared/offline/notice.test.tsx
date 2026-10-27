import { act, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { OfflineDataNotice } from './OfflineDataNotice'

// Bandeau des écrans lus hors ligne (lot 8, contrat §6.1).

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const offline = () => vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)

describe('OfflineDataNotice', () => {
  it('says nothing online', () => {
    const { container } = render(<OfflineDataNotice updatedAt={Date.parse('2026-10-08T08:42:00Z')} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('gives the time of the data read last, in the business time zone, with what waits for the network', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-08T11:00:00Z'))
    offline()
    render(<OfflineDataNotice updatedAt={Date.parse('2026-10-08T08:42:00Z')} />)

    expect(screen.getByText('Hors ligne — données de 09:42')).toBeInTheDocument()
    expect(screen.getByText('Les actions qui modifient des données reprendront au retour du réseau.')).toBeInTheDocument()
  })

  it('gives the day too for data read on another day, and the hint of the screen', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-08T11:00:00Z'))
    offline()
    render(<OfflineDataNotice updatedAt={Date.parse('2026-10-05T16:05:00Z')} hint="Votre panier reste sur cet appareil." />)

    expect(screen.getByText('Hors ligne — données du 05/10 à 17:05')).toBeInTheDocument()
    expect(screen.getByText('Votre panier reste sur cet appareil.')).toBeInTheDocument()
  })

  it('says when nothing is saved on the device for the screen', () => {
    offline()
    render(<OfflineDataNotice updatedAt={0} />)
    expect(screen.getByRole('status')).toHaveTextContent('Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.')
  })

  it('follows the network', () => {
    const spy = offline()
    render(<OfflineDataNotice updatedAt={Date.now()} />)
    expect(screen.getByText(/^Hors ligne — données de/)).toBeInTheDocument()

    spy.mockReturnValue(true)
    act(() => {
      window.dispatchEvent(new Event('online'))
    })
    expect(screen.queryByText(/^Hors ligne/)).not.toBeInTheDocument()
  })
})
