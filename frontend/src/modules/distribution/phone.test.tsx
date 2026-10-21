import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { json } from '../../test/utils'
import { findGrid, GRID_URL, makeGrid, mockScreen, PUBLISH_URL, renderDistribution, SAVE_URL, savedCells } from './testing'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const clientList = () => screen.findByRole('list', { name: /^Clients — / })

describe('distribution — phone pivot (EF-REG-03)', () => {
  it('lists the clients of a product with their limit, S and V, and a sticky summary bar', async () => {
    mockScreen(false)
    renderDistribution()
    const list = await clientList()
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Par produit' })).toHaveAttribute('aria-pressed', 'true')

    // Choix du produit par famille, avec Q et ΣB.
    const picker = screen.getByRole('combobox', { name: 'Produit' })
    expect(picker).toHaveValue('42')
    const groups = within(picker).getAllByRole('group')
    expect(groups.map((group) => group.getAttribute('label'))).toEqual(['Jus', 'Laitiers'])
    expect(within(picker).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'Orange 1 L — Q 500 · ΣB 560',
      'Pomme 1 L — Q 300 · ΣB 20',
      'Lben 1 L — Q 800 · ΣB 85',
    ])

    expect(list).toHaveAccessibleName('Clients — Orange 1 L')
    const rows = within(list).getAllByRole('listitem')
    expect(rows).toHaveLength(3)
    const first = within(rows[0]!).getByRole('textbox', { name: 'C0012 — Client A — borne Orange 1 L' })
    expect(first).toHaveValue('60')
    expect(first).toHaveAttribute('inputmode', 'numeric')
    expect(rows[0]).toHaveTextContent('S 10 · V 30')
    expect(rows[0]).toHaveTextContent('envoyé : 50')
    expect(rows[0]).toHaveAttribute('data-draft', 'true')
    expect(within(rows[1]!).getByText('Bloqué')).toBeInTheDocument()
    // Borne sous les 80 en attente de C0047 ? 100 : non ; C0051 sans commande.
    expect(rows[1]).not.toHaveAttribute('data-warning')

    const bar = screen.getByRole('region', { name: 'Synthèse — Orange 1 L' })
    expect(bar).toHaveTextContent('Q500ΣB560V300R200')
    expect(bar).toHaveAttribute('data-overallocated', 'true')
    expect(bar).toHaveAttribute('title', 'Σ bornes 560 > quota 500')
  })

  it('saves the typed limits with the same autosave and checks', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    mockScreen(false)
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await clientList()

    const input = screen.getByRole('textbox', { name: 'C0051 — Client C — borne Orange 1 L' })
    await user.clear(input)
    await user.type(input, '100')
    // ΣB suit la saisie : 60 + 100 + 100, plus de surallocation.
    const bar = screen.getByRole('region', { name: 'Synthèse — Orange 1 L' })
    expect(bar).toHaveTextContent('ΣB260')
    expect(bar).not.toHaveAttribute('data-overallocated')

    // Au-dessus du quota : refusée sur place.
    const other = screen.getByRole('textbox', { name: 'C0047 — Client B — borne Orange 1 L' })
    await user.clear(other)
    await user.type(other, '600')
    expect(other).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText('La borne ne peut pas dépasser le quota de la région (500).')).toBeInTheDocument()
    // Entrée : champ suivant.
    await user.keyboard('{Enter}')
    expect(screen.getByRole('textbox', { name: 'C0051 — Client C — borne Orange 1 L' })).toHaveFocus()

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({ region_id: 3, cells: [{ client_id: 51, product_id: 42, limit: 100 }] })
    expect(await screen.findByText(/^Enregistré à /)).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'C0051 — Client C — borne Orange 1 L' })).toHaveValue('100')
  })

  it('switches product, and lists the products of a client with the available quantity', async () => {
    mockScreen(false)
    renderDistribution()
    const user = userEvent.setup()
    await clientList()

    await user.selectOptions(screen.getByRole('combobox', { name: 'Produit' }), '57')
    expect(screen.getByRole('list', { name: 'Clients — Lben 1 L' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Synthèse — Lben 1 L' })).toHaveTextContent('Q800ΣB85V80R720')
    // Borne 5 sous les 10 en attente : avertissement.
    const warned = screen.getByRole('textbox', { name: 'C0012 — Client A — borne Lben 1 L' }).closest('li')!
    expect(warned).toHaveAttribute('data-warning', 'true')
    expect(warned).toHaveTextContent('Borne inférieure à la quantité engagée (10)')

    await user.click(screen.getByRole('button', { name: 'Par client' }))
    const picker = screen.getByRole('combobox', { name: 'Client' })
    expect(picker).toHaveValue('12')
    // D sur la borne publiée 50, pas sur le brouillon 60 : min(50 − 10 − 30, 200) = 10.
    expect(screen.getByRole('textbox', { name: 'Orange 1 L — borne C0012 Client A' }).closest('li')).toHaveTextContent('Disponible : 10')
    await user.type(screen.getByRole('searchbox', { name: 'Rechercher un client' }), 'C0047')
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Client' })).toHaveValue('47'))
    expect(screen.getByText('Bloqué')).toBeInTheDocument()

    const juices = screen.getByRole('list', { name: 'Produits de Client B — Jus' })
    const orange = within(juices).getByRole('textbox', { name: 'Orange 1 L — borne C0047 Client B' })
    expect(orange).toHaveValue('100')
    const orangeRow = orange.closest('li')!
    // D = min(100 − 80 − 0, 200) = 20.
    expect(orangeRow).toHaveTextContent('Disponible : 20')
    expect(orangeRow).toHaveTextContent('Quota 500 · Reste 200')
    // Pomme sans borne, reste épuisé : 0.
    expect(within(juices).getByRole('textbox', { name: 'Pomme 1 L — borne C0047 Client B' }).closest('li')).toHaveTextContent('Disponible : 0')
  })

  it('keeps D on the published limit while a limit is typed, and only flags a published limit above a decreased quota', async () => {
    mockScreen(false)
    const grid = makeGrid({ products: makeGrid().products.map((product) => (product.id === 42 ? { ...product, quota: 350, remaining: 50 } : product)) })
    renderDistribution({ routes: { [GRID_URL]: () => json(grid), [SAVE_URL]: ({ body }) => json(savedCells(body, grid)) } })
    const user = userEvent.setup()
    await clientList()

    // Borne envoyée 400 au-dessus du quota diminué (350, RG-Q06) : signalée, pas en erreur.
    const outdated = screen.getByRole('textbox', { name: 'C0051 — Client C — borne Orange 1 L' })
    expect(outdated).toHaveAttribute('aria-invalid', 'false')
    expect(outdated.closest('li')).toHaveAttribute('data-warning', 'true')
    expect(outdated.closest('li')).not.toHaveAttribute('data-error')
    expect(outdated.closest('li')).toHaveTextContent('Borne envoyée supérieure au quota actuel de la région (350), diminué depuis')

    await user.click(screen.getByRole('button', { name: 'Par client' }))
    await user.type(screen.getByRole('searchbox', { name: 'Rechercher un client' }), 'C0051')
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Client' })).toHaveValue('51'))
    const lben = screen.getByRole('textbox', { name: 'Lben 1 L — borne C0051 Client C' })
    await user.type(lben, '10')
    // Saisie non envoyée aux clients : D reste le reste région (pas de borne publiée, politique LIBRE).
    expect(lben.closest('li')).toHaveTextContent('Disponible : 720')
  })

  it('follows the width of the screen', async () => {
    const screenSize = mockScreen(true)
    renderDistribution()
    await findGrid()
    act(() => screenSize.resize(false))
    expect(await clientList()).toBeInTheDocument()
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
    act(() => screenSize.resize(true))
    expect(await findGrid()).toBeInTheDocument()
  })

  it('keeps the actions and the read-only mode on the phone', async () => {
    mockScreen(false)
    renderDistribution({
      routes: {
        [GRID_URL]: () => json(makeGrid()),
        [PUBLISH_URL]: () => json({ version: 2, first: false, changes: 1, notified_clients: 1, overallocated: [] }),
      },
    })
    const user = userEvent.setup()
    await clientList()
    expect(screen.getByRole('button', { name: 'Reprendre J-1' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Valider et envoyer aux clients' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Envoyer aux clients' }))
    expect(await screen.findByText('Répartition envoyée (version 2) : 1 client notifié.')).toBeInTheDocument()
  })

  it('disables the inputs without can_edit', async () => {
    mockScreen(false)
    renderDistribution({ routes: { [GRID_URL]: () => json(makeGrid({ can_edit: false, can_publish: false })) } })
    await clientList()
    expect(screen.getByRole('textbox', { name: 'C0012 — Client A — borne Orange 1 L' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Valider et envoyer aux clients' })).not.toBeInTheDocument()
  })
})
