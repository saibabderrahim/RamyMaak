import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { json, problem } from '../../test/utils'
import { gridKey } from './api'
import {
  CENTRE,
  cellOf,
  centreEntry,
  DAY,
  estEntry,
  findGrid,
  GRID_URL,
  makeGrid,
  opened,
  REGIONS_URL,
  renderDistribution,
  SAVE_URL,
  savedCells,
  storedDrafts,
  summaryOf,
  text,
} from './testing'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const editor = (code: string, name: string, productName: string) => screen.getByRole('textbox', { name: `Borne ${code} ${name} — ${productName}` })

describe('distribution grid — display', () => {
  it('shows the header, family groups, the five pinned summary rows and the cells', async () => {
    renderDistribution()
    await findGrid()

    expect(screen.getByRole('heading', { name: 'Centre (CENTRE)' })).toBeInTheDocument()
    expect(screen.getByText('Répartition publiée')).toBeInTheDocument()
    expect(screen.getByText('Répartition envoyée (v1)')).toBeInTheDocument()
    // Heure limite de répartition prolongée pour la région (RG-A05).
    expect(screen.getByText('Répartition avant 11:15 (prolongée) · Commande avant 13:00')).toBeInTheDocument()

    expect(screen.getByRole('columnheader', { name: 'Jus' })).toHaveAttribute('colspan', '2')
    expect(screen.getByRole('columnheader', { name: 'Laitiers' })).toHaveAttribute('colspan', '1')
    expect(screen.getByRole('columnheader', { name: /Orange 1 L/ })).toHaveAttribute('title', 'Jus Orange 1 L (désignation) — P0042 — Fardeau 6 × 1 L')

    // Synthèse : Q, ΣB (valeurs affichées, brouillon compris), S, V, R.
    expect(summaryOf('Quota (Q)', 'Orange 1 L')).toHaveTextContent(/^500$/)
    expect(summaryOf('Quota (Q)', 'Lben 1 L')).toHaveTextContent(/^800$/)
    const sum = summaryOf('Σ bornes (ΣB)', 'Orange 1 L')
    expect(sum).toHaveTextContent('560')
    expect(sum).toHaveAttribute('data-overallocated', 'true')
    expect(sum).toHaveAttribute('title', 'Σ bornes 560 > quota 500')
    expect(summaryOf('Σ bornes (ΣB)', 'Pomme 1 L')).toHaveTextContent(/^20$/)
    expect(summaryOf('Σ bornes (ΣB)', 'Pomme 1 L')).not.toHaveAttribute('data-overallocated')
    expect(summaryOf('Σ bornes (ΣB)', 'Lben 1 L')).toHaveTextContent(/^85$/)
    expect(summaryOf('En attente (S)', 'Orange 1 L')).toHaveTextContent(/^120$/)
    expect(summaryOf('Validé (V)', 'Orange 1 L')).toHaveTextContent(/^300$/)
    expect(summaryOf('Reste (R)', 'Orange 1 L')).toHaveTextContent(/^200$/)
    // Reste épuisé : en rouge.
    const exhausted = summaryOf('Reste (R)', 'Pomme 1 L')
    expect(exhausted).toHaveTextContent(/^0$/)
    expect(exhausted).toHaveAttribute('data-exhausted', 'true')
    expect(exhausted).toHaveAttribute('title', 'Reste région épuisé : plus aucune quantité ne peut être validée.')

    // Cellule en brouillon : valeur affichée, rappel S et V, borne envoyée en info-bulle.
    const draft = cellOf('C0012', 'Orange 1 L')
    expect(draft).toHaveTextContent('60S10 V30')
    expect(draft).toHaveAttribute('data-draft', 'true')
    expect(draft).toHaveAttribute('title', 'envoyé : 50')
    expect(draft).toHaveAccessibleName('C0012 Client A — Orange 1 L : 60, en attente 10, validé 30, modifiée, non envoyée (envoyé : 50)')
    // Case vide : pas de borne.
    expect(cellOf('C0051', 'Pomme 1 L')).toHaveTextContent(/^$/)
    expect(cellOf('C0051', 'Pomme 1 L')).toHaveAccessibleName('C0051 Client C — Pomme 1 L : pas de borne')
    // Borne sous la quantité engagée E = S + V : avertissement (RG-B07).
    const warning = cellOf('C0012', 'Lben 1 L')
    expect(warning).toHaveAttribute('data-warning', 'true')
    expect(warning).toHaveAttribute('title', 'Borne inférieure à la quantité engagée (10) : la commande en attente devra être réduite à la validation.')

    // Client bloqué.
    const blocked = screen.getByRole('rowheader', { name: /C0047/ })
    expect(within(blocked).getByText('Bloqué')).toBeInTheDocument()
    expect(screen.getAllByRole('row')).toHaveLength(7 + 3)
  })

  it('switches between the Limits, Orders and Remaining modes', async () => {
    renderDistribution()
    const user = userEvent.setup()
    await findGrid()

    expect(screen.getByRole('button', { name: 'Bornes' })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: 'Commandes' }))
    expect(screen.getByRole('button', { name: 'Commandes' })).toHaveAttribute('aria-pressed', 'true')
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent(/^S10 · V30$/)
    expect(cellOf('C0051', 'Orange 1 L')).toHaveTextContent(/^$/)
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAccessibleName(/^C0051 Client C — Orange 1 L : en attente 0, validé 0/)

    await user.click(screen.getByRole('button', { name: 'Reste' }))
    // D sur la borne publiée 50 (pas le brouillon 60) : min(50 − 10 − 30, R 200) = 10.
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent(/^10$/)
    expect(cellOf('C0012', 'Orange 1 L')).toHaveAccessibleName(/^C0012 Client A — Orange 1 L : disponible 10/)
    // Sans borne, politique LIBRE : reste région.
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^720$/)
    // Produit épuisé : 0.
    expect(cellOf('C0051', 'Pomme 1 L')).toHaveTextContent(/^0$/)
    // Borne entièrement validée : 0.
    expect(cellOf('C0047', 'Lben 1 L')).toHaveTextContent(/^0$/)

    // Pas de saisie hors du mode Bornes.
    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('7')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
  })

  it('computes D with the REQUIRED policy', async () => {
    renderDistribution({ routes: { [GRID_URL]: () => json(makeGrid({ policy: 'REQUIRED' })) } })
    const user = userEvent.setup()
    await findGrid()
    await user.click(screen.getByRole('button', { name: 'Reste' }))
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^0$/)
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent(/^10$/)
  })

  it('computes D on the published limit, never on an entry not sent to the clients yet (§5.1)', async () => {
    renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('10{Enter}')
    await user.click(cellOf('C0047', 'Orange 1 L'))
    await user.keyboard('{Delete}')
    await user.click(screen.getByRole('button', { name: 'Reste' }))
    // Pas de borne publiée (politique LIBRE) : R, malgré la borne 10 saisie.
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^720$/)
    // Borne publiée 100 (S 80) : 20, malgré la borne retirée en brouillon.
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^20$/)
  })

  it('filters the rows by search and category, ΣB still covering every client', async () => {
    renderDistribution()
    const user = userEvent.setup()
    await findGrid()

    expect(screen.getByText('3 clients affichés sur 3')).toBeInTheDocument()
    await user.type(screen.getByRole('searchbox', { name: 'Rechercher un client' }), 'client b')
    await waitFor(() => expect(screen.getByText('1 client affiché sur 3')).toBeInTheDocument())
    expect(screen.queryByRole('rowheader', { name: /C0012/ })).not.toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: /C0047/ })).toBeInTheDocument()
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).toHaveTextContent('560')

    await user.clear(screen.getByRole('searchbox', { name: 'Rechercher un client' }))
    await user.selectOptions(screen.getByRole('combobox', { name: 'Catégorie' }), 'Grossiste')
    await waitFor(() => expect(screen.getByText('1 client affiché sur 3')).toBeInTheDocument())
    expect(screen.getByRole('rowheader', { name: /C0012/ })).toBeInTheDocument()

    await user.type(screen.getByRole('searchbox', { name: 'Rechercher un client' }), 'zzz')
    expect(await screen.findByText('Aucun client ne correspond à la recherche.')).toBeInTheDocument()
  })
})

