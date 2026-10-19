import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { ClientFamily } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import type { CatalogueView } from './view'

const CHIP =
  'flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-semibold whitespace-nowrap transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

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
          className={`${CHIP} ${selected === item.id ? 'bg-brand-600 text-white' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'}`}
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
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="sr-only">
        {t('orders.client.catalogue.search')}
      </label>
      <input
        id={id}
        type="search"
        enterKeyHint="search"
        autoComplete="off"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={t('orders.client.catalogue.searchPlaceholder')}
        className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-base text-slate-900 shadow-xs focus:border-brand-600 focus:outline-2 focus:outline-brand-600/30"
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
    <div role="group" aria-label={t('orders.client.catalogue.view')} className="flex shrink-0 gap-1 rounded-lg bg-slate-100 p-1 sm:hidden">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          aria-pressed={view === option.value}
          onClick={() => onChange(option.value)}
          className={`min-h-11 rounded-md px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-brand-600 ${
            view === option.value ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700'
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
        className="mx-auto flex min-h-12 max-w-xl items-center justify-between gap-3 rounded-xl bg-brand-700 px-4 py-2 text-white shadow-lg hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
      >
        <span className="flex flex-col text-sm leading-tight">
          <span className="font-semibold">{t('orders.client.bar.lines', { count: lines })}</span>
          <span className="tabular-nums">{t('orders.client.bar.total', { amount: formatAmount(totalHt) })}</span>
        </span>
        <span className="font-semibold">
          {t('orders.client.bar.see')} <span aria-hidden="true">→</span>
        </span>
      </Link>
    </div>
  )
}
