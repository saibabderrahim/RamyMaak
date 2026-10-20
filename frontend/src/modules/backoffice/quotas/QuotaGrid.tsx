import type { TFunction } from 'i18next'
import {
  type ClipboardEvent,
  type FocusEvent,
  type KeyboardEvent,
  type MouseEvent,
  memo,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { useTranslation } from 'react-i18next'
import type { QuotaCell, QuotaMatrix, QuotaMatrixProduct, QuotaMatrixRegion } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { Badge } from '../../../shared/ui'
import type { CellChange, LocalEdit } from './drafts'
import { groupByFamily, isBlock, parseClipboard, parseQuantity, serverValue } from './matrix'
import { RegionStatusBadge } from './components'

/**
 * Matrice produit × région (EF-ADV-02) : saisie au clavier comme dans un
 * tableur, totaux par ligne et par colonne sur la valeur affichée, repères
 * visuels (brouillon, sous le validé, erreur). Motif ARIA « grid » : une
 * seule cellule active dans l'ordre de tabulation (tabindex mobile), son
 * libellé est annoncé à la prise de focus.
 *
 * Fluidité (150 produits × 12 régions) : les lignes sont mémorisées et ne se
 * redessinent que si leurs cellules, leurs saisies ou la cellule active
 * changent ; la frappe dans une cellule ne touche que son champ.
 *
 * La cellule active et la saisie en cours sont repérées par leur produit et
 * leur région, pas par leur rang : une matrice relue (temps réel, secours)
 * qui ajoute ou retire une ligne ou une colonne ne déplace pas une saisie
 * vers une autre cellule (RG-Q03). Rangs déduits à chaque rendu.
 */

/** Rang d'une cellule dans la grille affichée (navigation, attributs data-row/data-col). */
type Position = { row: number; col: number }
/** Cellule repérée par son identité, stable quand la matrice est relue. */
type CellId = { productId: number; regionId: number }
/** Cellule active : identité, et dernier rang connu en repli si elle disparaît. */
type Selection = CellId & Position
type Editing = CellId & { text: string; mode: 'replace' | 'edit' }

type Highlights = ReadonlyMap<number, ReadonlySet<number>>

// Traduction passée aux lignes et cellules : un seul abonnement à i18next pour toute la grille.
type Translate = TFunction

type Props = {
  matrix: QuotaMatrix
  edits: ReadonlyMap<number, Readonly<Record<number, LocalEdit>>>
  readOnly: boolean
  /** Cellules refusées à la publication (QUOTA_BELOW_VALIDATED) : produit → régions. */
  highlights: Highlights
  onEdit: (changes: CellChange[]) => void
  onRevert: (productId: number, regionId: number) => void
  label: string
  describedBy?: string
}

const PAGE_ROWS = 10

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

const clamp = (value: number, last: number): number => Math.max(0, Math.min(value, last))

/** Valeur affichée : saisie locale valide, sinon brouillon ou quota publié. */
function displayValue(cell: QuotaCell | undefined, edit: LocalEdit | undefined): number {
  return edit?.value ?? serverValue(cell)
}

/** Valeur proposée à la modification (F2) : chiffres seuls, sans séparateurs. */
function editableText(cell: QuotaCell | undefined, edit: LocalEdit | undefined): string {
  if (edit && edit.value === null) return edit.text
  return String(displayValue(cell, edit))
}

export function QuotaGrid({ matrix, edits, readOnly, highlights, onEdit, onRevert, label, describedBy }: Props) {
  const { t } = useTranslation()
  const { products, regions, cells } = matrix
  const groups = useMemo(() => groupByFamily(products), [products])
  const locked = useMemo(() => regions.map((region) => region.status === 'CLOSED'), [regions])
  const lastRow = products.length - 1
  const lastCol = regions.length - 1
  const rowOf = useMemo(() => new Map(products.map((product, row) => [product.id, row])), [products])
  const colOf = useMemo(() => new Map(regions.map((region, col) => [region.id, col])), [regions])

  // La grille n'est affichée qu'avec au moins un produit et une région.
  const [selection, setSelection] = useState<Selection>(() => ({ productId: products[0]?.id ?? 0, regionId: regions[0]?.id ?? 0, row: 0, col: 0 }))
  const [editing, setEditing] = useState<Editing | null>(null)
  const [announcement, setAnnouncement] = useState('')
  const table = useRef<HTMLTableElement>(null)
  // Saisie en cours, lue par les gestionnaires d'événements sans attendre le rendu.
  const editingRef = useRef<Editing | null>(null)
  // Le focus suit la cellule active seulement après une action dans la grille.
  const focusRequested = useRef(false)
  // Ctrl+V reçu : l'événement « paste » est-il bien arrivé ?
  const pasteSeen = useRef(true)

  /** Rang actuel d'une cellule ; null si son produit ou sa région a disparu de la matrice relue. */
  const locate = ({ productId, regionId }: CellId): Position | null => {
    const row = rowOf.get(productId)
    const col = colOf.get(regionId)
    return row === undefined || col === undefined ? null : { row, col }
  }

  const editable = (row: number, col: number): boolean => !readOnly && products[row]?.is_active === true && locked[col] === false

  // Cellule active suivie par identité ; disparue de la matrice relue : même
  // rang qu'avant, ramené dans la grille.
  const active: Position = {
    row: clamp(rowOf.get(selection.productId) ?? selection.row, lastRow),
    col: clamp(colOf.get(selection.regionId) ?? selection.col, lastCol),
  }

  // Saisie en cours, à son rang actuel ; null si sa cellule a disparu ou
  // n'est plus modifiable (journée ou région clôturée, produit désactivé).
  const editingAt = editing ? locate(editing) : null
  const liveEditing = editing && editingAt && editable(editingAt.row, editingAt.col) ? editing : null
  const editingLost = editing !== null && liveEditing === null

  // Saisie devenue impossible : abandonnée sans enregistrement, le focus
  // revient sur la cellule active.
  useEffect(() => {
    if (!editingLost || !editingRef.current) return
    editingRef.current = null
    focusRequested.current = true
    setEditing(null)
  }, [editingLost])

  useEffect(() => {
    if (!focusRequested.current || editing) return
    focusRequested.current = false
    table.current?.querySelector<HTMLElement>(`[data-row="${active.row}"][data-col="${active.col}"]`)?.focus()
  })

  const totals = useMemo(() => {
    const columns = regions.map(() => 0)
    for (const product of products) {
      const row = cells[product.id]
      const edited = edits.get(product.id)
      regions.forEach((region, col) => {
        columns[col]! += displayValue(row?.[region.id], edited?.[region.id])
      })
    }
    return { columns, grand: columns.reduce((sum, value) => sum + value, 0) }
  }, [products, regions, cells, edits])

  const moveTo = (row: number, col: number) => {
    focusRequested.current = true
    const target = { row: clamp(row, lastRow), col: clamp(col, lastCol) }
    setSelection({ ...target, productId: products[target.row]?.id ?? 0, regionId: regions[target.col]?.id ?? 0 })
  }

  const startEditing = (next: Editing) => {
    editingRef.current = next
    setEditing(next)
  }

  const stopEditing = () => {
    editingRef.current = null
    setEditing(null)
  }

  const change = (row: number, col: number, text: string): CellChange | null => {
    const product = products[row]
    const region = regions[col]
    if (!product || !region || !editable(row, col)) return null
    const value = parseQuantity(text)
    if (value === null) return { productId: product.id, regionId: region.id, edit: { value: null, text, error: t('backoffice.quotas.invalidQuantity') } }
    // Valeur inchangée (F2 puis Entrée) : rien à enregistrer.
    const edit = edits.get(product.id)?.[region.id]
    if (edit ? edit.error === null && edit.value === value : serverValue(cells[product.id]?.[region.id]) === value) return null
    return { productId: product.id, regionId: region.id, edit: { value, text, error: null } }
  }

  const apply = (changes: Array<CellChange | null>) => {
    const valid = changes.filter((item): item is CellChange => item !== null)
    if (valid.length > 0) onEdit(valid)
  }

  /**
   * Fin de la saisie en cours : la valeur part (ou s'affiche en erreur) pour
   * le produit et la région où elle a commencé, à leur rang actuel ; rien ne
   * part si la cellule a disparu ou n'est plus modifiable.
   */
  const commit = (text: string): void => {
    const current = editingRef.current
    if (!current) return
    stopEditing()
    const at = locate(current)
    if (at) apply([change(at.row, at.col, text)])
  }

  const editorValue = (): string => table.current?.querySelector<HTMLInputElement>('input[data-editor]')?.value ?? ''

  /** Tab : cellule suivante, puis début de la ligne suivante ; null au bord de la grille. */
  const tabTarget = (from: Position, backwards: boolean): Position | null => {
    if (backwards) {
      if (from.col > 0) return { row: from.row, col: from.col - 1 }
      return from.row > 0 ? { row: from.row - 1, col: lastCol } : null
    }
    if (from.col < lastCol) return { row: from.row, col: from.col + 1 }
    return from.row < lastRow ? { row: from.row + 1, col: 0 } : null
  }

  const onEditorKeyDown = (event: KeyboardEvent, current: Position & Pick<Editing, 'mode'>) => {
    const input = event.target as HTMLInputElement
    const commitAndMove = (row: number, col: number) => {
      event.preventDefault()
      commit(input.value)
      moveTo(row, col)
    }
    switch (event.key) {
      case 'Enter':
        commitAndMove(current.row + (event.shiftKey ? -1 : 1), current.col)
        break
      case 'Tab': {
        const target = tabTarget(current, event.shiftKey) ?? current
        commitAndMove(target.row, target.col)
        break
      }
      case 'ArrowUp':
        commitAndMove(current.row - 1, current.col)
        break
      case 'ArrowDown':
        commitAndMove(current.row + 1, current.col)
        break
      case 'ArrowLeft':
      case 'ArrowRight':
        // Saisie commencée par une frappe : les flèches valident et déplacent ;
        // après F2 ou double-clic, elles déplacent le curseur dans le champ.
        if (current.mode === 'replace') commitAndMove(current.row, current.col + (event.key === 'ArrowLeft' ? -1 : 1))
        break
      case 'Escape':
        event.preventDefault()
        event.stopPropagation()
        focusRequested.current = true
        stopEditing()
        break
    }
  }

  const onKeyDown = (event: KeyboardEvent<HTMLTableElement>) => {
    if (!(event.target as HTMLElement).closest('[data-row]')) return
    const current = editingRef.current
    if (current) {
      // Déplacement à partir du rang actuel de la cellule saisie.
      onEditorKeyDown(event, { ...(locate(current) ?? active), mode: current.mode })
      return
    }
    if (event.altKey) return

    const { row, col } = active
    const ctrl = event.ctrlKey || event.metaKey
    const product = products[row]
    const region = regions[col]
    const move = (nextRow: number, nextCol: number) => {
      event.preventDefault()
      moveTo(nextRow, nextCol)
    }

    switch (event.key) {
      case 'ArrowUp':
        return move(ctrl ? 0 : row - 1, col)
      case 'ArrowDown':
        return move(ctrl ? lastRow : row + 1, col)
      case 'ArrowLeft':
        return move(row, ctrl ? 0 : col - 1)
      case 'ArrowRight':
        return move(row, ctrl ? lastCol : col + 1)
      case 'Enter':
        return move(row + (event.shiftKey ? -1 : 1), col)
      case 'Tab': {
        // Au bord de la grille, Tab en sort (pas de piège au clavier).
        const target = tabTarget(active, event.shiftKey)
        if (target) move(target.row, target.col)
        return
      }
      case 'Home':
        return move(ctrl ? 0 : row, 0)
      case 'End':
        return move(ctrl ? lastRow : row, lastCol)
      case 'PageUp':
        return move(row - PAGE_ROWS, col)
      case 'PageDown':
        return move(row + PAGE_ROWS, col)
    }

    if (!product || !region) return
    const edit = edits.get(product.id)?.[region.id]

    if (event.key === 'Escape') {
      // Cellule en erreur : retour à la valeur du serveur.
      if (edit?.error) {
        event.preventDefault()
        onRevert(product.id, region.id)
      }
      return
    }
    if (ctrl && event.key.toLowerCase() === 'v') {
      if (editable(row, col)) expectPaste(active)
      return
    }
    if (!editable(row, col) || ctrl) return

    if (event.key === 'F2') {
      event.preventDefault()
      startEditing({ productId: product.id, regionId: region.id, text: editableText(cells[product.id]?.[region.id], edit), mode: 'edit' })
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
      event.preventDefault()
      apply([change(row, col, '0')])
    } else if (event.key.length === 1 && event.key !== ' ') {
      // Une frappe remplace la valeur (comme dans un tableur).
      event.preventDefault()
      startEditing({ productId: product.id, regionId: region.id, text: event.key, mode: 'replace' })
    }
  }

  const cellAt = (target: EventTarget): Position | null => {
    const element = (target as HTMLElement).closest<HTMLElement>('[data-row]')
    if (!element) return null
    return { row: Number(element.dataset.row), col: Number(element.dataset.col) }
  }

  const onMouseDown = (event: MouseEvent<HTMLTableElement>) => {
    const target = cellAt(event.target)
    if (!target) return
    const current = editingRef.current
    if (current && current.productId === products[target.row]?.id && current.regionId === regions[target.col]?.id) return
    if (current) commit(editorValue())
    moveTo(target.row, target.col)
  }

  const onDoubleClick = (event: MouseEvent<HTMLTableElement>) => {
    const target = cellAt(event.target)
    if (!target || editingRef.current || !editable(target.row, target.col)) return
    const product = products[target.row]!
    const region = regions[target.col]!
    startEditing({
      productId: product.id,
      regionId: region.id,
      text: editableText(cells[product.id]?.[region.id], edits.get(product.id)?.[region.id]),
      mode: 'edit',
    })
  }

  // Champ quitté (clic ailleurs, bouton de la barre d'actions) : la saisie est gardée.
  const onBlur = (event: FocusEvent<HTMLTableElement>) => {
    if (event.target instanceof HTMLInputElement && event.target.dataset.editor !== undefined) commit(event.target.value)
  }

  /**
   * Certains navigateurs n'émettent pas « paste » sur une cellule non
   * modifiable : sans événement juste après Ctrl+V, le presse-papiers est lu
   * directement (avec l'accord de l'utilisateur).
   */
  const expectPaste = (start: Position) => {
    pasteSeen.current = false
    setTimeout(() => {
      if (pasteSeen.current) return
      pasteSeen.current = true
      navigator.clipboard?.readText().then(
        (text) => text !== '' && paste(start, parseClipboard(text)),
        () => undefined,
      )
    }, 0)
  }

  const onPaste = (event: ClipboardEvent<HTMLTableElement>) => {
    pasteSeen.current = true
    if (readOnly) return
    const text = event.clipboardData.getData('text/plain')
    if (text === '') return
    const current = editingRef.current
    // Une simple valeur collée dans le champ en cours : comportement normal du champ.
    if (current && !isBlock(text)) return
    event.preventDefault()
    const start = (current && locate(current)) ?? active
    if (current) stopEditing()
    paste(start, parseClipboard(text))
  }

  /**
   * Bloc collé depuis Excel à partir de la cellule active, rogné aux bords
   * de la grille. Les lignes des produits inactifs (lecture seule) sont
   * sautées : le bloc se poursuit sur le produit actif suivant, comme dans
   * le gabarit qui ne contient que les produits actifs.
   */
  const paste = (start: Position, block: string[][]) => {
    const rows: number[] = []
    for (let row = start.row; row <= lastRow && rows.length < block.length; row++) {
      if (products[row]?.is_active) rows.push(row)
    }
    const changes: Array<CellChange | null> = []
    block.forEach((values, index) => {
      const row = rows[index]
      if (row === undefined) return
      values.forEach((text, offset) => {
        const col = start.col + offset
        if (col <= lastCol && locked[col] === false) changes.push(change(row, col, text.trim()))
      })
    })
    apply(changes)
    setAnnouncement(t('backoffice.quotas.grid.pasted', { count: changes.length }))
    moveTo(start.row, start.col)
  }

  const columnCount = regions.length + 3
  let ariaRow = 1

  return (
    <div className="max-h-[70vh] overflow-auto rounded-lg border border-slate-200 bg-white">
      <table
        ref={table}
        role="grid"
        aria-label={label}
        aria-describedby={describedBy}
        aria-readonly={readOnly || undefined}
        aria-colcount={columnCount}
        aria-rowcount={products.length + groups.length + 2}
        onKeyDown={onKeyDown}
        onMouseDown={onMouseDown}
        onDoubleClick={onDoubleClick}
        onBlur={onBlur}
        onPaste={onPaste}
        className="w-max min-w-full border-separate border-spacing-0 text-sm"
      >
        <thead className="sticky top-0 z-20 bg-slate-50">
          <tr aria-rowindex={ariaRow++}>
            <th scope="col" aria-colindex={1} className="sticky left-0 z-30 w-24 min-w-24 border-b border-slate-300 bg-slate-50 px-3 py-2 text-start font-medium text-slate-600">
              {t('backoffice.quotas.grid.code')}
            </th>
            <th scope="col" aria-colindex={2} className="sticky left-24 z-30 w-56 min-w-56 border-b border-e-2 border-slate-300 bg-slate-50 px-3 py-2 text-start font-medium text-slate-600">
              {t('backoffice.quotas.grid.product')}
            </th>
            {regions.map((region, index) => (
              <RegionHeader key={region.id} region={region} colIndex={index + 3} />
            ))}
            <th scope="col" aria-colindex={columnCount} className="min-w-24 border-b border-s-2 border-slate-300 bg-slate-100 px-3 py-2 text-end font-semibold text-slate-700">
              {t('backoffice.quotas.grid.total')}
            </th>
          </tr>
        </thead>
        {groups.map((group) => (
          <tbody key={group.family.id}>
            <tr aria-rowindex={ariaRow++}>
              <th scope="rowgroup" colSpan={columnCount} aria-colindex={1} className="border-b border-slate-200 bg-slate-100 px-0 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-700">
                <span className="sticky left-0 px-3">{group.family.name}</span>
              </th>
            </tr>
            {group.rows.map((row) => {
              const product = products[row]!
              const isActiveRow = row === active.row
              return (
                <QuotaRow
                  key={product.id}
                  product={product}
                  row={row}
                  ariaRowIndex={ariaRow++}
                  regions={regions}
                  cells={cells[product.id]}
                  edits={edits.get(product.id)}
                  activeCol={isActiveRow ? active.col : null}
                  editing={liveEditing && liveEditing.productId === product.id ? liveEditing : null}
                  readOnly={readOnly || !product.is_active}
                  locked={locked}
                  highlights={highlights.get(product.id)}
                  t={t}
                />
              )
            })}
          </tbody>
        ))}
        <tfoot className="sticky bottom-0 z-20 bg-slate-100">
          <tr aria-rowindex={ariaRow++}>
            <th scope="row" colSpan={2} aria-colindex={1} className="sticky left-0 z-30 border-t-2 border-e-2 border-slate-300 bg-slate-100 px-3 py-2 text-start font-semibold text-slate-800">
              {t('backoffice.quotas.grid.total')}
            </th>
            {regions.map((region, index) => (
              <td
                key={region.id}
                role="gridcell"
                aria-colindex={index + 3}
                aria-label={t('backoffice.quotas.grid.columnTotal', { region: region.code, value: formatQuantity(totals.columns[index] ?? 0) })}
                className="border-t-2 border-e border-slate-300 px-3 py-2 text-end font-semibold tabular-nums text-slate-900"
              >
                {formatQuantity(totals.columns[index] ?? 0)}
              </td>
            ))}
            <td
              role="gridcell"
              aria-colindex={columnCount}
              aria-label={t('backoffice.quotas.grid.grandTotal', { value: formatQuantity(totals.grand) })}
              className="border-t-2 border-s-2 border-slate-300 px-3 py-2 text-end font-bold tabular-nums text-slate-900"
            >
              {formatQuantity(totals.grand)}
            </td>
          </tr>
        </tfoot>
      </table>
      <p role="status" className="sr-only">
        {announcement}
      </p>
    </div>
  )
}

function RegionHeader({ region, colIndex }: { region: QuotaMatrixRegion; colIndex: number }) {
  const { t } = useTranslation()
  return (
    <th scope="col" aria-colindex={colIndex} className="min-w-28 border-b border-e border-slate-300 bg-slate-50 px-3 py-2 align-top font-normal">
      <div className="flex flex-col items-end gap-1 text-end">
        <span className="font-semibold text-slate-900" title={region.name}>
          {region.code}
        </span>
        <RegionStatusBadge status={region.status} />
        <span className="text-xs text-slate-600">
          {region.quota_version > 0 ? t('backoffice.quotas.grid.version', { version: region.quota_version }) : t('backoffice.quotas.grid.unpublished')}
        </span>
        <span className={cx('text-xs', region.pending_drafts > 0 ? 'font-semibold text-amber-800' : 'text-slate-500')}>
          {region.pending_drafts > 0 ? t('backoffice.quotas.grid.drafts', { count: region.pending_drafts }) : t('backoffice.quotas.grid.noDrafts')}
        </span>
      </div>
    </th>
  )
}

type RowProps = {
  product: QuotaMatrixProduct
  /** Rang de la ligne parmi les produits (navigation). */
  row: number
  ariaRowIndex: number
  regions: QuotaMatrixRegion[]
  cells: Record<string, QuotaCell> | undefined
  edits: Readonly<Record<number, LocalEdit>> | undefined
  /** Colonne de la cellule active si elle est sur cette ligne. */
  activeCol: number | null
  editing: Editing | null
  readOnly: boolean
  locked: boolean[]
  highlights: ReadonlySet<number> | undefined
  t: Translate
}

/** Ligne d'un produit, redessinée seulement si ses propres données changent. */
const QuotaRow = memo(function QuotaRow({ product, row, ariaRowIndex, regions, cells, edits, activeCol, editing, readOnly, locked, highlights, t }: RowProps) {
  const values = regions.map((region) => displayValue(cells?.[region.id], edits?.[region.id]))
  const total = values.reduce((sum, value) => sum + value, 0)
  const inactive = !product.is_active

  return (
    <tr aria-rowindex={ariaRowIndex} className={inactive ? 'text-slate-500' : undefined}>
      <th
        scope="row"
        aria-colindex={1}
        className={cx('sticky left-0 z-10 w-24 min-w-24 border-b border-slate-200 px-3 py-1 text-start font-mono text-xs font-medium', inactive ? 'bg-slate-50' : 'bg-white text-slate-700')}
      >
        {product.sku}
      </th>
      <td
        role="gridcell"
        aria-colindex={2}
        className={cx('sticky left-24 z-10 w-56 min-w-56 max-w-56 border-b border-e-2 border-slate-200 px-3 py-1', inactive ? 'bg-slate-50' : 'bg-white')}
      >
        <div className="flex items-center gap-2">
          <span className="truncate font-medium text-slate-900" title={`${product.name} — ${product.sales_unit_label}`}>
            {product.short_name}
          </span>
          {inactive && (
            <span title={t('backoffice.quotas.grid.inactiveHint')}>
              <Badge>{t('backoffice.quotas.grid.inactive')}</Badge>
            </span>
          )}
        </div>
        <div className="truncate text-xs text-slate-500">{product.sales_unit_label}</div>
      </td>
      {regions.map((region, col) => {
        const isEditing = editing !== null && editing.regionId === region.id
        return (
          <QuotaCellView
            key={region.id}
            product={product}
            region={region}
            row={row}
            col={col}
            cell={cells?.[region.id]}
            edit={edits?.[region.id]}
            value={values[col] ?? 0}
            active={activeCol === col}
            editing={isEditing ? editing : null}
            readOnly={readOnly || locked[col] === true}
            highlighted={highlights?.has(region.id) === true}
            t={t}
          />
        )
      })}
      <td
        role="gridcell"
        aria-colindex={regions.length + 3}
        aria-label={t('backoffice.quotas.grid.rowTotal', { product: product.short_name, value: formatQuantity(total) })}
        className="border-b border-s-2 border-slate-200 bg-slate-50 px-3 py-1 text-end font-semibold tabular-nums text-slate-900"
      >
        {formatQuantity(total)}
      </td>
    </tr>
  )
})

type CellProps = {
  product: QuotaMatrixProduct
  region: QuotaMatrixRegion
  row: number
  col: number
  cell: QuotaCell | undefined
  edit: LocalEdit | undefined
  value: number
  active: boolean
  editing: Editing | null
  readOnly: boolean
  highlighted: boolean
  t: Translate
}

function QuotaCellView({ product, region, row, col, cell, edit, value, active, editing, readOnly, highlighted, t }: CellProps) {
  const published = cell?.quantity ?? 0
  const validated = cell?.validated ?? 0
  const invalid = edit !== undefined && edit.value === null
  const text = invalid ? edit.text : formatQuantity(value)
  // Brouillon : saisie locale différente du publié, ou brouillon enregistré.
  const draft = edit ? edit.value !== null && edit.value !== published : (cell?.draft ?? null) !== null
  const below = value < validated
  const error = edit?.error ?? null

  const publishedHint: string = t('backoffice.quotas.grid.published', { value: formatQuantity(published) })
  const validatedHint: string = t('backoffice.quotas.grid.validated', { value: formatQuantity(validated) })
  const hints: string[] = []
  if (draft) hints.push(publishedHint)
  if (validated > 0) hints.push(validatedHint)
  if (error) hints.push(error)

  const parts: string[] = [t('backoffice.quotas.grid.cell', { product: product.short_name, region: region.code, value: text })]
  if (draft) parts.push(`${t('backoffice.quotas.grid.draft')} (${publishedHint})`)
  if (validated > 0) parts.push(validatedHint)
  if (below) parts.push(t('backoffice.quotas.grid.below'))
  if (highlighted) parts.push(t('backoffice.quotas.grid.violation'))
  if (error) parts.push(t('backoffice.quotas.grid.error', { message: error }))
  if (readOnly) parts.push(t('backoffice.quotas.grid.readOnly'))
  const description = parts.join(', ')

  return (
    <td
      role="gridcell"
      data-row={row}
      data-col={col}
      data-draft={draft || undefined}
      data-below={below || undefined}
      aria-colindex={col + 3}
      aria-label={description}
      aria-selected={active || undefined}
      aria-readonly={readOnly || undefined}
      aria-invalid={error !== null || undefined}
      tabIndex={active && !editing ? 0 : -1}
      title={hints.length > 0 ? hints.join(' · ') : undefined}
      className={cx(
        'h-11 min-w-28 scroll-mt-28 scroll-mb-12 scroll-ms-80 border-b border-e border-slate-200 px-3 text-end tabular-nums',
        readOnly ? 'cursor-default' : 'cursor-cell',
        error ? 'bg-red-50' : draft ? 'bg-amber-100' : product.is_active ? 'bg-white' : 'bg-slate-50',
        below || error ? 'font-semibold text-red-700' : draft ? 'font-semibold text-slate-900' : 'text-slate-900',
        // Cellule active : contour visible, plus épais quand la grille a le focus.
        active ? 'outline-2 -outline-offset-2 outline-brand-600 focus:outline-[3px]' : 'outline-none',
        (highlighted || error) && !active && 'ring-2 ring-inset ring-red-600',
      )}
    >
      {editing ? (
        <CellEditor initial={editing.text} label={t('backoffice.quotas.grid.input', { product: product.short_name, region: region.code })} />
      ) : (
        text
      )}
    </td>
  )
}

/** Champ de saisie de la cellule active, non contrôlé : la frappe ne redessine rien d'autre. */
function CellEditor({ initial, label }: { initial: string; label: string }) {
  const input = useRef<HTMLInputElement>(null)
  useLayoutEffect(() => {
    const element = input.current
    if (!element) return
    element.focus()
    element.setSelectionRange(element.value.length, element.value.length)
  }, [])
  return (
    <input
      ref={input}
      data-editor=""
      defaultValue={initial}
      aria-label={label}
      inputMode="numeric"
      autoComplete="off"
      spellCheck={false}
      className="h-9 w-full min-w-0 rounded border border-brand-600 bg-white px-2 text-end text-base tabular-nums text-slate-900 outline-none"
    />
  )
}
