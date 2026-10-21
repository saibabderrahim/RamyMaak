import { render, screen } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, it } from 'vitest'
import { useLocationNotice } from './useLocationNotice'

function Probe() {
  const notice = useLocationNotice()
  const location = useLocation()
  return (
    <>
      <p data-testid="notice">{notice ?? 'aucun'}</p>
      <p data-testid="state">{JSON.stringify(location.state)}</p>
      <p data-testid="where">{`${location.pathname}${location.search}`}</p>
    </>
  )
}

describe('useLocationNotice', () => {
  it('shows the message once and removes it from the history entry', async () => {
    render(
      <MemoryRouter initialEntries={[{ pathname: '/client/commandes/01ABC', search: '?x=1', state: { notice: 'Commande RMK-261008-00042 envoyée.' } }]}>
        <Probe />
      </MemoryRouter>,
    )

    // Affiché, puis retiré de l'historique : un rechargement ne le remontre pas (commande validée depuis).
    expect(screen.getByTestId('notice')).toHaveTextContent('Commande RMK-261008-00042 envoyée.')
    expect(await screen.findByText('null')).toBeInTheDocument()
    expect(screen.getByTestId('notice')).toHaveTextContent('Commande RMK-261008-00042 envoyée.')
    expect(screen.getByTestId('where')).toHaveTextContent('/client/commandes/01ABC?x=1')
  })

  it('shows nothing without a message', () => {
    render(
      <MemoryRouter initialEntries={['/client/commandes']}>
        <Probe />
      </MemoryRouter>,
    )

    expect(screen.getByTestId('notice')).toHaveTextContent('aucun')
  })
})
