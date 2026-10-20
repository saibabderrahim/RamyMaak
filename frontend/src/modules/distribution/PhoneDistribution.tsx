import { type KeyboardEvent, memo, useCallback, useDeferredValue, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { DistributionGrid as Grid, DistributionGridClient, DistributionGridProduct, LimitCell } from '../../shared/api/types'
import { formatQuantity } from '../../shared/format'
import { Badge, Input, Select } from '../../shared/ui'
import {
  belowEngaged,
  cellAvailable,
  type CellChange,
  checkLimit,
  displayedLimit,
  type EditsByClient,
  editableText,
  filterClients,
  groupByFamily,
  hasDraft,
  type Highlights,
  type LocalEdit,
  type ProductSummary,
} from './model'

type Props = {
  grid: Grid
  edits: EditsByClient
  summaries: readonly ProductSummary[]
  readOnly: boolean
  highlights: Highlights
  onEdit: (changes: CellChange[]) => void
  makeChange: (clientId: number, productId: number, text: string) => CellChange | null
}

type View = 'product' | 'client'

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

/**
 * Vue pivot du téléphone (EF-REG-03), sous 768 px : « Par produit » (vue
 * par défaut) choisit un produit puis liste les clients avec le champ borne
 * (clavier numérique), S et V, et une barre de synthèse fixe Q · ΣB · V · R
 * (orange en surallocation) ; « Par client » choisit un client puis liste
 * ses produits avec la borne et le disponible D. Même enregistrement
 * automatique et mêmes contrôles que la grille PC.
 */
export function PhoneDistribution({ grid, edits, summaries, readOnly, highlights, onEdit, makeChange }: Props) {
  const { t } = useTranslation()
  const [view, setView] = useState<View>('product')
  const [productId, setProductId] = useState<number | null>(null)
  const [clientId, setClientId] = useState<number | null>(null)

  // Saisie d'un champ : la fonction de contrôle change à chaque saisie, les
  // lignes gardent un rappel stable (pas de rendu de toute la liste).
  const makeChangeRef = useRef(makeChange)
  useEffect(() => {
    makeChangeRef.current = makeChange
  })
  const onInput = useCallback(
    (client: number, product: number, text: string) => {
      const change = makeChangeRef.current(client, product, text)
      if (change) onEdit([change])
    },
    [onEdit],
  )

  const product = grid.products.find((item) => item.id === productId) ?? grid.products[0]
  const summary = summaries.find((item) => item.productId === product?.id)

  return (
    <div className="flex flex-col gap-4">
      <div role="group" aria-label={t('distribution.phone.view')} className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
        {(['product', 'client'] as const).map((option) => (
          <button
            key={option}
            type="button"
            aria-pressed={view === option}
            onClick={() => setView(option)}
            className={cx(
              'min-h-11 rounded-lg px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
              view === option ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700 hover:bg-slate-200',
            )}
          >
            {t(`distribution.phone.${option}`)}
          </button>
        ))}
      </div>

      {view === 'product' && product && summary ? (
        <ByProduct
          grid={grid}
          product={product}
          summary={summary}
          summaries={summaries}
          edits={edits}
          readOnly={readOnly}
          highlights={highlights}
          onProduct={setProductId}
          onInput={onInput}
        />
      ) : (
        view === 'client' && <ByClient grid={grid} clientId={clientId} onClient={setClientId} edits={edits} readOnly={readOnly} highlights={highlights} onInput={onInput} />
      )}
    </div>
  )
}

type ByProductProps = {
  grid: Grid
  product: DistributionGridProduct
  summary: ProductSummary
  summaries: readonly ProductSummary[]
  edits: EditsByClient
  readOnly: boolean
  highlights: Highlights
  onProduct: (productId: number) => void
  onInput: (clientId: number, productId: number, text: string) => void
}

/** Par produit : choix du produit (par famille, avec Q et ΣB), clients et leur borne, barre de synthèse fixe. */
function ByProduct({ grid, product, summary, summaries, edits, readOnly, highlights, onProduct, onInput }: ByProductProps) {
  const { t } = useTranslation()
  const productFieldId = useId()
  const searchId = useId()
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const groups = useMemo(() => groupByFamily(grid.products), [grid.products])
  const clients = useMemo(() => filterClients(grid.clients, deferredSearch, null), [grid.clients, deferredSearch])
  const sums = useMemo(() => new Map(summaries.map((item) => [item.productId, item])), [summaries])
  const published = grid.region.distribution_version > 0
  const sumText = (item: ProductSummary | undefined) => (item && item.limited > 0 ? formatQuantity(item.sumLimits) : '—')

  return (
    <>
      <div className="flex flex-col gap-1">
        <label htmlFor={productFieldId} className="text-sm font-medium text-slate-800">
          {t('distribution.phone.productChoice')}
        </label>
        <Select id={productFieldId} value={product.id} onChange={(event) => onProduct(Number(event.target.value))}>
          {groups.map((group) => (
            <optgroup key={group.family.id} label={group.family.name}>
              {group.columns.map((col) => {
                const item = grid.products[col]!
                const itemSummary = sums.get(item.id)
                return (
                  <option key={item.id} value={item.id}>
                    {t('distribution.phone.productOption', {
                      product: item.short_name,
                      quota: formatQuantity(item.quota),
                      sum: sumText(itemSummary),
                    })}
                  </option>
                )
              })}
            </optgroup>
          ))}
        </Select>
        <p className="text-xs text-slate-600">{`${product.name} — ${product.sku} — ${product.sales_unit_label}`}</p>
      </div>

      <div className="flex flex-col gap-1">
        <label htmlFor={searchId} className="text-sm font-medium text-slate-800">
          {t('distribution.filters.search')}
        </label>
        <Input id={searchId} type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('distribution.filters.searchPlaceholder')} />
      </div>

      {clients.length === 0 ? (
        <p className="text-sm text-slate-600">{t('distribution.grid.noMatch')}</p>
      ) : (
        <ul aria-label={t('distribution.phone.clientsOf', { product: product.short_name })} className="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
          {clients.map((client) => (
            <PhoneLimitRow
              key={client.id}
              client={client}
              product={product}
              title={`${client.code} — ${client.name}`}
              context={product.short_name}
              subtitle={null}
              blocked={client.status === 'BLOCKED'}
              cell={grid.cells[String(client.id)]?.[String(product.id)]}
              edit={edits.get(client.id)?.[product.id]}
              readOnly={readOnly}
              published={published}
              policy={grid.policy}
              showAvailable={false}
              highlighted={highlights.get(client.id)?.has(product.id) === true}
              onInput={onInput}
            />
          ))}
        </ul>
      )}

      {/* Barre de synthèse fixe, au-dessus de la barre d'onglets du téléphone. */}
      <section
        aria-label={t('distribution.phone.summary', { product: product.short_name })}
        title={summary.overallocated ? t('distribution.summary.overallocatedHint', { sum: formatQuantity(summary.sumLimits), quota: formatQuantity(summary.quota) }) : undefined}
        data-overallocated={summary.overallocated || undefined}
        className={cx(
          'sticky bottom-[calc(3.75rem+env(safe-area-inset-bottom))] z-30 grid grid-cols-4 gap-1 rounded-xl border px-2 py-2 text-center text-sm shadow-lg',
          summary.overallocated ? 'border-orange-300 bg-orange-100 text-orange-950' : 'border-slate-200 bg-white text-slate-900',
        )}
      >
        {(
          [
            ['quota', formatQuantity(summary.quota)],
            ['sumLimits', sumText(summary)],
            ['validated', formatQuantity(summary.validated)],
            ['remaining', formatQuantity(summary.remaining)],
          ] as const
        ).map(([key, value]) => (
          <div key={key} className={cx('flex flex-col', key === 'remaining' && summary.exhausted && 'font-semibold text-red-800')}>
            <span className="text-xs text-slate-600">{t(`distribution.phone.short.${key}`)}</span>
            <span className="font-semibold tabular-nums">{value}</span>
          </div>
        ))}
      </section>
    </>
  )
}

