import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { REGION_DAY_STATUSES, type Day, type RegionDay, type RegionDayStatus } from '../../../shared/api/types'
import { Badge, Button } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { ConfirmDialog } from '../shared/ui'
import { formatDayLong } from './dates'
import type { OpenDayFlow } from './useOpenDayFlow'

/**
 * Éléments communs du calendrier et de la page d'une journée : statuts
 * (§6.1) en couleur et en texte (la couleur n'est jamais le seul repère),
 * ouverture d'une journée, lien vers la matrice des quotas.
 */

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

// Contraste AA : texte foncé sur fond clair, liseré de la teinte du statut.
const STATUS_STYLES = {
  QUOTA_DRAFT: { chip: 'bg-amber-50 text-amber-900 ring-amber-300', dot: 'bg-amber-500' },
  QUOTA_PUBLISHED: { chip: 'bg-sky-50 text-sky-900 ring-sky-300', dot: 'bg-sky-600' },
  DISTRIBUTION_DRAFT: { chip: 'bg-violet-50 text-violet-900 ring-violet-300', dot: 'bg-violet-600' },
  DISTRIBUTION_PUBLISHED: { chip: 'bg-emerald-50 text-emerald-900 ring-emerald-300', dot: 'bg-emerald-600' },
  CLOSED: { chip: 'bg-slate-100 text-slate-700 ring-slate-300', dot: 'bg-slate-500' },
} as const satisfies Record<RegionDayStatus, { chip: string; dot: string }>

const CHIP = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset'

/** Statut d'une région pour une journée, libellé du serveur. */
export function RegionDayStatusBadge({ status, label }: { status: RegionDayStatus; label: string }) {
  return (
    <span className={cx(CHIP, STATUS_STYLES[status].chip)}>
      <span aria-hidden="true" className={cx('size-2 rounded-full', STATUS_STYLES[status].dot)} />
      {label}
    </span>
  )
}

/** Journée ouverte ou clôturée. */
export function DayStatusBadge({ day }: { day: Pick<Day, 'status' | 'status_label'> }) {
  return <Badge tone={day.status === 'OPEN' ? 'success' : 'neutral'}>{day.status_label}</Badge>
}

/**
 * Pastilles des régions (EF-ADV-01) : code coloré selon le statut, chiffre
 * des brouillons non publiés ; statut et brouillons lus en toutes lettres
 * par les lecteurs d'écran et dans l'info-bulle.
 */
export function RegionPills({ regions }: { regions: RegionDay[] }) {
  const { t } = useTranslation()
  if (regions.length === 0) return <span className="text-xs text-slate-500">{t('backoffice.days.noRegions')}</span>

  return (
    <ul className="flex flex-wrap gap-1.5" aria-label={t('backoffice.days.columns.regions')}>
      {regions.map((item) => {
        const label = t('backoffice.days.pill', { code: item.region.code, name: item.region.name, status: item.status_label })
        const drafts = item.pending_drafts > 0 ? t('backoffice.days.drafts', { count: item.pending_drafts }) : null
        return (
          <li
            key={item.region.id}
            title={drafts ? `${label} — ${drafts}` : label}
            data-status={item.status}
            className={cx(CHIP, 'font-semibold', STATUS_STYLES[item.status].chip)}
          >
            <span aria-hidden="true" className={cx('size-2 rounded-full', STATUS_STYLES[item.status].dot)} />
            <span aria-hidden="true">{item.region.code}</span>
            {drafts && (
              <span aria-hidden="true" className="rounded-full bg-amber-700 px-1.5 text-[11px] leading-4 font-bold text-white">
                {item.pending_drafts}
              </span>
            )}
            <span className="sr-only">{drafts ? `${label}, ${drafts}` : label}</span>
          </li>
        )
      })}
    </ul>
  )
}

/** Légende des couleurs des pastilles. */
export function StatusLegend() {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
      <span className="font-medium text-slate-700">{t('backoffice.days.legend')}</span>
      <ul className="flex flex-wrap items-center gap-x-4 gap-y-2" aria-label={t('backoffice.days.legend')}>
        {REGION_DAY_STATUSES.map((status) => (
          <li key={status} className="inline-flex items-center gap-1.5">
            <span aria-hidden="true" className={cx('size-2.5 rounded-full', STATUS_STYLES[status].dot)} />
            {t(`backoffice.days.regionStatus.${status}`)}
          </li>
        ))}
        <li className="inline-flex items-center gap-1.5">
          <span aria-hidden="true" className="rounded-full bg-amber-700 px-1.5 text-[11px] leading-4 font-bold text-white">
            3
          </span>
          {t('backoffice.days.draftsMarker')}
        </li>
      </ul>
    </div>
  )
}

/** Heure limite applicable à une région, avec la mention « prolongée » (RG-A05). */
export function CutoffTime({ time, extended }: { time: string; extended: boolean }) {
  const { t } = useTranslation()
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5 tabular-nums">
      {time}
      {extended && (
        <span title={t('backoffice.days.regions.extendedLabel')}>
          <Badge tone="warning">{t('backoffice.days.regions.extended')}</Badge>
        </span>
      )}
    </span>
  )
}

const LINK_BUTTON =
  'inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

/**
 * Lien vers la matrice des quotas : « Saisir » avec quota.edit sur une
 * journée ouverte, « Voir » sinon (Admin, journée clôturée).
 */
export function QuotasLink({ day, variant = 'link' }: { day: Pick<Day, 'day' | 'status'>; variant?: 'link' | 'button' }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const action = can('quota.edit') && day.status === 'OPEN' ? t('backoffice.days.links.editQuotas') : t('backoffice.days.links.viewQuotas')
  return (
    <Link
      to={`${base}/journees/${day.day}/quotas`}
      aria-label={t('backoffice.days.links.quotasLabel', { action, day: formatDayLong(day.day) })}
      className={
        variant === 'button'
          ? cx(LINK_BUTTON, 'bg-brand-600 text-white hover:bg-brand-700')
          : 'inline-flex min-h-11 items-center font-medium text-brand-700 hover:underline'
      }
    >
      {action}
    </Link>
  )
}

/** « Ouvrir la journée » (day.manage) : nom accessible complété par la date. */
type OpenDayButtonProps = { day: string; workingDay: boolean; flow: OpenDayFlow; variant?: 'primary' | 'secondary' }

export function OpenDayButton({ day, workingDay, flow, variant = 'secondary' }: OpenDayButtonProps) {
  const { t } = useTranslation()
  const pending = flow.mutation.isPending && flow.mutation.variables === day
  return (
    <Button
      variant={variant}
      aria-label={t('backoffice.days.open.actionLabel', { day: formatDayLong(day) })}
      disabled={flow.mutation.isPending}
      onClick={() => flow.request(day, workingDay)}
    >
      {pending ? t('backoffice.days.open.opening') : t('backoffice.days.open.action')}
    </Button>
  )
}

/** Confirmation d'ouverture d'un jour non ouvré ; le refus du serveur s'y affiche. */
export function OpenDayConfirm({ flow }: { flow: OpenDayFlow }) {
  const { t } = useTranslation()
  return (
    <ConfirmDialog
      open={flow.confirming !== null}
      title={t('backoffice.days.open.confirmTitle')}
      confirmLabel={t('backoffice.days.open.action')}
      pending={flow.mutation.isPending}
      error={flow.mutation.error}
      onConfirm={flow.confirm}
      onCancel={flow.cancel}
    >
      {flow.confirming && <p>{t('backoffice.days.open.confirm', { day: formatDayLong(flow.confirming) })}</p>}
    </ConfirmDialog>
  )
}