describe('distribution grid — keyboard entry', () => {
  it('moves like a spreadsheet; typing replaces, F2 edits, Enter and Tab validate', async () => {
    renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0012', 'Orange 1 L'))
    expect(cellOf('C0012', 'Orange 1 L')).toHaveFocus()
    await user.keyboard('{ArrowRight}')
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveFocus()
    await user.keyboard('{ArrowDown}')
    expect(cellOf('C0047', 'Pomme 1 L')).toHaveFocus()
    await user.keyboard('{Tab}{Tab}')
    // Tab au bout de la ligne : début de la ligne suivante ; Maj+Tab revient.
    expect(cellOf('C0051', 'Orange 1 L')).toHaveFocus()
    await user.keyboard('{Shift>}{Tab}{/Shift}')
    expect(cellOf('C0047', 'Lben 1 L')).toHaveFocus()
    await user.keyboard('{ArrowUp}{ArrowLeft}{ArrowLeft}')
    expect(cellOf('C0012', 'Orange 1 L')).toHaveFocus()

    // Une frappe ouvre la saisie et remplace la valeur ; Entrée valide et descend.
    await user.keyboard('7')
    expect(editor('C0012', 'Client A', 'Orange 1 L')).toHaveValue('7')
    await user.keyboard('5{Enter}')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent('75S10 V30')
    expect(cellOf('C0047', 'Orange 1 L')).toHaveFocus()
    // ΣB recalculé à chaque saisie : 75 + 100 + 400.
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).toHaveTextContent('575')

    // F2 garde la valeur ; Tab valide et passe à droite.
    await user.keyboard('{F2}')
    expect(editor('C0047', 'Client B', 'Orange 1 L')).toHaveValue('100')
    await user.keyboard('{Backspace}{Backspace}{Backspace}90{Tab}')
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent('90')
    expect(cellOf('C0047', 'Pomme 1 L')).toHaveFocus()

    // Double-clic : modification de la valeur affichée.
    await user.dblClick(cellOf('C0051', 'Orange 1 L'))
    expect(editor('C0051', 'Client C', 'Orange 1 L')).toHaveValue('400')
    await user.keyboard('{Backspace}{Backspace}{Backspace}1 200{Enter}')
    // Au-dessus du quota (500) : refusée sur place.
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAttribute('data-error', 'true')
  })

  it('clears a cell with Delete or Backspace (no limit), cancels with Escape', async () => {
    renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0051', 'Orange 1 L'))
    await user.keyboard('{Delete}')
    expect(cellOf('C0051', 'Orange 1 L')).toHaveTextContent(/^$/)
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAccessibleName(/^C0051 Client C — Orange 1 L : pas de borne, modifiée, non envoyée \(envoyé : 400\)/)
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAttribute('title', 'envoyé : 400')
    // ΣB : 60 + 100, plus de surallocation.
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).toHaveTextContent(/^160$/)
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).not.toHaveAttribute('data-overallocated')

    // Échap annule la saisie en cours.
    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('9')
    expect(editor('C0012', 'Client A', 'Lben 1 L')).toHaveValue('9')
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent('5S10 V0')
    expect(cellOf('C0012', 'Lben 1 L')).toHaveFocus()

    await user.keyboard('{ArrowUp}{Backspace}')
    // Première ligne : la flèche haut reste sur la cellule.
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent('S10 V0')
    expect(cellOf('C0012', 'Lben 1 L')).toHaveAccessibleName(/: pas de borne/)
  })

  it('refuses a value above the quota or below the validated quantity, without sending it', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Orange 1 L'))
    await user.keyboard('600{ArrowRight}10{Enter}')
    const above = cellOf('C0012', 'Orange 1 L')
    expect(above).toHaveTextContent('600')
    expect(above).toHaveAttribute('aria-invalid', 'true')
    // Brouillon enregistré (60) : la borne envoyée reste rappelée.
    expect(above).toHaveAttribute('title', 'envoyé : 50 · La borne ne peut pas dépasser le quota de la région (500).')
    // Répartition déjà envoyée : pas sous le validé (20) du client (RG-B07).
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveAttribute('title', 'Borne inférieure aux 20 déjà validés pour ce client.')
    expect(screen.getByText('2 bornes en erreur, non enregistrées')).toBeInTheDocument()
    // Rien n'est parti : pas d'« Enregistrement… ».
    expect(screen.queryByText('Enregistrement…')).not.toBeInTheDocument()
    // ΣB garde la valeur du serveur.
    expect(summaryOf('Σ bornes (ΣB)', 'Orange 1 L')).toHaveTextContent('560')

    await act(() => vi.advanceTimersByTimeAsync(1000))
    expect(requests(SAVE_URL)).toHaveLength(0)

    // Échap sur la cellule en erreur : retour à la valeur du serveur.
    await user.keyboard('{ArrowUp}{Escape}')
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveTextContent('20')
    expect(cellOf('C0012', 'Pomme 1 L')).not.toHaveAttribute('aria-invalid')
    expect(screen.getByText('1 borne en erreur, non enregistrée')).toBeInTheDocument()
  })

  it('pastes a block copied from Excel at the active cell, clipped to the grid', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Orange 1 L'))
    await user.paste('70\t25\r\n1 090\t\r\n')
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent(/^70/)
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveTextContent(/^25/)
    // Au-dessus du quota : refusée ; case vide : pas de borne (inchangée ici).
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^1 090/)
    expect(cellOf('C0047', 'Orange 1 L')).toHaveAttribute('data-error', 'true')
    expect(cellOf('C0047', 'Pomme 1 L')).toHaveTextContent(/^$/)
    expect(screen.getByText('3 cellules collées.')).toBeInTheDocument()

    // Collé depuis la dernière ligne : ce qui dépasse est ignoré.
    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.paste('9\t8\n7\n')
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^9$/)

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      region_id: 3,
      cells: [
        { client_id: 12, product_id: 42, limit: 70 },
        { client_id: 12, product_id: 43, limit: 25 },
        { client_id: 51, product_id: 57, limit: 9 },
      ],
    })
  })

  it('fills a selection with one pasted value', async () => {
    renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup()
    await findGrid()

    await user.click(cellOf('C0047', 'Orange 1 L'))
    await user.keyboard('{Shift>}{ArrowDown}{/Shift}')
    expect(cellOf('C0047', 'Orange 1 L')).toHaveAttribute('aria-selected', 'true')
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAttribute('aria-selected', 'true')
    await user.paste('150')
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^150/)
    expect(cellOf('C0051', 'Orange 1 L')).toHaveTextContent(/^150/)
  })
})

