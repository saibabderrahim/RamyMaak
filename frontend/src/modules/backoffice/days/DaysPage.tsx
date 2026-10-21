import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { Day } from '../../../shared/api/types'
import { businessToday, formatDate } from '../../../shared/format'
import { useLiveRefresh } from '../../../shared/realtime'
import { Badge, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { addDays } from '../shared/dates'
import { DataTable, TableHead, Th } from '../shared/ui'
import { DAY_EVENTS, type DaysList, type DaysRange, daysKey, useDays } from './api'
import { DayStatusBadge, MonitoringLink, OpenDayButton, OpenDayConfirm, QuotasLink, RegionPills, StatusLegend } from './components'
import { datesBetween, formatDayLong, openWindow, spanOf, weekdayOf } from './dates'
import { type OpenDayFlow, useOpenDayFlow } from './useOpenDayFlow'

const LINK_BUTTON =
  'inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

/**
 * Calendrier des journées (EF-ADV-01) : toutes les dates de la période
 * (d'aujourd'hui − 7 à + 14 par défaut), ouvertes ou non ; statut et
 * pastilles par région ; accès direct à J et J+1 ; ouverture d'une journée
 * (day.manage), avec confirmation pour un jour non ouvré. Tenu à jour par
 * les canaux `adv.{J}` et `adv.{J+1}` (quotas, envoi des répartitions),
 * sinon toutes les 30 s (§9.6).
 */
export default function DaysPage() {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const [range, setRange] = useState<DaysRange>({})
  const flow = useOpenDayFlow()

  const clientToday = businessToday()
  const liveToday = useLiveRefresh(`adv.${clientToday}`, DAY_EVENTS, [daysKey])
  const liveTomorrow = useLiveRefresh(`adv.${addDays(clientToday, 1)}`, DAY_EVENTS, [daysKey])
  const days = useDays(range, { refetchInterval: liveToday || liveTomorrow })

  const today = days.data?.meta.today ?? clientToday
  const tomorrow = addDays(today, 1)

  return (
    <>
      <PageHeader
        title={t('backoffice.days.title')}
        actions={
          <nav aria-label={t('backoffice.days.quick.label')} className="flex flex-wrap gap-2">
            <Link to={`${base}/journees/${today}`} className={LINK_BUTTON}>
              {t('backoffice.days.quick.today')}
            </Link>
            <Link to={`${base}/journees/${tomorrow}`} className={LINK_BUTTON}>
              {t('backoffice.days.quick.tomorrow')}
            </Link>
          </nav>
        }
      />

      <div className="flex flex-col gap-4">
        <p className="text-sm text-slate-600">{t('backoffice.days.intro')}</p>
        {flow.confirming === null && <ErrorAlert error={flow.mutation.error} />}

        <Card>
          <ErrorAlert error={days.error} className="mb-4" />
          {days.isPending && <Spinner />}
          {days.data && (
            <div className="flex flex-col gap-4">
              <PeriodNav meta={days.data.meta} isDefault={range.from === undefined} onChange={setRange} />
              <StatusLegend />
              <Calendar list={days.data} flow={flow} />
            </div>
          )}
        </Card>
      </div>

      <OpenDayConfirm flow={flow} />
    </>
  )
}

/** Périodes voisines de même longueur ; retour à la période par défaut. */
function PeriodNav({ meta, isDefault, onChange }: { meta: DaysList['meta']; isDefault: boolean; onChange: (range: DaysRange) => void }) {
  const { t } = useTranslation()
  const span = spanOf(meta.from, meta.to)
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <p className="text-sm font-medium text-slate-800" aria-live="polite">
        <span className="sr-only">{t('backoffice.days.period.label')} : </span>
        {t('backoffice.days.period.range', { from: formatDate(meta.from), to: formatDate(meta.to) })}
      </p>
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => onChange({ from: addDays(meta.from, -span), to: addDays(meta.from, -1) })}>
          <span aria-hidden="true">←</span> {t('backoffice.days.period.previous')}
        </Button>
        {!isDefault && (
          <Button variant="ghost" onClick={() => onChange({})}>
            {t('backoffice.days.period.current')}
          </Button>
        )}
        <Button variant="secondary" onClick={() => onChange({ from: addDays(meta.to, 1), to: addDays(meta.to, span) })}>
          {t('backoffice.days.period.next')} <span aria-hidden="true">→</span>
        </Button>
      </div>
    </div>
  )
}

/** Une ligne par date de la période ; aujourd'hui mis en évidence. */
function Calendar({ list, flow }: { list: DaysList; flow: OpenDayFlow }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const { meta } = list
  const opened = new Map<string, Day>(list.data.map((day) => [day.day, day]))
  const tomorrow = addDays(meta.today, 1)

  return (
    <DataTable label={t('backoffice.days.calendar')} className="min-w-[880px]">
      <TableHead>
        <Th>{t('backoffice.days.columns.day')}</Th>
        <Th>{t('backoffice.days.columns.status')}</Th>
        <Th>{t('backoffice.days.columns.delivery')}</Th>
        <Th>{t('backoffice.days.columns.regions')}</Th>
        <Th>{t('backoffice.days.columns.actions')}</Th>
      </TableHead>
      <tbody className="divide-y divide-slate-100">
        {datesBetween(meta.from, meta.to).map((date) => {
          const day = opened.get(date)
          const isToday = date === meta.today
          const workingDay = day?.is_working_day ?? meta.working_days.includes(weekdayOf(date))
          const label = formatDayLong(date)

          return (
            <tr
              key={date}
              data-day={date}
              aria-current={isToday ? 'date' : undefined}
              className={isToday ? 'bg-brand-50' : workingDay ? undefined : 'bg-slate-50 text-slate-600'}
            >
              <th scope="row" className="py-2 pe-4 text-start align-top font-normal">
                <span className="flex flex-col gap-1">
                  {day ? (
                    <Link to={`${base}/journees/${date}`} className="font-semibold text-brand-700 first-letter:uppercase hover:underline">
                      {label}
                    </Link>
                  ) : (
                    <span className="font-medium first-letter:uppercase">{label}</span>
                  )}
                  <span className="flex flex-wrap gap-1">
                    {isToday && <Badge tone="brand">{t('backoffice.days.badges.today')}</Badge>}
                    {date === tomorrow && <Badge>{t('backoffice.days.badges.tomorrow')}</Badge>}
                    {!workingDay && <Badge tone="warning">{t('backoffice.days.badges.nonWorking')}</Badge>}
                  </span>
                </span>
              </th>
              <td className="py-2 pe-4 align-top">
                {day ? <DayStatusBadge day={day} /> : <span className="text-xs text-slate-500">{t('backoffice.days.notOpened')}</span>}
              </td>
              <td className="py-2 pe-4 align-top whitespace-nowrap tabular-nums">{day ? formatDate(day.delivery_date) : t('common.none')}</td>
              <td className="py-2 pe-4 align-top">{day && <RegionPills regions={day.regions} />}</td>
              <td className="py-1 align-top">
                {day ? (
                  <span className="flex flex-col items-start">
                    <QuotasLink day={day} />
                    <MonitoringLink day={day} variant="link" />
                  </span>
                ) : (
                  can('day.manage') && openWindow(date, meta.today) === 'open' && <OpenDayButton day={date} workingDay={workingDay} flow={flow} />
                )}
              </td>
            </tr>
          )
        })}
      </tbody>
    </DataTable>
  )
}
