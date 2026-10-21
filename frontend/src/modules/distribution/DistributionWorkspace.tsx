import { type FormEvent, useCallback, useDeferredValue, useId, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { DistributionGrid as Grid } from '../../shared/api/types'
import { Button, Input } from '../../shared/ui'
import { ClientFilters, Legend, ModeSwitch } from './components'
import { DistributionGrid } from './DistributionGrid'
import {
  type CellChange,
  type DisplayMode,
  displayedLimit,
  type EditsByClient,
  filterClients,
  type GridSelection,
  type Highlights,
  locateSelection,
  parseLimit,
  type ProductSummary,
} from './model'

type Props = {
  grid: Grid
  edits: EditsByClient
  summaries: readonly ProductSummary[]
  readOnly: boolean
  highlights: Highlights
  onEdit: (changes: CellChange[]) => void
  onRevert: (clientId: number, productId: number) => void
  makeChange: (clientId: number, productId: number, text: string) => CellChange | null
  label: string
}

/**
 * Grille PC et tablette (EF-REG-02) avec sa barre d'outils : modes
 * d'affichage (Bornes, Commandes, Reste), recherche et filtre par catégorie,
 * actions de masse (appliquer une valeur à la colonne active pour les
 * clients affichés ou à la sélection, effacer la colonne). Sans saisie
 * (back-office, lecture seule) : ni actions de masse ni champ.
 */
export function DistributionWorkspace({ grid, edits, summaries, readOnly, highlights, onEdit, onRevert, makeChange, label }: Props) {
  const { t } = useTranslation()
  const helpId = useId()
  const valueId = useId()
  const valueErrorId = useId()
  const [mode, setMode] = useState<DisplayMode>('limits')
  const [search, setSearch] = useState('')
  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [selection, setSelection] = useState<GridSelection | null>(null)
  const [massValue, setMassValue] = useState('')
  const [massError, setMassError] = useState<string | null>(null)
  const [notice, setNotice] = useState('')

  // La recherche ne bloque pas la frappe : la grille filtrée suit juste après.
  const deferredSearch = useDeferredValue(search)
  const clients = useMemo(() => filterClients(grid.clients, deferredSearch, categoryId), [grid.clients, deferredSearch, categoryId])
  const { active, range } = locateSelection(selection, clients, grid.products)
  const product = grid.products[active.col]
  const selectedCount = clients.length === 0 ? 0 : (range.bottom - range.top + 1) * (range.right - range.left + 1)
  const editable = !readOnly && mode === 'limits'

  const onSelect = useCallback((next: GridSelection) => setSelection(next), [])

  const applyAll = (changes: Array<CellChange | null>) => {
    const valid = changes.filter((item): item is CellChange => item !== null)
    if (valid.length > 0) onEdit(valid)
    const refused = valid.filter((item) => item.edit.error !== null).length
    setNotice(
      [t('distribution.mass.done', { count: valid.length - refused }), refused > 0 ? t('distribution.mass.refused', { count: refused }) : '']
        .filter(Boolean)
        .join(' '),
    )
  }

  /** Valeur saisie pour une action de masse ; null si elle est refusée (message sous le champ). */
  const readValue = (): string | null => {
    if (parseLimit(massValue) === null) {
      setMassError(t('distribution.errors.format'))
      return null
    }
    setMassError(null)
    return massValue.trim()
  }

  const applyToColumn = (event: FormEvent) => {
    event.preventDefault()
    const text = readValue()
    if (text === null || !product) return
    applyAll(clients.map((client) => makeChange(client.id, product.id, text)))
  }

  const applyToSelection = () => {
    const text = readValue()
    if (text === null) return
    const changes: Array<CellChange | null> = []
    for (let row = range.top; row <= range.bottom; row++) {
      const client = clients[row]
      if (!client) continue
      for (let col = range.left; col <= range.right; col++) {
        const target = grid.products[col]
        if (target) changes.push(makeChange(client.id, target.id, text))
      }
    }
    applyAll(changes)
  }

  const clearColumn = () => {
    if (!product) return
    applyAll(
      clients
        .filter((client) => displayedLimit(grid.cells[String(client.id)]?.[String(product.id)], edits.get(client.id)?.[product.id]) !== null)
        .map((client) => makeChange(client.id, product.id, '')),
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <ClientFilters
          clients={grid.clients}
          search={search}
          onSearch={setSearch}
          categoryId={categoryId}
          onCategory={setCategoryId}
          shown={clients.length}
        />
        <ModeSwitch mode={mode} onChange={setMode} />
      </div>

      {editable && product && (
        <form
          onSubmit={applyToColumn}
          noValidate
          aria-label={t('distribution.mass.label')}
          className="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3"
        >
          <div className="flex w-full flex-col gap-1 sm:w-44">
            <label htmlFor={valueId} className="text-sm font-medium text-slate-800">
              {t('distribution.mass.value')}
            </label>
            <Input
              id={valueId}
              value={massValue}
              onChange={(event) => setMassValue(event.target.value)}
              inputMode="numeric"
              autoComplete="off"
              placeholder={t('distribution.mass.placeholder')}
              aria-invalid={massError !== null}
              aria-describedby={massError ? valueErrorId : undefined}
            />
          </div>
          <Button type="submit" variant="secondary">
            {t('distribution.mass.applyColumn', { product: product.short_name, count: clients.length })}
          </Button>
          <Button variant="secondary" onClick={applyToSelection} disabled={selectedCount < 2}>
            {t('distribution.mass.applySelection', { count: selectedCount })}
          </Button>
          <Button variant="secondary" onClick={clearColumn}>
            {t('distribution.mass.clearColumn', { product: product.short_name })}
          </Button>
          {massError && (
            <p id={valueErrorId} role="alert" className="w-full text-sm text-red-700">
              {massError}
            </p>
          )}
          <p role="status" className="w-full text-sm text-slate-700 empty:hidden">
            {notice}
          </p>
        </form>
      )}

      <Legend />
      <p id={helpId} className="text-xs text-slate-600">
        {editable ? t('distribution.help.edit') : t('distribution.help.readOnly')}
      </p>

      <DistributionGrid
        grid={grid}
        clients={clients}
        summaries={summaries}
        edits={edits}
        mode={mode}
        readOnly={readOnly}
        highlights={highlights}
        selection={selection}
        onSelect={onSelect}
        onEdit={onEdit}
        onRevert={onRevert}
        makeChange={makeChange}
        label={label}
        describedBy={helpId}
      />
    </div>
  )
}