type ByClientProps = {
  grid: Grid
  clientId: number | null
  onClient: (clientId: number) => void
  edits: EditsByClient
  readOnly: boolean
  highlights: Highlights
  onInput: (clientId: number, productId: number, text: string) => void
}

/** Par client : choix du client (recherche), puis ses produits par famille avec la borne et le disponible. */
function ByClient({ grid, clientId, onClient, edits, readOnly, highlights, onInput }: ByClientProps) {
  const { t } = useTranslation()
  const searchId = useId()
  const clientFieldId = useId()
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const matches = useMemo(() => filterClients(grid.clients, deferredSearch, null), [grid.clients, deferredSearch])
  const groups = useMemo(() => groupByFamily(grid.products), [grid.products])
  // Client choisi absent des résultats de la recherche : le premier trouvé.
  const client = matches.find((item) => item.id === clientId) ?? matches[0]
  const published = grid.region.distribution_version > 0

  return (
    <>
      <div className="flex flex-col gap-1">
        <label htmlFor={searchId} className="text-sm font-medium text-slate-800">
          {t('distribution.filters.search')}
        </label>
        <Input id={searchId} type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('distribution.filters.searchPlaceholder')} />
      </div>
      {!client ? (
        <p className="text-sm text-slate-600">{t('distribution.grid.noMatch')}</p>
      ) : (
        <>
          <div className="flex flex-col gap-1">
            <label htmlFor={clientFieldId} className="text-sm font-medium text-slate-800">
              {t('distribution.phone.clientChoice')}
            </label>
            <Select id={clientFieldId} value={client.id} onChange={(event) => onClient(Number(event.target.value))}>
              {matches.map((item) => (
                <option key={item.id} value={item.id}>
                  {`${item.code} — ${item.name}`}
                </option>
              ))}
            </Select>
            {client.status === 'BLOCKED' && (
              <p>
                <Badge tone="danger">{t('distribution.grid.blocked')}</Badge>
              </p>
            )}
          </div>
          {groups.map((group) => (
            <section key={group.family.id} className="flex flex-col gap-2">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-700">{group.family.name}</h3>
              <ul aria-label={t('distribution.phone.productsOf', { client: client.name, family: group.family.name })} className="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                {group.columns.map((col) => {
                  const product = grid.products[col]!
                  return (
                    <PhoneLimitRow
                      key={product.id}
                      client={client}
                      product={product}
                      title={product.short_name}
                      context={`${client.code} ${client.name}`}
                      subtitle={t('distribution.phone.productFigures', { quota: formatQuantity(product.quota), remaining: formatQuantity(product.remaining) })}
                      blocked={false}
                      cell={grid.cells[String(client.id)]?.[String(product.id)]}
                      edit={edits.get(client.id)?.[product.id]}
                      readOnly={readOnly}
                      published={published}
                      policy={grid.policy}
                      showAvailable
                      highlighted={highlights.get(client.id)?.has(product.id) === true}
                      onInput={onInput}
                    />
                  )
                })}
              </ul>
            </section>
          ))}
        </>
      )}
    </>
  )
}

