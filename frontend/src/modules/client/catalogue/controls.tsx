import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { ClientFamily } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import type { CatalogueView } from './view'

const CHIP =
  'flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-semibold whitespace-nowrap transition-colors duration-100 active:translate-y-px ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'

/** Familles en onglets qui défilent horizontalement (§7.3) ; null : toutes. */
export function FamilyTabs({ families, selected, onSelect }: { families: ClientFamily[]; selected: number | null; onSelect: (id: number | null) => void }) {
  const { t } = useTranslation()
  const items = [{ id: null, name: t('orders.client.catalogue.allFamilies') }, ...families.map((family) => ({ id: family.id, name: family.name }))]
  return (
    <div role="group" aria-label={t('orders.client.catalogue.families')} className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
      {items.map((item) => (
        <button
          key={item.id ?? 'all'}
          type="button"
          aria-pressed={selected === item.id}
          onClick={() => onSelect(item.id)}
          className={`${CHIP} ${selected === item.id ? 'border border-brand-600 bg-brand-600 text-white' : 'border border-slate-300 bg-white text-slate-900 hover:border-field'}`}
        >
          {item.name}
        </button>
      ))}
    </div>
  )
}

/** Recherche instantanée sur le nom, le nom court et le code article. */
export function SearchBox({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const { t } = useTranslation()
  const id = useId()
  return (
    <div className="relative flex flex-col gap-1">
      <label htmlFor={id} className="sr-only">
        {t('orders.client.catalogue.search')}
      </label>
      <svg viewBox="0 0 24 24" aria-hidden="true" className="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-slate-600" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
        <path d="M11 4.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13zM16 16l4.5 4.5" />
      </svg>
      <input
        id={id}
        type="search"
        enterKeyHint="search"
        autoComplete="off"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={t('orders.client.catalogue.searchPlaceholder')}
        className="min-h-11 w-full rounded-lg border border-field bg-white ps-10 pe-3 text-base text-slate-900 placeholder:text-slate-500 focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-focus"
      />
    </div>
  )
}

/** Liste compacte ou galerie à 2 colonnes, sur téléphone seulement (la tablette et le PC sont en galerie). */
export function ViewToggle({ view, onChange }: { view: CatalogueView; onChange: (view: CatalogueView) => void }) {
  const { t } = useTranslation()
  const options = [
    { value: 'list', label: t('orders.client.catalogue.list') },
    { value: 'gallery', label: t('orders.client.catalogue.gallery') },
  ] as const
  return (
    <div role="group" aria-label={t('orders.client.catalogue.view')} className="flex shrink-0 gap-0.5 rounded-lg bg-slate-100 p-0.5 sm:hidden">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          aria-pressed={view === option.value}
          onClick={() => onChange(option.value)}
          className={`min-h-10 rounded-md px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-focus ${
            view === option.value ? 'bg-white text-slate-900 shadow-[0_1px_2px_rgb(35_28_26/0.08)]' : 'text-slate-600 hover:text-slate-900'
          }`}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

/**
 * Barre du panier fixée en bas de l'écran (téléphone, tablette), au-dessus
 * des onglets : nombre de lignes et total HT indicatif, ouvre le panier.
 */
export function CartBar({ lines, totalHt }: { lines: number; totalHt: number }) {
  const { t } = useTranslation()
  return (
    <div className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 px-4 pb-2 lg:bottom-0">
      <Link
        to="/client/panier"
        className="mx-auto flex min-h-13 max-w-xl items-center justify-between gap-3 rounded-lg bg-brand-600 px-4 py-2 text-white shadow-[0_12px_32px_rgb(20_16_13/0.22)] hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
      >
        <span className="flex flex-col text-sm leading-tight">
          <span className="font-semibold">{t('orders.client.bar.lines', { count: lines })}</span>
          <span className="tabular-nums">{t('orders.client.bar.total', { amount: formatAmount(totalHt) })}</span>
        </span>
        <span className="inline-flex items-center gap-1 font-semibold">
          {t('orders.client.bar.see')}
          <svg viewBox="0 0 24 24" aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="m9 5 7 7-7 7" />
          </svg>
        </span>
      </Link>
    </div>
  )
}
