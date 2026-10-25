import { type ComponentProps, useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { DistributionGrid, DistributionGridClient, RegionDayStatus } from '../../shared/api/types'
import { Badge, Button, Input, Select } from '../../shared/ui'
import { useErrorMessage } from '../../shared/ui/useErrorMessage'
import type { DraftSnapshot } from './drafts'
import { categoriesOf, DISPLAY_MODES, type DisplayMode, formatTime } from './model'

/** Composants communs de la grille de répartition (régional et back-office). */

type Tone = ComponentProps<typeof Badge>['tone']

const STATUS_TONES: Record<RegionDayStatus, Tone> = {
  QUOTA_DRAFT: 'warning',
  QUOTA_PUBLISHED: 'brand',
  DISTRIBUTION_DRAFT: 'brand',
  DISTRIBUTION_PUBLISHED: 'success',
  CLOSED: 'neutral',
}

/** Statut de la journée régionale (§6.1) ; libellé suivi à l'écran après chaque enregistrement. */
export function StatusBadge({ status }: { status: RegionDayStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`distribution.status.${status}`)}</Badge>
}

/**
 * En-tête de la grille : région, statut, version envoyée et heures limites
 * applicables à la région (prolongation comprise, RG-A05). La journée est
 * dans le titre de la page.
 */
export function GridHeader({ grid }: { grid: DistributionGrid }) {
  const { t } = useTranslation()
  const { region } = grid
  const extended = t('distribution.header.extended')
  const cutoff = (key: 'distribution' | 'ordering') =>
    `${t(`distribution.header.${key}`, { time: region.cutoffs[key] })}${region.extended[key] ? ` (${extended})` : ''}`

  return (
    <div className="mb-4 flex flex-col gap-1">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 className="text-lg font-semibold text-slate-900">{t('distribution.header.region', { name: region.name, code: region.code })}</h2>
        <StatusBadge status={region.status} />
        {region.distribution_version > 0 && (
          <span className="text-sm font-medium text-emerald-800">{t('distribution.header.version', { version: region.distribution_version })}</span>
        )}
      </div>
      <p className="text-sm text-slate-700">{`${cutoff('distribution')} · ${cutoff('ordering')}`}</p>
    </div>
  )
}

/**
 * Indicateur d'enregistrement automatique : « Enregistrement… »,
 * « Enregistré à 09:12 », ou échec avec « Réessayer » ; nombre de cellules
 * en erreur, qui ne sont pas envoyées.
 */
export function SaveIndicator({ snapshot, onRetry }: { snapshot: DraftSnapshot; onRetry: () => void }) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const { status, savedAt, invalid } = snapshot

  let message: string | null = null
  if (status === 'pending' || status === 'saving') message = t('distribution.save.saving')
  else if (status === 'saved' && savedAt) message = t('distribution.save.saved', { time: formatTime(savedAt) })
  else if (status === 'error') message = `${t('distribution.save.error')} ${errorMessage(snapshot.error)}`

  return (
    <div className="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 text-sm">
      <p role="status" className={status === 'error' ? 'font-semibold text-red-700' : 'text-slate-600'}>
        {message}
      </p>
      {status === 'error' && (
        <Button variant="secondary" onClick={onRetry}>
          {t('distribution.save.retry')}
        </Button>
      )}
      {invalid > 0 && <p className="font-semibold text-red-700">{t('distribution.save.invalid', { count: invalid })}</p>}
    </div>
  )
}

/** Bascule des modes d'affichage : Bornes, Commandes, Reste. */
export function ModeSwitch({ mode, onChange }: { mode: DisplayMode; onChange: (mode: DisplayMode) => void }) {
  const { t } = useTranslation()
  return (
    <div role="group" aria-label={t('distribution.modes.label')} className="inline-flex gap-1 rounded-lg bg-slate-100 p-1">
      {DISPLAY_MODES.map((option) => (
        <button
          key={option}
          type="button"
          aria-pressed={mode === option}
          onClick={() => onChange(option)}
          className={`min-h-9 rounded-md px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
            mode === option ? 'bg-white text-brand-800' : 'text-slate-700 hover:bg-slate-200'
          }`}
        >
          {t(`distribution.modes.${option}`)}
        </button>
      ))}
    </div>
  )
}

type FiltersProps = {
  clients: readonly DistributionGridClient[]
  search: string
  onSearch: (search: string) => void
  categoryId: number | null
  onCategory: (categoryId: number | null) => void
  /** Nombre de clients affichés après filtrage. */
  shown: number
}

/** Recherche (code, nom) et filtre par catégorie des lignes de la grille. */
export function ClientFilters({ clients, search, onSearch, categoryId, onCategory, shown }: FiltersProps) {
  const { t } = useTranslation()
  const searchId = useId()
  const categoryFieldId = useId()
  const categories = categoriesOf(clients)
  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="flex w-full flex-col gap-1 sm:w-64">
        <label htmlFor={searchId} className="text-sm font-medium text-slate-800">
          {t('distribution.filters.search')}
        </label>
        <Input id={searchId} type="search" value={search} onChange={(event) => onSearch(event.target.value)} placeholder={t('distribution.filters.searchPlaceholder')} />
      </div>
      {categories.length > 0 && (
        <div className="flex w-full flex-col gap-1 sm:w-56">
          <label htmlFor={categoryFieldId} className="text-sm font-medium text-slate-800">
            {t('distribution.filters.category')}
          </label>
          <Select id={categoryFieldId} value={categoryId ?? ''} onChange={(event) => onCategory(event.target.value === '' ? null : Number(event.target.value))}>
            <option value="">{t('distribution.filters.allCategories')}</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </Select>
        </div>
      )}
      <p className="min-h-11 content-center text-sm text-slate-600">{t('distribution.filters.shown', { count: shown, total: clients.length })}</p>
    </div>
  )
}

/** Légende des repères visuels de la grille. */
export function Legend() {
  const { t } = useTranslation()
  const items = [
    { label: t('distribution.legend.draft'), swatch: 'bg-sky-100' },
    { label: t('distribution.legend.warning'), swatch: 'bg-white font-semibold text-amber-800', sample: '!' },
    { label: t('distribution.legend.error'), swatch: 'bg-red-50 ring-2 ring-inset ring-red-600' },
    { label: t('distribution.legend.overallocated'), swatch: 'bg-orange-100 text-orange-900 font-semibold', sample: '!' },
    { label: t('distribution.legend.exhausted'), swatch: 'bg-red-100 text-red-800 font-semibold', sample: '0' },
  ]
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-700">
      <span className="font-semibold">{t('distribution.legend.label')}</span>
      <span>{t('distribution.legend.empty')}</span>
      {items.map((item) => (
        <span key={item.label} className="flex items-center gap-2">
          <span aria-hidden="true" className={`inline-flex h-5 w-8 items-center justify-center rounded border border-slate-300 tabular-nums ${item.swatch}`}>
            {item.sample}
          </span>
          {item.label}
        </span>
      ))}
    </div>
  )
}
