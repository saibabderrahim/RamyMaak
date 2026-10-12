import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { Day, ExtendableCutoff, RegionDay } from '../../../shared/api/types'
import { formatDate, formatDateTime } from '../../../shared/format'
import { useLiveRefresh } from '../../../shared/realtime'
import { Alert, Badge, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { DataTable, DetailList, EmptyState, TableHead, Th } from '../shared/ui'
import { DAY_EVENTS, daysKey, useDay, useDays, useUpdateDay } from './api'
import {
  CutoffTime,
  DayStatusBadge,
  DistributionLink,
  DistributionState,
  OpenDayButton,
  OpenDayConfirm,
  QuotasLink,
  RegionDayStatusBadge,
} from './components'
import { formatDayLong, isDay, OPEN_DAYS_AHEAD, openWindow, weekdayOf } from './dates'
import { ExtendCutoffDialog } from './ExtendCutoffDialog'
import { ScheduleForm } from './ScheduleForm'
import { useOpenDayFlow } from './useOpenDayFlow'

/**
 * Page d'une journée (EF-ADV-01, §4.3) : statut, date de livraison, heures
 * limites (modifiables avec day.manage tant que la journée est ouverte),
 * régions (statut, version et dernière publication des quotas, état de la
 * répartition aux clients et lien vers sa grille, heures limites
 * applicables, brouillons), « Prolonger » (day.extend-cutoff, RG-A05), lien
 * vers la matrice. Journée non ouverte : proposition de l'ouvrir. Tenue à
 * jour par le canal `adv.{day}` (§9.6), envoi des répartitions compris.
 */
export default function DayDetailPage() {
  const { day = '' } = useParams()
  return <DayDetail key={day} day={day} />
}

function DayDetail({ day }: { day: string }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const valid = isDay(day)
  const refetchInterval = useLiveRefresh(valid ? `adv.${day}` : null, DAY_EVENTS, [daysKey])
  const query = useDay(day, { enabled: valid, refetchInterval })

  const back = (
    <Link to={`${base}/journees`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
      {t('backoffice.days.links.calendar')}
    </Link>
  )

  if (!valid) {
    return (
      <>
        <PageHeader title={t('backoffice.days.title')} actions={back} />
        <Alert tone="warning">{t('backoffice.days.detail.invalid')}</Alert>
      </>
    )
  }

  const title = t('backoffice.days.detail.title', { day: formatDayLong(day) })
  if (query.isPending) return <Spinner />
  // Échec d'une relecture en arrière-plan : la journée déjà lue reste affichée.
  if (!query.data) {
    const notOpened = query.error instanceof ApiError && query.error.status === 404
    return (
      <>
        <PageHeader title={title} actions={back} />
        {notOpened ? <NotOpened day={day} /> : <ErrorAlert error={query.error} />}
      </>
    )
  }

  const current = query.data
  return (
    <>
      <PageHeader
        title={title}
        actions={
          <>
            {back}
            <QuotasLink day={current} variant="button" />
          </>
        }
      />
      <div className="-mt-4 mb-6 flex flex-wrap items-center gap-3 text-sm text-slate-700">
        <DayStatusBadge day={current} />
        {!current.is_working_day && <Badge tone="warning">{t('backoffice.days.badges.nonWorking')}</Badge>}
        <span>{t('backoffice.days.detail.deliveryOn', { date: formatDate(current.delivery_date) })}</span>
      </div>
      <div className="flex flex-col gap-6">
        <ErrorAlert error={query.error} />
        <div className="grid gap-6 lg:grid-cols-[2fr_3fr]">
          <Summary day={current} />
          <ScheduleCard day={current} />
        </div>
        <RegionsCard day={current} />
      </div>
    </>
  )
}

/** Journée non ouverte (404) : ouverture proposée avec day.manage, dans la fenêtre permise. */
function NotOpened({ day }: { day: string }) {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const manage = can('day.manage')
  const flow = useOpenDayFlow()
  // Jour métier et jours ouvrés lus sur le serveur (calendrier réduit à cette date).
  const calendar = useDays({ from: day, to: day }, { enabled: manage })
  const meta = calendar.data?.meta
  const position = meta ? openWindow(day, meta.today) : null

  return (
    <Card>
      <div className="flex flex-col gap-4">
        <Alert tone="warning">
          <p className="font-semibold">{t('backoffice.days.detail.notOpenedTitle')}</p>
          <p>{t('backoffice.days.detail.notOpened', { day: formatDayLong(day) })}</p>
        </Alert>
        {manage && (
          <>
            {flow.confirming === null && <ErrorAlert error={flow.mutation.error} />}
            <ErrorAlert error={calendar.error} />
            {calendar.isPending && <Spinner />}
            {meta && position === 'open' && (
              <OpenDayButton day={day} workingDay={meta.working_days.includes(weekdayOf(day))} flow={flow} variant="primary" />
            )}
            {position === 'past' && <p className="text-sm text-slate-600">{t('backoffice.days.open.tooEarly')}</p>}
            {position === 'tooFar' && <p className="text-sm text-slate-600">{t('backoffice.days.open.tooLate', { days: OPEN_DAYS_AHEAD })}</p>}
          </>
        )}
      </div>
      <OpenDayConfirm flow={flow} />
    </Card>
  )
}

function Summary({ day }: { day: Day }) {
  const { t } = useTranslation()
  return (
    <Card title={t('backoffice.days.detail.summary')}>
      <div className="flex flex-col gap-4">
        {day.status === 'CLOSED' && <Alert>{t('backoffice.days.detail.closedNotice')}</Alert>}
        <DetailList
          items={[
            {
              label: t('backoffice.days.detail.workingDay'),
              value: day.is_working_day ? t('common.yes') : t('backoffice.days.detail.nonWorkingDay'),
            },
            { label: t('backoffice.days.detail.openedBy'), value: day.created_by?.name },
            { label: t('backoffice.days.detail.openedAt'), value: formatDateTime(day.created_at) },
            ...(day.closed_at ? [{ label: t('backoffice.days.detail.closedAt'), value: formatDateTime(day.closed_at) }] : []),
          ]}
        />
      </div>
    </Card>
  )
}

/** Heures limites : formulaire avec day.manage sur une journée ouverte, lecture sinon. */
function ScheduleCard({ day }: { day: Day }) {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const update = useUpdateDay(day.day)
  const editable = can('day.manage') && day.status === 'OPEN'

  return (
    <Card title={t('backoffice.days.cutoffs.title')}>
      {editable ? (
        <>
          {update.isSuccess && (
            <Alert tone="success" className="mb-4">
              {t('backoffice.days.cutoffs.saved')}
            </Alert>
          )}
          <ScheduleForm
            key={`${Object.values(day.cutoffs).join('|')}|${day.delivery_date}`}
            day={day}
            submitting={update.isPending}
            error={update.error}
            onSubmit={(input) => {
              update.reset()
              return update.mutateAsync(input)
            }}
          />
        </>
      ) : (
        <div className="flex flex-col gap-4">
          {day.status === 'OPEN' && <p className="text-sm text-slate-600">{t('backoffice.days.cutoffs.readOnly')}</p>}
          <DetailList
            items={[
              { label: t('backoffice.days.cutoffs.distribution'), value: <span className="tabular-nums">{day.cutoffs.distribution}</span> },
              { label: t('backoffice.days.cutoffs.ordering'), value: <span className="tabular-nums">{day.cutoffs.ordering}</span> },
              { label: t('backoffice.days.cutoffs.validation'), value: <span className="tabular-nums">{day.cutoffs.validation}</span> },
              { label: t('backoffice.days.cutoffs.closing'), value: <span className="tabular-nums">{day.cutoffs.closing}</span> },
              { label: t('backoffice.days.detail.delivery'), value: formatDate(day.delivery_date) },
            ]}
          />
        </div>
      )}
    </Card>
  )
}

/** Régions du périmètre : statut, quotas, répartition, heures limites applicables, prolongation. */
function RegionsCard({ day }: { day: Day }) {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const [extending, setExtending] = useState<RegionDay | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const canExtend = can('day.extend-cutoff') && day.status === 'OPEN'

  const done = (regionDay: RegionDay, kind: ExtendableCutoff, until: string) => {
    setExtending(null)
    setNotice(
      t('backoffice.days.extend.done', {
        kind: t(`backoffice.days.extend.kindLower.${kind}`),
        name: regionDay.region.name,
        time: until,
      }),
    )
  }

  return (
    <Card title={t('backoffice.days.regions.title')}>
      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}
      {day.regions.length === 0 ? (
        <EmptyState>{t('backoffice.days.regions.empty')}</EmptyState>
      ) : (
        <DataTable label={t('backoffice.days.regions.title')} className="min-w-[1120px]">
          <TableHead>
            <Th>{t('backoffice.days.regions.columns.region')}</Th>
            <Th>{t('backoffice.days.regions.columns.status')}</Th>
            <Th className="text-end">{t('backoffice.days.regions.columns.version')}</Th>
            <Th>{t('backoffice.days.regions.columns.lastPublication')}</Th>
            <Th>{t('backoffice.days.regions.columns.distributionState')}</Th>
            <Th>{t('backoffice.days.regions.columns.distribution')}</Th>
            <Th>{t('backoffice.days.regions.columns.ordering')}</Th>
            <Th className="text-end">{t('backoffice.days.regions.columns.drafts')}</Th>
            {canExtend && <Th>{t('backoffice.days.regions.columns.actions')}</Th>}
          </TableHead>
          <tbody className="divide-y divide-slate-100">
            {day.regions.map((item) => (
              <tr key={item.region.id} data-region={item.region.code}>
                <th scope="row" className="py-2 pe-4 text-start font-normal">
                  <span className="font-mono text-xs font-semibold">{item.region.code}</span>
                  <span className="block text-slate-700">{item.region.name}</span>
                </th>
                <td className="py-2 pe-4">
                  <RegionDayStatusBadge status={item.status} label={item.status_label} />
                </td>
                <td className="py-2 pe-4 text-end tabular-nums">
                  {item.quota_version > 0 ? t('backoffice.days.regions.version', { version: item.quota_version }) : t('common.none')}
                </td>
                <td className="py-2 pe-4 whitespace-nowrap">
                  {item.quota_last_published_at ? (
                    <span
                      title={
                        item.quota_published_at
                          ? t('backoffice.days.regions.firstPublication', { date: formatDateTime(item.quota_published_at) })
                          : undefined
                      }
                    >
                      {formatDateTime(item.quota_last_published_at)}
                    </span>
                  ) : (
                    <span className="text-slate-500">{t('backoffice.days.regions.notPublished')}</span>
                  )}
                </td>
                <td className="py-2 pe-4">
                  <span className="flex flex-col items-start gap-0.5">
                    <DistributionState regionDay={item} />
                    <DistributionLink day={day.day} regionDay={item} />
                  </span>
                </td>
                <td className="py-2 pe-4">
                  <CutoffTime time={item.cutoffs.distribution} extended={item.extended.distribution} />
                </td>
                <td className="py-2 pe-4">
                  <CutoffTime time={item.cutoffs.ordering} extended={item.extended.ordering} />
                </td>
                <td className="py-2 pe-4 text-end tabular-nums">
                  {item.pending_drafts > 0 ? (
                    <span className="font-semibold text-amber-800">{t('backoffice.days.drafts', { count: item.pending_drafts })}</span>
                  ) : (
                    t('common.none')
                  )}
                </td>
                {canExtend && (
                  <td className="py-1">
                    {item.status !== 'CLOSED' && (
                      <Button
                        variant="secondary"
                        aria-label={t('backoffice.days.extend.actionLabel', { name: item.region.name })}
                        onClick={() => {
                          setNotice(null)
                          setExtending(item)
                        }}
                      >
                        {t('backoffice.days.extend.action')}
                      </Button>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </DataTable>
      )}
      <ExtendCutoffDialog day={day} regionDay={extending} onClose={() => setExtending(null)} onDone={done} />
    </Card>
  )
}
