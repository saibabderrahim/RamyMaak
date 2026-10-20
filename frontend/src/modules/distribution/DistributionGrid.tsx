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
import type { DistributionGrid as Grid, DistributionGridClient, DistributionGridProduct, LimitCell, NoLimitPolicy } from '../../shared/api/types'
import { formatQuantity } from '../../shared/format'
import { Badge } from '../../shared/ui'
import {
  belowEngaged,
  cellAvailable,
  type CellChange,
  type CellId,
  checkLimit,
  type DisplayMode,
  displayedLimit,
  type EditsByClient,
  editableText,
  type GridSelection,
  groupByFamily,
  hasDraft,
  type Highlights,
  isBlock,
  type LocalEdit,
  locateSelection,
  parseClipboard,
  type Position,
  type ProductSummary,
} from './model'

/**
 * Grille produit × client du régional (EF-REG-02), commune au back-office
 * (lecture seule) : clients en lignes (code et nom figés à gauche),
 * produits en colonnes groupés par famille, cinq lignes de synthèse
 * épinglées (Q, ΣB, S, V, R), trois modes d'affichage. Saisie au clavier
 * comme dans un tableur (motif ARIA « grid », une seule cellule dans
 * l'ordre de tabulation, libellé annoncé à la prise de focus), sélection
 * d'un rectangle par Maj+flèches ou Maj+clic, collage d'un bloc Excel.
 *
 * Fluidité (200 clients × 40 produits) : les lignes sont mémorisées et ne
 * se redessinent que si leurs cellules, leurs saisies, la sélection ou la
 * saisie en cours les concernent ; la frappe dans une cellule ne touche que
 * son champ. ΣB est recalculé par l'appelant à chaque saisie.
 *
 * La cellule active et la saisie en cours sont repérées par leur client et
 * leur produit, pas par leur rang : une grille relue ou filtrée ne déplace
 * pas une saisie vers une autre cellule (RG-B09).
 */

type Editing = CellId & { text: string; mode: 'replace' | 'edit' }

type Props = {
  grid: Grid
  /** Lignes affichées (recherche, catégorie). */
  clients: readonly DistributionGridClient[]
  /** Synthèse par produit, dans l'ordre des colonnes. */
  summaries: readonly ProductSummary[]
  edits: EditsByClient
  mode: DisplayMode
  readOnly: boolean
  /** Cellules refusées par le dernier envoi (violations). */
  highlights: Highlights
  selection: GridSelection | null
  onSelect: (selection: GridSelection) => void
  onEdit: (changes: CellChange[]) => void
  onRevert: (clientId: number, productId: number) => void
  /** Saisie contrôlée d'une cellule (null : rien à changer). */
  makeChange: (clientId: number, productId: number, text: string) => CellChange | null
  label: string
  describedBy?: string
}

const PAGE_ROWS = 10
/** Lignes d'en-tête : familles, produits et cinq lignes de synthèse. */
const HEADER_ROWS = 7

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

const clamp = (value: number, last: number): number => Math.max(0, Math.min(value, last))

/** Libellés partagés par toutes les cellules : traduits une fois par rendu de la grille. */
type Labels = {
  t: TFunction
  noLimit: string
  readOnly: string
  violation: string
  draft: string
  blocked: string
}

