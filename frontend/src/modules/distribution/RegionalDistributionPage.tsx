import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { businessToday, formatDate } from '../../shared/format'
import { Alert, Button, ErrorAlert, PageHeader, Select, Spinner } from '../../shared/ui'
import { useRegionalRegions } from './api'
import { DistributionScreen } from './DistributionScreen'
import { isDayKey, nextDay, weekdayOf } from './model'

/**
 * Grille de répartition du régional (EF-REG-02 sur PC et tablette, EF-REG-03
 * sur téléphone) : `/regional/repartition?day=AAAA-MM-JJ&region=ID`.
 * Aujourd'hui (par défaut) ou demain ; régions du périmètre ce jour-là
 * (`GET /regional/quotas`), choisie d'office quand il n'y en a qu'une, la
 * première quand celle demandée n'en fait pas partie.
 * Contrat : docs/lot-4-contrat-api.md §3 et §5.
 */
export default function RegionalDistributionPage() {
  const { t } = useTranslation()
  const regionFieldId = useId()
  const [searchParams, setSearchParams] = useSearchParams()

  const today = businessToday()
  const tomorrow = nextDay(today)
  const requested = searchParams.get('day')
  const day = isDayKey(requested) ? requested : today

  const regionsQuery = useRegionalRegions(day)
  const data = regionsQuery.data

  const regions = data?.regions ?? []
  const requestedRegion = Number(searchParams.get('region'))
  const selected = regions.find((entry) => entry.region.id === requestedRegion) ?? regions[0]

  const update = (key: 'day' | 'region', value: string) =>
    setSearchParams(
      (params) => {
        const next = new URLSearchParams(params)
        next.set(key, value)
        return next
      },
      { replace: true },
    )

  return (
    <>
      <PageHeader title={t('distribution.title')} />

      <div className="flex flex-col gap-4">
        <div role="group" aria-label={t('distribution.page.dayChoice')} className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 sm:max-w-md">
          {[
            { value: today, label: t('distribution.page.today') },
            { value: tomorrow, label: t('distribution.page.tomorrow') },
          ].map(({ value, label }) => (
            <button
              key={value}
              type="button"
              aria-pressed={day === value}
              onClick={() => update('day', value)}
              className={`flex min-h-12 flex-col items-center justify-center rounded-lg px-3 py-1 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                day === value ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700 hover:bg-slate-200'
              }`}
            >
              {label}
              <span className="text-xs font-normal text-slate-600">{formatDate(value)}</span>
            </button>
          ))}
        </div>

        <p className="font-semibold text-slate-900">{t('distribution.page.dayTitle', { date: `${weekdayOf(day)} ${formatDate(day)}` })}</p>

        {regionsQuery.error && !data && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={regionsQuery.error} />
            <Button variant="secondary" className="self-start" onClick={() => void regionsQuery.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {regionsQuery.isPending && <Spinner />}

        {data &&
          (!data.opened ? (
            <Alert>
              <p className="font-semibold">{t('distribution.page.notOpened')}</p>
              <p>{t('distribution.page.notOpenedHint')}</p>
            </Alert>
          ) : !selected ? (
            <Alert tone="warning">{t('distribution.page.noRegion')}</Alert>
          ) : (
            <>
              {regions.length > 1 && (
                <div className="flex flex-col gap-1 sm:max-w-md">
                  <label htmlFor={regionFieldId} className="text-sm font-medium text-slate-800">
                    {t('distribution.page.region')}
                  </label>
                  <Select id={regionFieldId} value={selected.region.id} onChange={(event) => update('region', event.target.value)}>
                    {regions.map((entry) => (
                      <option key={entry.region.id} value={entry.region.id}>
                        {t('distribution.page.regionOption', {
                          code: entry.region.code,
                          name: entry.region.name,
                          status:
                            entry.distribution_version > 0
                              ? t('distribution.header.version', { version: entry.distribution_version })
                              : t(`distribution.status.${entry.status}`),
                        })}
                      </option>
                    ))}
                  </Select>
                </div>
              )}
              {/* Autre journée ou autre région : écran neuf (file d'enregistrement, sélection). */}
              <DistributionScreen key={`${day}:${selected.region.id}`} day={day} regionId={selected.region.id} />
            </>
          ))}
      </div>
    </>
  )
}