describe('distribution grid — quota decreased below a published limit (RG-Q06)', () => {
  // Quota d'Orange diminué à 350 par l'ADV : la borne publiée 400 de C0051 le dépasse.
  const lowered = (overrides: Parameters<typeof makeGrid>[0] = {}) =>
    makeGrid({ products: makeGrid().products.map((product) => (product.id === 42 ? { ...product, quota: 350, remaining: 50 } : product)), ...overrides })

  it('flags the published limit without an error, and accepts it typed again', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const grid = lowered()
    const { requests } = renderDistribution({ routes: { [GRID_URL]: () => json(grid), [SAVE_URL]: ({ body }) => json(savedCells(body, grid)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    const outdated = cellOf('C0051', 'Orange 1 L')
    expect(outdated).toHaveTextContent(/^!400$/)
    expect(outdated).not.toHaveAttribute('data-error')
    expect(outdated).not.toHaveAttribute('aria-invalid')
    expect(outdated).toHaveAttribute('data-warning', 'true')
    expect(outdated).toHaveAttribute('title', 'Borne envoyée supérieure au quota actuel de la région (350), diminué depuis : la validation des commandes protège le quota.')
    expect(screen.queryByText(/en erreur/)).not.toBeInTheDocument()

    // Modifiée, puis ressaisie à la borne envoyée : acceptée, plus de brouillon.
    await user.click(outdated)
    await user.keyboard('200{Enter}')
    expect(cellOf('C0051', 'Orange 1 L')).toHaveAttribute('data-draft', 'true')
    await user.click(cellOf('C0051', 'Orange 1 L'))
    await user.keyboard('400{Enter}')
    expect(cellOf('C0051', 'Orange 1 L')).not.toHaveAttribute('data-error')
    expect(cellOf('C0051', 'Orange 1 L')).not.toHaveAttribute('data-draft')
    // Une autre valeur au-dessus du quota reste refusée.
    await user.click(cellOf('C0047', 'Orange 1 L'))
    await user.keyboard('360{Enter}')
    expect(cellOf('C0047', 'Orange 1 L')).toHaveAttribute('data-error', 'true')

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({ region_id: 3, cells: [{ client_id: 51, product_id: 42, limit: 400 }] })
  })

  it('reverts a draft to a published limit above the quota without flagging it as an error', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const base = lowered()
    const grid = lowered({ cells: { ...base.cells, '51': { '42': { limit: 400, draft: { value: 200 }, pending: 0, validated: 0 } } } })
    const { requests } = renderDistribution({ routes: { [GRID_URL]: () => json(grid), [SAVE_URL]: ({ body }) => json(savedCells(body, grid)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(screen.getByRole('button', { name: 'Annuler les modifications' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Annuler les modifications' }))
    const reverted = cellOf('C0051', 'Orange 1 L')
    expect(reverted).toHaveTextContent(/^!400$/)
    expect(reverted).not.toHaveAttribute('data-error')
    expect(reverted).not.toHaveAttribute('data-draft')
    expect(screen.queryByText(/en erreur/)).not.toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      region_id: 3,
      cells: [
        { client_id: 12, product_id: 42, limit: 50 },
        { client_id: 51, product_id: 42, limit: 400 },
      ],
    })
    expect(cellOf('C0051', 'Orange 1 L')).not.toHaveAttribute('data-error')
  })
})

describe('distribution grid — mass actions', () => {
  it('applies a value to the column of the active cell for the displayed clients', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.selectOptions(screen.getByRole('combobox', { name: 'Catégorie' }), 'Détaillant')
    await waitFor(() => expect(screen.getByText('2 clients affichés sur 3')).toBeInTheDocument())
    await user.click(cellOf('C0047', 'Lben 1 L'))
    await user.type(screen.getByRole('textbox', { name: 'Valeur à appliquer' }), '30')
    await user.click(screen.getByRole('button', { name: 'Appliquer à « Lben 1 L » (2 clients affichés)' }))

    // C0047 a déjà 80 validés : refusé (RG-B07).
    expect(cellOf('C0047', 'Lben 1 L')).toHaveAttribute('data-error', 'true')
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^30$/)
    expect(screen.getByText('1 borne modifiée. 1 valeur refusée (en rouge).')).toBeInTheDocument()

    // Valeur refusée sur place.
    await user.clear(screen.getByRole('textbox', { name: 'Valeur à appliquer' }))
    await user.type(screen.getByRole('textbox', { name: 'Valeur à appliquer' }), '1,5')
    await user.click(screen.getByRole('button', { name: /^Appliquer à « Lben 1 L »/ }))
    expect(screen.getByRole('alert')).toHaveTextContent('Nombre entier de 0 à 1 000 000 attendu, ou vide pour « pas de borne ».')

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({ region_id: 3, cells: [{ client_id: 51, product_id: 57, limit: 30 }] })
  })

  it('applies a value to a selection (Shift+arrows, Shift+click) and clears a column', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    expect(screen.getByRole('button', { name: 'Appliquer à la sélection (1 case)' })).toBeDisabled()
    await user.click(cellOf('C0012', 'Orange 1 L'))
    await user.keyboard('{Shift>}{ArrowRight}{/Shift}')
    await user.keyboard('{Shift>}')
    await user.click(cellOf('C0047', 'Pomme 1 L'))
    await user.keyboard('{/Shift}')
    expect(screen.getByRole('button', { name: 'Appliquer à la sélection (4 cases)' })).toBeEnabled()
    await user.type(screen.getByRole('textbox', { name: 'Valeur à appliquer' }), '40')
    await user.click(screen.getByRole('button', { name: 'Appliquer à la sélection (4 cases)' }))
    expect(screen.getByText('4 bornes modifiées.')).toBeInTheDocument()
    for (const [code, product] of [
      ['C0012', 'Orange 1 L'],
      ['C0012', 'Pomme 1 L'],
      ['C0047', 'Pomme 1 L'],
    ] as const) {
      expect(cellOf(code, product)).toHaveTextContent(/^40/)
    }
    // Sous les 80 en attente de C0047 : avertissement (RG-B07).
    expect(cellOf('C0047', 'Orange 1 L')).toHaveTextContent(/^!40S80 V0$/)
    expect(cellOf('C0047', 'Orange 1 L')).toHaveAttribute('data-warning', 'true')
    expect(cellOf('C0051', 'Orange 1 L')).toHaveTextContent(/^400$/)

    // Colonne de la cellule active (Pomme) : bornes affichées effacées.
    await user.click(screen.getByRole('button', { name: 'Effacer les bornes de « Pomme 1 L »' }))
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveAccessibleName(/: pas de borne/)
    expect(cellOf('C0047', 'Pomme 1 L')).toHaveAccessibleName(/: pas de borne/)

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      region_id: 3,
      cells: [
        { client_id: 12, product_id: 42, limit: 40 },
        { client_id: 47, product_id: 42, limit: 40 },
        { client_id: 12, product_id: 43, limit: null },
        { client_id: 47, product_id: 43, limit: null },
      ],
    })
  })

  it('reverts every draft to the limit already sent, after confirmation', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('12{Enter}')
    await user.click(screen.getByRole('button', { name: 'Annuler les modifications' }))
    const dialog = screen.getByRole('dialog', { name: 'Annuler les modifications' })
    expect(dialog).toHaveTextContent('2 bornes modifiées reviendront à la valeur déjà envoyée aux clients.')
    await user.click(within(dialog).getByRole('button', { name: 'Annuler les modifications' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByText('2 bornes remises à la valeur envoyée.')).toBeInTheDocument()
    expect(cellOf('C0012', 'Orange 1 L')).toHaveTextContent(/^50/)
    expect(cellOf('C0012', 'Orange 1 L')).not.toHaveAttribute('data-draft')

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      region_id: 3,
      cells: [
        { client_id: 12, product_id: 42, limit: 50 },
        { client_id: 51, product_id: 57, limit: null },
      ],
    })
  })
})

describe('distribution grid — autosave', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    // 08:12 UTC = 09:12 à Alger.
    vi.setSystemTime(new Date('2026-10-08T08:12:00Z'))
  })

  it('sends the edits in one batch after a short pause and shows when they were saved', async () => {
    const { requests, fetchMock } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Pomme 1 L'))
    await user.keyboard('250{Enter}{Delete}{ArrowDown}60{Enter}')
    expect(requests(SAVE_URL)).toHaveLength(0)
    expect(screen.getByText('Enregistrement…')).toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      region_id: 3,
      cells: [
        { client_id: 12, product_id: 43, limit: 250 },
        { client_id: 51, product_id: 43, limit: 60 },
      ],
    })
    // Lot assez petit pour survivre à la fermeture de la page (RG-B09).
    const init = fetchMock.mock.calls.find(([input, options]) => String(input).includes('/limits') && options?.method === 'PUT')?.[1]
    expect(init?.keepalive).toBe(true)
    expect(new Headers(init?.headers).get('X-XSRF-TOKEN')).toBe('token=1')
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()

    // État renvoyé reporté dans la grille.
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveAttribute('title', 'envoyé : 20')
    expect(cellOf('C0051', 'Pomme 1 L')).toHaveAttribute('title', 'envoyé : pas de borne')
    expect(cellOf('C0051', 'Pomme 1 L')).toHaveTextContent(/^60$/)
  })

  it('maps a 422 to the offending cell and saves the others', async () => {
    let calls = 0
    const { requests } = renderDistribution({
      routes: {
        [SAVE_URL]: ({ body }) => {
          calls++
          if (calls === 1) {
            return problem(422, 'VALIDATION_FAILED', 'Données invalides.', { errors: { 'cells.1.client_id': ['Client hors de la région ce jour-là.'] } })
          }
          return json(savedCells(body))
        },
      },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('15{ArrowDown}{ArrowDown}30{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(2))
    expect(requests(SAVE_URL)[1]!.body).toEqual({ region_id: 3, cells: [{ client_id: 12, product_id: 57, limit: 15 }] })

    const refused = cellOf('C0051', 'Lben 1 L')
    await waitFor(() => expect(refused).toHaveAttribute('aria-invalid', 'true'))
    expect(refused).toHaveAttribute('title', 'Client hors de la région ce jour-là.')
    expect(refused).toHaveTextContent(/^30$/)
    expect(screen.getByText('1 borne en erreur, non enregistrée')).toBeInTheDocument()
  })

  it('offers a retry after a failed save', async () => {
    let fail = true
    const { requests } = renderDistribution({
      routes: { [SAVE_URL]: ({ body }) => (fail ? problem(500, 'SERVER_ERROR', 'Service indisponible.') : json(savedCells(body))) },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('15{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(await screen.findByText('Échec de l’enregistrement. Service indisponible.')).toBeInTheDocument()

    fail = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()
    expect(requests(SAVE_URL)).toHaveLength(2)
  })

  it('sends the failed edits again when the network comes back', async () => {
    let online = true
    let down = true
    vi.spyOn(navigator, 'onLine', 'get').mockImplementation(() => online)
    const { requests } = renderDistribution({
      routes: {
        [SAVE_URL]: ({ body }) => {
          if (down) throw new TypeError('Failed to fetch')
          return json(savedCells(body))
        },
      },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('15{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(await screen.findByText(/^Échec de l’enregistrement\. Connexion impossible au serveur/)).toBeInTheDocument()

    online = false
    act(() => {
      window.dispatchEvent(new Event('offline'))
    })
    down = false
    online = true
    act(() => {
      window.dispatchEvent(new Event('online'))
    })
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()
    expect(requests(SAVE_URL)).toHaveLength(2)
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent(/^15/)
  })

  it('switches to read-only when the day was closed meanwhile (409 DAY_NOT_OPEN)', async () => {
    let closed = false
    renderDistribution({
      routes: {
        [SAVE_URL]: () => {
          closed = true
          return problem(409, 'DAY_NOT_OPEN', 'La journée du 08/10/2026 est clôturée.')
        },
        [GRID_URL]: () =>
          json(
            closed
              ? makeGrid({ day: { ...makeGrid().day, status: 'CLOSED' }, region: { ...makeGrid().region, status: 'CLOSED' }, can_edit: false, can_publish: false })
              : makeGrid(),
          ),
      },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('15{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(await screen.findByText('La journée a été clôturée pendant la saisie : les bornes ne sont plus modifiables.')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Valider et envoyer aux clients' })).not.toBeInTheDocument())
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent('5S10 V0')
    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('4')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
  })

  it('keeps unsaved edits above refreshed data and sends them when the page is hidden', async () => {
    const { requests, queryClient, fetchMock } = renderDistribution({ routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) } })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('44{Enter}')
    // Relecture (temps réel, secours) avant l'enregistrement : la saisie reste affichée.
    await act(() => queryClient.invalidateQueries({ queryKey: gridKey(DAY, 3) }))
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(2))
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^44$/)
    expect(requests(SAVE_URL)).toHaveLength(0)

    // Le navigateur avertit avant de quitter la page.
    const leave = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(leave)
    expect(leave.defaultPrevented).toBe(true)

    // Onglet masqué : envoi immédiat, sans attendre le délai (RG-B09).
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    act(() => {
      document.dispatchEvent(new Event('visibilitychange'))
    })
    visibility.mockRestore()
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(fetchMock.mock.calls.some(([input, init]) => String(input).includes('/limits') && init?.keepalive === true)).toBe(true)
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()
    const after = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(after)
    expect(after.defaultPrevented).toBe(false)
  })
})

describe('distribution grid — no loss of unsaved limits (RG-B09)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
  })

  const EST_GRID = makeGrid({ region: { ...makeGrid().region, id: 4, code: 'EST', name: 'Est' } })

  it('keeps an edit whose save failed in the browser, and sends it again when the grid is reopened', async () => {
    let down = true
    const { requests } = renderDistribution({
      routes: {
        [REGIONS_URL]: () => json(opened(DAY, [centreEntry(), estEntry()])),
        [GRID_URL]: ({ url }) => json(url.searchParams.get('region') === '4' ? EST_GRID : makeGrid()),
        [SAVE_URL]: ({ body }) => (down ? problem(500, 'SERVER_ERROR', 'Service indisponible.') : json(savedCells(body))),
      },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()

    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('15{Enter}')
    // En attente, puis en échec : gardée dans le navigateur.
    expect(storedDrafts()).toEqual([{ client_id: 12, product_id: 57, limit: 15 }])
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(await screen.findByText('Échec de l’enregistrement. Service indisponible.')).toBeInTheDocument()
    expect(storedDrafts()).toEqual([{ client_id: 12, product_id: 57, limit: 15 }])

    // Autre région : la saisie part une dernière fois, échoue, et reste gardée.
    await user.selectOptions(screen.getByRole('combobox', { name: 'Région' }), '4')
    expect(await screen.findByRole('heading', { name: 'Est (EST)' })).toBeInTheDocument()
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(2))
    expect(storedDrafts()).toEqual([{ client_id: 12, product_id: 57, limit: 15 }])

    // Retour sur la région : la saisie est reprise, signalée et renvoyée.
    down = false
    await user.selectOptions(screen.getByRole('combobox', { name: 'Région' }), String(CENTRE.id))
    expect(await screen.findByText('1 borne non enregistrée a été récupérée et est renvoyée.')).toBeInTheDocument()
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent(/^15/)
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(3))
    expect(requests(SAVE_URL)[2]!.body).toEqual({ region_id: 3, cells: [{ client_id: 12, product_id: 57, limit: 15 }] })
    await waitFor(() => expect(storedDrafts()).toBeNull())
    expect(await screen.findByText(/^Enregistré à /)).toBeInTheDocument()
  })

  it('restores the edits of a previous visit with the checks of an entry', async () => {
    const { requests } = renderDistribution({
      routes: { [SAVE_URL]: ({ body }) => json(savedCells(body)) },
      stored: [
        { client_id: 51, product_id: 57, limit: 30 },
        // Déjà la valeur du serveur (brouillon 60) : rien à renvoyer.
        { client_id: 12, product_id: 42, limit: 60 },
        // Au-dessus du quota de Pomme (300) : refusée sur place.
        { client_id: 12, product_id: 43, limit: 700 },
        // Client sorti de la région.
        { client_id: 99, product_id: 42, limit: 10 },
      ],
    })
    await findGrid()

    expect(await screen.findByText('1 borne non enregistrée a été récupérée et est renvoyée.')).toBeInTheDocument()
    expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^30$/)
    expect(cellOf('C0051', 'Lben 1 L')).toHaveAttribute('data-draft', 'true')
    expect(cellOf('C0012', 'Pomme 1 L')).toHaveAttribute('data-error', 'true')
    // Envoyée sans attendre le délai.
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({ region_id: 3, cells: [{ client_id: 51, product_id: 57, limit: 30 }] })
    // Accusé de réception : plus rien de gardé, la valeur refusée non plus.
    await waitFor(() => expect(storedDrafts()).toBeNull())
    expect(screen.getByText('1 borne en erreur, non enregistrée')).toBeInTheDocument()
  })

  it('waits for an editable grid before restoring, and forgets the edits of a closed day', async () => {
    const stored = [{ client_id: 51, product_id: 57, limit: 30 }]
    const first = renderDistribution({ routes: { [GRID_URL]: () => json(makeGrid({ can_edit: false, can_publish: false })) }, stored })
    await findGrid()
    expect(storedDrafts()).toEqual(stored)
    expect(first.requests(SAVE_URL)).toHaveLength(0)
    first.unmount()

    const grid = makeGrid()
    const second = renderDistribution({
      routes: { [GRID_URL]: () => json(makeGrid({ day: { ...grid.day, status: 'CLOSED' }, region: { ...grid.region, status: 'CLOSED' }, can_edit: false, can_publish: false })) },
      stored,
    })
    await findGrid()
    expect(storedDrafts()).toBeNull()
    expect(second.requests(SAVE_URL)).toHaveLength(0)
  })

  it('rereads the « Quotas du jour » page after the first draft of the region', async () => {
    const grid = makeGrid({ region: { ...makeGrid().region, status: 'QUOTA_PUBLISHED', distribution_version: 0, distribution_published_at: null } })
    const { queryClient } = renderDistribution({
      routes: { [GRID_URL]: () => json(grid), [SAVE_URL]: ({ body }) => json({ ...savedCells(body, grid), status: 'DISTRIBUTION_DRAFT' }) },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await findGrid()
    // Page « Quotas du jour » déjà visitée (modules/regional/quotas).
    queryClient.setQueryData(['regional', 'quotas', DAY], opened(DAY))

    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('12{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(await screen.findByText('Répartition en cours')).toBeInTheDocument()
    expect(queryClient.getQueryState(['regional', 'quotas', DAY])?.isInvalidated).toBe(true)
  })
})

describe('distribution grid — values', () => {
  it('formats quantities with a non-breaking thousands separator', () => {
    expect(text(1200)).toBe('1 200')
  })
})