export function DistributionGrid({
  grid,
  clients,
  summaries,
  edits,
  mode,
  readOnly,
  highlights,
  selection,
  onSelect,
  onEdit,
  onRevert,
  makeChange,
  label,
  describedBy,
}: Props) {
  const { t } = useTranslation()
  const { products, cells, policy } = grid
  const published = grid.region.distribution_version > 0
  const groups = useMemo(() => groupByFamily(products), [products])
  const familyStarts = useMemo(() => new Set(groups.slice(1).map((group) => group.columns[0])), [groups])
  const lastRow = clients.length - 1
  const lastCol = products.length - 1
  const rowOf = useMemo(() => new Map(clients.map((client, row) => [client.id, row])), [clients])
  const colOf = useMemo(() => new Map(products.map((product, col) => [product.id, col])), [products])
  const labels = useMemo<Labels>(
    () => ({
      t,
      noLimit: t('distribution.grid.noLimit'),
      readOnly: t('distribution.grid.readOnlyCell'),
      violation: t('distribution.grid.violation'),
      draft: t('distribution.grid.draft'),
      blocked: t('distribution.grid.blocked'),
    }),
    [t],
  )

  const { active, range } = locateSelection(selection, clients, products)
  const [editing, setEditing] = useState<Editing | null>(null)
  const [announcement, setAnnouncement] = useState('')
  const table = useRef<HTMLTableElement>(null)
  // Saisie en cours, lue par les gestionnaires d'événements sans attendre le rendu.
  const editingRef = useRef<Editing | null>(null)
  // Le focus suit la cellule active seulement après une action dans la grille.
  const focusRequested = useRef(false)
  // Ctrl+V reçu : l'événement « paste » est-il bien arrivé ?
  const pasteSeen = useRef(true)

  /** Rang actuel d'une cellule ; null si son client (filtre, relecture) ou son produit a disparu. */
  const locate = ({ clientId, productId }: CellId): Position | null => {
    const row = rowOf.get(clientId)
    const col = colOf.get(productId)
    return row === undefined || col === undefined ? null : { row, col }
  }

  const editable = !readOnly && mode === 'limits'

  // Saisie en cours, à son rang actuel ; null si sa cellule a disparu ou
  // n'est plus modifiable (lecture seule, autre mode).
  const editingAt = editing ? locate(editing) : null
  const liveEditing = editing && editingAt && editable ? editing : null
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

  const idAt = (row: number, col: number): (CellId & Position) | null => {
    const client = clients[row]
    const product = products[col]
    return client && product ? { clientId: client.id, productId: product.id, row, col } : null
  }

  const moveTo = (row: number, col: number, extend = false) => {
    if (clients.length === 0 || products.length === 0) return
    focusRequested.current = true
    const target = idAt(clamp(row, lastRow), clamp(col, lastCol))
    if (!target) return
    const current = idAt(active.row, active.col) ?? target
    const anchor = extend ? (selection?.anchor ?? current) : target
    onSelect({ active: target, anchor: { clientId: anchor.clientId, productId: anchor.productId } })
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
    const client = clients[row]
    const product = products[col]
    if (!client || !product || !editable) return null
    return makeChange(client.id, product.id, text)
  }

  const apply = (changes: Array<CellChange | null>) => {
    const valid = changes.filter((item): item is CellChange => item !== null)
    if (valid.length > 0) onEdit(valid)
    return valid.length
  }

  /**
   * Fin de la saisie en cours : la valeur part (ou s'affiche en erreur) pour
   * le client et le produit où elle a commencé, à leur rang actuel ; rien ne
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

  /** Cellules modifiables de la sélection, ligne par ligne. */
  const selectedCells = (): Position[] => {
    const positions: Position[] = []
    for (let row = range.top; row <= range.bottom; row++) {
      for (let col = range.left; col <= range.right; col++) positions.push({ row, col })
    }
    return positions
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
    const extend = event.shiftKey
    const move = (nextRow: number, nextCol: number, extendSelection = false) => {
      event.preventDefault()
      moveTo(nextRow, nextCol, extendSelection)
    }

    switch (event.key) {
      case 'ArrowUp':
        return move(ctrl ? 0 : row - 1, col, extend)
      case 'ArrowDown':
        return move(ctrl ? lastRow : row + 1, col, extend)
      case 'ArrowLeft':
        return move(row, ctrl ? 0 : col - 1, extend)
      case 'ArrowRight':
        return move(row, ctrl ? lastCol : col + 1, extend)
      case 'Enter':
        return move(row + (event.shiftKey ? -1 : 1), col)
      case 'Tab': {
        // Au bord de la grille, Tab en sort (pas de piège au clavier).
        const target = tabTarget(active, event.shiftKey)
        if (target) move(target.row, target.col)
        return
      }
      case 'Home':
        return move(ctrl ? 0 : row, 0, extend)
      case 'End':
        return move(ctrl ? lastRow : row, lastCol, extend)
      case 'PageUp':
        return move(row - PAGE_ROWS, col, extend)
      case 'PageDown':
        return move(row + PAGE_ROWS, col, extend)
    }

    const client = clients[row]
    const product = products[col]
    if (!client || !product) return
    const edit = edits.get(client.id)?.[product.id]

    if (event.key === 'Escape') {
      if (edit?.error) {
        // Cellule en erreur : retour à la valeur du serveur.
        event.preventDefault()
        onRevert(client.id, product.id)
      } else if (range.top !== range.bottom || range.left !== range.right) {
        // Sélection réduite à la cellule active.
        event.preventDefault()
        moveTo(row, col)
      }
      return
    }
    if (ctrl && event.key.toLowerCase() === 'v') {
      if (editable) expectPaste()
      return
    }
    if (!editable || ctrl) return

    if (event.key === 'F2') {
      event.preventDefault()
      startEditing({ clientId: client.id, productId: product.id, text: textToEdit(cells[String(client.id)]?.[String(product.id)], edit), mode: 'edit' })
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
      // Vider la cellule (ou la sélection) retire la borne (RG-B01).
      event.preventDefault()
      apply(selectedCells().map((position) => change(position.row, position.col, '')))
    } else if (event.key.length === 1 && event.key !== ' ') {
      // Une frappe remplace la valeur (comme dans un tableur) ; la sélection se réduit à la cellule active.
      event.preventDefault()
      if (range.top !== range.bottom || range.left !== range.right) {
        onSelect({ active: { clientId: client.id, productId: product.id, row, col }, anchor: { clientId: client.id, productId: product.id } })
      }
      startEditing({ clientId: client.id, productId: product.id, text: event.key, mode: 'replace' })
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
    if (current && current.clientId === clients[target.row]?.id && current.productId === products[target.col]?.id) return
    if (current) commit(editorValue())
    // Maj+clic : rectangle depuis l'ancre.
    if (event.shiftKey) event.preventDefault()
    moveTo(target.row, target.col, event.shiftKey)
  }

  const onDoubleClick = (event: MouseEvent<HTMLTableElement>) => {
    const target = cellAt(event.target)
    if (!target || editingRef.current || !editable) return
    const client = clients[target.row]
    const product = products[target.col]
    if (!client || !product) return
    startEditing({
      clientId: client.id,
      productId: product.id,
      text: textToEdit(cells[String(client.id)]?.[String(product.id)], edits.get(client.id)?.[product.id]),
      mode: 'edit',
    })
  }

  // Champ quitté (clic ailleurs, bouton de la barre d'actions) : la saisie est gardée.
  const onBlur = (event: FocusEvent<HTMLTableElement>) => {
    if (event.target instanceof HTMLInputElement && event.target.dataset.editor !== undefined) commit(event.target.value)
  }

  /**
   * Certains navigateurs n'émettent pas « paste » sur une cellule sans champ :
   * sans événement juste après Ctrl+V, le presse-papiers est lu directement
   * (avec l'accord de l'utilisateur).
   */
  const expectPaste = () => {
    pasteSeen.current = false
    setTimeout(() => {
      if (pasteSeen.current) return
      pasteSeen.current = true
      navigator.clipboard?.readText().then(
        (text) => text !== '' && paste(parseClipboard(text)),
        () => undefined,
      )
    }, 0)
  }

  const onPaste = (event: ClipboardEvent<HTMLTableElement>) => {
    pasteSeen.current = true
    if (!editable) return
    const text = event.clipboardData.getData('text/plain')
    if (text === '') return
    const current = editingRef.current
    // Une simple valeur collée dans le champ en cours : comportement normal du champ.
    if (current && !isBlock(text)) return
    event.preventDefault()
    const at = current ? locate(current) : null
    if (current) stopEditing()
    paste(parseClipboard(text), at ?? undefined)
  }

  /**
   * Bloc collé depuis Excel à partir de la cellule active (coin de la
   * sélection), rogné aux bords de la grille ; une case vide retire la
   * borne. Une valeur seule collée sur une sélection la remplit.
   */
  const paste = (block: string[][], from?: Position) => {
    const start = from ?? { row: range.top, col: range.left }
    const changes: Array<CellChange | null> = []
    const single = block.length === 1 && block[0]?.length === 1
    if (single && !from && (range.top !== range.bottom || range.left !== range.right)) {
      const text = block[0]![0]!.trim()
      for (const position of selectedCells()) changes.push(change(position.row, position.col, text))
    } else {
      block.forEach((values, offsetRow) => {
        const row = start.row + offsetRow
        if (row > lastRow) return
        values.forEach((text, offsetCol) => {
          const col = start.col + offsetCol
          if (col <= lastCol) changes.push(change(row, col, text.trim()))
        })
      })
    }
    const count = changes.filter((item) => item !== null).length
    apply(changes)
    setAnnouncement(t('distribution.grid.pasted', { count }))
    moveTo(start.row, start.col)
  }

  const columnCount = products.length + 1
  let ariaRow = HEADER_ROWS + 1

  return (
    <div className="max-h-[70vh] overflow-auto rounded-lg border border-slate-200 bg-white">
      <table
        ref={table}
        role="grid"
        aria-label={label}
        aria-describedby={describedBy}
        aria-readonly={!editable || undefined}
        aria-multiselectable={editable || undefined}
        aria-colcount={columnCount}
        aria-rowcount={clients.length + HEADER_ROWS}
        onKeyDown={onKeyDown}
        onMouseDown={onMouseDown}
        onDoubleClick={onDoubleClick}
        onBlur={onBlur}
        onPaste={onPaste}
        className="w-max min-w-full border-separate border-spacing-0 text-sm"
      >
        <thead className="sticky top-0 z-20 bg-slate-50">
          <tr aria-rowindex={1}>
            <th
              scope="col"
              rowSpan={2}
              aria-colindex={1}
              className="sticky left-0 z-30 w-60 min-w-60 border-b border-e-2 border-slate-300 bg-slate-50 px-3 py-2 text-start align-bottom font-medium text-slate-600"
            >
              {t('distribution.grid.client')}
            </th>
            {groups.map((group, index) => (
              <th
                key={group.family.id}
                scope="colgroup"
                colSpan={group.columns.length}
                aria-colindex={(group.columns[0] ?? 0) + 2}
                className={cx(
                  'border-b border-e border-slate-300 bg-slate-100 px-3 py-1 text-start text-xs font-semibold uppercase tracking-wide text-slate-700',
                  index > 0 && 'border-s-2',
                )}
              >
                {group.family.name}
              </th>
            ))}
          </tr>
          <tr aria-rowindex={2}>
            {products.map((product, col) => (
              <th
                key={product.id}
                scope="col"
                aria-colindex={col + 2}
                title={`${product.name} — ${product.sku} — ${product.sales_unit_label}`}
                className={cx(
                  'min-w-24 max-w-32 border-b border-e border-slate-300 bg-slate-50 px-2 py-1.5 text-end align-bottom font-semibold text-slate-900',
                  familyStarts.has(col) && 'border-s-2',
                )}
              >
                <span className="block truncate">{product.short_name}</span>
                <span className="block font-mono text-xs font-normal text-slate-500">{product.sku}</span>
              </th>
            ))}
          </tr>
          <SummaryRow kind="quota" ariaRowIndex={3} products={products} summaries={summaries} familyStarts={familyStarts} t={t} />
          <SummaryRow kind="sumLimits" ariaRowIndex={4} products={products} summaries={summaries} familyStarts={familyStarts} t={t} />
          <SummaryRow kind="pending" ariaRowIndex={5} products={products} summaries={summaries} familyStarts={familyStarts} t={t} />
          <SummaryRow kind="validated" ariaRowIndex={6} products={products} summaries={summaries} familyStarts={familyStarts} t={t} />
          <SummaryRow kind="remaining" ariaRowIndex={7} products={products} summaries={summaries} familyStarts={familyStarts} t={t} />
        </thead>
        <tbody>
          {clients.length === 0 ? (
            <tr aria-rowindex={HEADER_ROWS + 1}>
              <td colSpan={columnCount} className="px-3 py-6 text-center text-slate-600">
                <span className="sticky left-3">{t('distribution.grid.noMatch')}</span>
              </td>
            </tr>
          ) : (
            clients.map((client, row) => {
              const inRange = row >= range.top && row <= range.bottom
              return (
                <ClientRow
                  key={client.id}
                  client={client}
                  row={row}
                  ariaRowIndex={ariaRow++}
                  products={products}
                  cells={cells[String(client.id)]}
                  edits={edits.get(client.id)}
                  activeCol={row === active.row ? active.col : null}
                  selectedFrom={inRange ? range.left : null}
                  selectedTo={inRange ? range.right : null}
                  editing={liveEditing && liveEditing.clientId === client.id ? liveEditing : null}
                  mode={mode}
                  readOnly={!editable}
                  policy={policy}
                  published={published}
                  familyStarts={familyStarts}
                  highlights={highlights.get(client.id)}
                  labels={labels}
                />
              )
            })
          )}
        </tbody>
      </table>
      <p role="status" className="sr-only">
        {announcement}
      </p>
    </div>
  )
}

/** Texte proposé à la modification (F2, double-clic) : saisie refusée telle quelle, sinon la valeur affichée. */
function textToEdit(cell: LimitCell | undefined, edit: LocalEdit | undefined): string {
  if (edit && edit.error !== null) return edit.text
  return editableText(displayedLimit(cell, edit))
}

type SummaryKind = 'quota' | 'sumLimits' | 'pending' | 'validated' | 'remaining'

type SummaryRowProps = {
  kind: SummaryKind
  ariaRowIndex: number
  products: readonly DistributionGridProduct[]
  summaries: readonly ProductSummary[]
  familyStarts: ReadonlySet<number | undefined>
  t: TFunction
}

/** Ligne de synthèse épinglée : surallocation en orange (RG-B05), reste épuisé en rouge ; redessinée seulement quand la synthèse change. */
const SummaryRow = memo(function SummaryRow({ kind, ariaRowIndex, products, summaries, familyStarts, t }: SummaryRowProps) {
  const title = t(`distribution.summary.${kind}`)
  return (
    <tr aria-rowindex={ariaRowIndex}>
      <th
        scope="row"
        aria-colindex={1}
        className="sticky left-0 z-30 w-60 min-w-60 border-b border-e-2 border-slate-200 bg-slate-50 px-3 py-1 text-start font-medium text-slate-700"
      >
        {title}
      </th>
      {products.map((product, col) => {
        const summary = summaries[col]
        if (!summary) return null
        let text = formatQuantity(summary[kind])
        let hint: string | undefined
        let tone = 'text-slate-900'
        if (kind === 'sumLimits') {
          if (summary.limited === 0) text = '—'
          if (summary.overallocated) {
            hint = t('distribution.summary.overallocatedHint', { sum: formatQuantity(summary.sumLimits), quota: formatQuantity(summary.quota) })
            tone = 'bg-orange-100 font-semibold text-orange-900'
          }
        } else if (kind === 'remaining' && summary.exhausted) {
          hint = t('distribution.summary.exhaustedHint')
          tone = 'bg-red-100 font-semibold text-red-800'
        }
        return (
          <td
            key={product.id}
            role="gridcell"
            aria-colindex={col + 2}
            aria-label={`${title} — ${product.short_name} : ${text}${hint ? `, ${hint}` : ''}`}
            title={hint}
            data-overallocated={(kind === 'sumLimits' && summary.overallocated) || undefined}
            data-exhausted={(kind === 'remaining' && summary.exhausted) || undefined}
            className={cx('border-b border-e border-slate-200 px-2 py-1 text-end tabular-nums', familyStarts.has(col) && 'border-s-2', tone)}
          >
            {kind === 'sumLimits' && summary.overallocated && (
              <span aria-hidden="true" className="me-1">
                (!)
              </span>
            )}
            {text}
          </td>
        )
      })}
    </tr>
  )
})

type RowProps = {
  client: DistributionGridClient
  /** Rang de la ligne parmi les clients affichés (navigation). */
  row: number
  ariaRowIndex: number
  products: readonly DistributionGridProduct[]
  cells: Record<string, LimitCell> | undefined
  edits: Readonly<Record<number, LocalEdit>> | undefined
  /** Colonne de la cellule active si elle est sur cette ligne. */
  activeCol: number | null
  /** Colonnes sélectionnées sur cette ligne (bornes comprises), ou null. */
  selectedFrom: number | null
  selectedTo: number | null
  editing: Editing | null
  mode: DisplayMode
  readOnly: boolean
  policy: NoLimitPolicy
  published: boolean
  familyStarts: ReadonlySet<number | undefined>
  highlights: ReadonlySet<number> | undefined
  labels: Labels
}

/** Ligne d'un client, redessinée seulement si ses propres données changent. */
const ClientRow = memo(function ClientRow({
  client,
  row,
  ariaRowIndex,
  products,
  cells,
  edits,
  activeCol,
  selectedFrom,
  selectedTo,
  editing,
  mode,
  readOnly,
  policy,
  published,
  familyStarts,
  highlights,
  labels,
}: RowProps) {
  const blocked = client.status === 'BLOCKED'
  const clientLabel = `${client.code} ${client.name}`
  return (
    <tr aria-rowindex={ariaRowIndex}>
      <th
        scope="row"
        aria-colindex={1}
        title={client.category ? `${client.name} — ${client.category.name}` : client.name}
        className="sticky left-0 z-10 w-60 min-w-60 max-w-60 border-b border-e-2 border-slate-200 bg-white px-3 py-1 text-start font-normal"
      >
        <div className="flex items-center gap-2">
          <span className="font-mono text-xs font-medium text-slate-600">{client.code}</span>
          {blocked && <Badge tone="danger">{labels.blocked}</Badge>}
        </div>
        <div className="truncate font-medium text-slate-900">{client.name}</div>
      </th>
      {products.map((product, col) => (
        <LimitCellView
          key={product.id}
          clientLabel={clientLabel}
          product={product}
          row={row}
          col={col}
          cell={cells?.[String(product.id)]}
          edit={edits?.[product.id]}
          active={activeCol === col}
          selected={selectedFrom !== null && selectedTo !== null && col >= selectedFrom && col <= selectedTo}
          editing={editing !== null && editing.productId === product.id ? editing : null}
          mode={mode}
          readOnly={readOnly}
          policy={policy}
          published={published}
          familyStart={familyStarts.has(col)}
          highlighted={highlights?.has(product.id) === true}
          labels={labels}
        />
      ))}
    </tr>
  )
})

type CellProps = {
  clientLabel: string
  product: DistributionGridProduct
  row: number
  col: number
  cell: LimitCell | undefined
  edit: LocalEdit | undefined
  active: boolean
  selected: boolean
  editing: Editing | null
  mode: DisplayMode
  readOnly: boolean
  policy: NoLimitPolicy
  published: boolean
  familyStart: boolean
  highlighted: boolean
  labels: Labels
}

function LimitCellView({
  clientLabel,
  product,
  row,
  col,
  cell,
  edit,
  active,
  selected,
  editing,
  mode,
  readOnly,
  policy,
  published,
  familyStart,
  highlighted,
  labels,
}: CellProps) {
  const { t } = labels
  const value = displayedLimit(cell, edit)
  const pending = cell?.pending ?? 0
  const validated = cell?.validated ?? 0
  const invalid = edit !== undefined && edit.error !== null
  const draft = hasDraft(cell, edit)
  // Valeur affichée refusée : saisie locale, ou brouillon devenu invalide (quota diminué depuis).
  const problem = invalid ? null : checkLimit(value, product.quota, validated, published)
  // Borne publiée affichée sans brouillon, devenue hors limites (quota
  // diminué depuis, RG-Q06) : signalée, ce n'est pas une erreur à corriger.
  const outdated = problem !== null && !draft
  const problemText =
    problem === null
      ? null
      : problem.kind === 'aboveQuota'
        ? t(outdated ? 'distribution.grid.outdatedAboveQuota' : 'distribution.errors.aboveQuota', { quota: formatQuantity(problem.quota) })
        : t(outdated ? 'distribution.grid.outdatedBelowValidated' : 'distribution.errors.belowValidated', { validated: formatQuantity(problem.validated) })
  const error = edit?.error ?? (outdated ? null : problemText)
  const engaged = error === null && belowEngaged(value, cell)
  const warning = engaged || outdated
  const used = pending + validated > 0
  const usage = `S${formatQuantity(pending)} V${formatQuantity(validated)}`

  let text: string
  let spoken: string
  if (mode === 'limits') {
    text = invalid ? edit.text : value === null ? '' : formatQuantity(value)
    spoken = invalid ? edit.text : value === null ? labels.noLimit : formatQuantity(value)
  } else if (mode === 'orders') {
    text = used ? `S${formatQuantity(pending)} · V${formatQuantity(validated)}` : ''
    spoken = t('distribution.grid.orders', { pending: formatQuantity(pending), validated: formatQuantity(validated) })
  } else {
    // D(c,p) sur la borne publiée : un brouillon ne change rien avant l'envoi.
    const rest = formatQuantity(cellAvailable(cell, product.remaining, policy))
    text = rest
    spoken = t('distribution.grid.available', { value: rest })
  }

  const sentLimit = cell?.limit ?? null
  const sent = draft ? t('distribution.grid.sent', { value: sentLimit === null ? labels.noLimit : formatQuantity(sentLimit) }) : null
  // Avertissement, erreur et refus : info-bulle et libellé annoncé.
  const notes: string[] = []
  if (outdated && problemText) notes.push(problemText)
  if (engaged) notes.push(t('distribution.grid.warning', { engaged: formatQuantity(pending + validated) }))
  if (error) notes.push(error)
  if (highlighted) notes.push(labels.violation)
  const hints = sent ? [sent, ...notes] : notes

  const parts: string[] = [`${clientLabel} — ${product.short_name} : ${spoken}`]
  if (mode === 'limits' && used) parts.push(t('distribution.grid.orders', { pending: formatQuantity(pending), validated: formatQuantity(validated) }))
  if (sent) parts.push(`${labels.draft} (${sent})`)
  parts.push(...notes)
  if (readOnly) parts.push(labels.readOnly)

  return (
    <td
      role="gridcell"
      data-row={row}
      data-col={col}
      data-draft={draft || undefined}
      data-warning={warning || undefined}
      data-error={error !== null || undefined}
      data-highlighted={highlighted || undefined}
      aria-colindex={col + 2}
      aria-label={parts.join(', ')}
      aria-selected={selected || undefined}
      aria-readonly={readOnly || undefined}
      aria-invalid={error !== null || undefined}
      tabIndex={active && !editing ? 0 : -1}
      title={hints.length > 0 ? hints.join(' · ') : undefined}
      className={cx(
        'h-11 min-w-24 max-w-32 scroll-mt-64 scroll-mb-4 scroll-ms-64 border-b border-e border-slate-200 px-2 text-end tabular-nums',
        familyStart && 'border-s-2',
        readOnly ? 'cursor-default' : 'cursor-cell',
        error ? 'bg-red-50' : draft ? 'bg-sky-100' : 'bg-white',
        error ? 'font-semibold text-red-700' : warning ? 'font-semibold text-amber-800' : 'text-slate-900',
        // Cellule active : contour visible, plus épais quand la grille a le focus.
        active ? 'outline-2 -outline-offset-2 outline-brand-600 focus:outline-[3px]' : 'outline-none',
        !active && (highlighted || error) && 'ring-2 ring-inset ring-red-600',
        !active && !highlighted && !error && selected && 'ring-2 ring-inset ring-brand-300',
      )}
    >
      {editing ? (
        <CellEditor initial={editing.text} label={t('distribution.grid.input', { client: clientLabel, product: product.short_name })} />
      ) : (
        <div className="flex flex-col items-end leading-tight">
          <span>
            {warning && mode === 'limits' && (
              <span aria-hidden="true" className="me-1">
                !
              </span>
            )}
            {text}
          </span>
          {mode === 'limits' && used && <span className="text-[11px] font-normal text-slate-500">{usage}</span>}
        </div>
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