type RowProps = {
  client: DistributionGridClient
  product: DistributionGridProduct
  /** Libellé visible du champ (client ou produit). */
  title: string
  /** L'autre dimension de la cellule, ajoutée au nom accessible du champ. */
  context: string
  subtitle: string | null
  blocked: boolean
  cell: LimitCell | undefined
  edit: LocalEdit | undefined
  readOnly: boolean
  published: boolean
  policy: Grid['policy']
  /** Vue par client : disponible D(c,p) affiché. */
  showAvailable: boolean
  highlighted: boolean
  onInput: (clientId: number, productId: number, text: string) => void
}

/**
 * Une borne sur téléphone : champ numérique de 44 px au moins, rappel S et
 * V, brouillon non envoyé, avertissement sous E et erreur. Le texte frappé
 * reste affiché tant que le champ a le focus, même quand l'enregistrement
 * le remplace par la valeur du serveur.
 */
const PhoneLimitRow = memo(function PhoneLimitRow({
  client,
  product,
  title,
  context,
  subtitle,
  blocked,
  cell,
  edit,
  readOnly,
  published,
  policy,
  showAvailable,
  highlighted,
  onInput,
}: RowProps) {
  const { t } = useTranslation()
  const inputId = useId()
  const notesId = useId()
  const [typed, setTyped] = useState<string | null>(null)
  const value = displayedLimit(cell, edit)
  const invalid = edit !== undefined && edit.error !== null
  const shown = invalid ? edit.text : editableText(value)
  const pending = cell?.pending ?? 0
  const validated = cell?.validated ?? 0
  const draft = hasDraft(cell, edit)
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
  const sentLimit = cell?.limit ?? null

  const notes: Array<{ key: string; text: string; tone: string }> = []
  if (draft) notes.push({ key: 'sent', text: t('distribution.grid.sent', { value: sentLimit === null ? t('distribution.grid.noLimit') : formatQuantity(sentLimit) }), tone: 'text-sky-800' })
  if (outdated && problemText) notes.push({ key: 'outdated', text: problemText, tone: 'text-amber-800' })
  if (engaged) notes.push({ key: 'warning', text: t('distribution.grid.warning', { engaged: formatQuantity(pending + validated) }), tone: 'text-amber-800' })
  if (error) notes.push({ key: 'error', text: error, tone: 'font-semibold text-red-700' })
  if (highlighted) notes.push({ key: 'violation', text: t('distribution.grid.violation'), tone: 'font-semibold text-red-700' })

  // Entrée : champ suivant de la liste (saisie à la chaîne au pouce).
  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key !== 'Enter') return
    event.preventDefault()
    const inputs = Array.from(event.currentTarget.closest('ul')?.querySelectorAll<HTMLInputElement>('input[data-limit]') ?? [])
    inputs[inputs.indexOf(event.currentTarget) + 1]?.focus()
  }

  return (
    <li
      data-draft={draft || undefined}
      data-warning={warning || undefined}
      data-error={error !== null || undefined}
      className={cx('flex flex-col gap-1 px-3 py-2', error || highlighted ? 'bg-red-50' : draft ? 'bg-sky-50' : undefined)}
    >
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <label htmlFor={inputId} className="block truncate font-medium text-slate-900">
            {title}
          </label>
          {subtitle && <p className="text-xs text-slate-600">{subtitle}</p>}
          <p className="flex flex-wrap items-center gap-x-2 text-xs text-slate-600">
            {blocked && <Badge tone="danger">{t('distribution.grid.blocked')}</Badge>}
            <span>{t('distribution.phone.usage', { pending: formatQuantity(pending), validated: formatQuantity(validated) })}</span>
            {showAvailable && (
              <span className="font-medium text-slate-800">
                {/* D(c,p) sur la borne publiée : un brouillon ne change rien avant l'envoi. */}
                {t('distribution.phone.available', { value: formatQuantity(cellAvailable(cell, product.remaining, policy)) })}
              </span>
            )}
          </p>
        </div>
        <input
          id={inputId}
          data-limit=""
          value={typed ?? shown}
          disabled={readOnly}
          inputMode="numeric"
          enterKeyHint="next"
          autoComplete="off"
          placeholder={t('distribution.phone.noLimitPlaceholder')}
          aria-invalid={error !== null || highlighted}
          aria-describedby={notes.length > 0 ? notesId : undefined}
          aria-label={t('distribution.phone.input', { title, other: context })}
          onFocus={() => setTyped(shown)}
          onBlur={() => setTyped(null)}
          onChange={(event) => {
            setTyped(event.target.value)
            onInput(client.id, product.id, event.target.value)
          }}
          onKeyDown={onKeyDown}
          className={cx(
            'h-12 w-28 shrink-0 rounded-lg border bg-white px-3 text-end text-lg tabular-nums text-slate-900 focus:outline-2 focus:outline-brand-600/40 disabled:bg-slate-100 disabled:text-slate-700',
            error || highlighted ? 'border-red-600' : warning ? 'border-amber-500' : draft ? 'border-sky-500' : 'border-slate-300',
          )}
        />
      </div>
      {notes.length > 0 && (
        <p id={notesId} className="flex flex-col text-xs">
          {notes.map((note) => (
            <span key={note.key} className={note.tone}>
              {note.text}
            </span>
          ))}
        </p>
      )}
    </li>
  )
})
