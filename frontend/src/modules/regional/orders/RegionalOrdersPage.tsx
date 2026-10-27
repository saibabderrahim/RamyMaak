import { useId, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useSearchParams } from 'react-router'
import { businessToday, formatDate } from '../../../shared/format'
import { NoOfflineData, OfflineDataNotice } from '../../../shared/offline/OfflineDataNotice'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useErrorMessage } from '../../../shared/ui/useErrorMessage'
import { useRegionalRegions } from '../../distribution/api'
import { useWideScreen } from '../../distribution/hooks'
import { formatTime, isDayKey, nextDay, weekdayOf } from '../quotas/format'
import { ORDER_EVENTS, regionalOrdersKey, useLiveIntervals, useRegionalOrders } from './api'
import { DayToggle, OrderCards, OrdersTable, OrderTabs, RegionFilter, RegionOrdersLive } from './components'
import { type OrderTab, ordersOf, regionParam, tabOf } from './model'

/**
 * Commandes reçues par le régional (EF-REG-04, en lecture) :
 * `/regional/commandes?day=AAAA-MM-JJ&onglet=a-valider&region=ID`.
 * Aujourd’hui (par défaut) ou demain ; région à choisir quand le périmètre
 * du jour en compte plusieurs (`GET /regional/quotas`), toutes par défaut ;
 * onglets « À valider », « Validées », « Rejetées », « Annulées et
 * expirées » avec leur compteur ; commandes par heure d’envoi (RG-V01),
 * cartes sur téléphone, tableau à partir de 768 px. Tenue à jour en temps
 * réel sur le canal de chaque région affichée (§9.6), sinon toutes les 30 s.
 * Contrat : docs/lot-5-contrat-api.md §4 à §6.
 */
export default function RegionalOrdersPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()

  const today = businessToday()
  const tomorrow = nextDay(today)
  const requested = searchParams.get('day')
  const day = isDayKey(requested) ? requested : today
  const tab = tabOf(searchParams.get('onglet'))
  const requestedRegion = regionParam(searchParams.get('region'))

  const regionsQuery = useRegionalRegions(day)
  const regionsData = regionsQuery.data
  const regions = useMemo(() => (regionsData?.opened ? regionsData.regions.map((entry) => entry.region) : []), [regionsData])
  // Région demandée hors du périmètre du jour, ou seule région : toutes.
  const region = regions.length > 1 ? (regions.find((entry) => entry.id === requestedRegion)?.id ?? null) : null
  const liveRegions = region !== null ? [region] : regions.map((entry) => entry.id)

  const { interval, report } = useLiveIntervals()
  const orders = useRegionalOrders(day, region, tab, {
    // Sans région demandée, la liste part en même temps que les régions ; journée non ouverte : rien à lire.
    enabled: regionsData ? regions.length > 0 : requestedRegion === null,
    refetchInterval: interval,
  })

  const update = (changes: Record<string, string | null>) =>
    setSearchParams(
      (params) => {
        const next = new URLSearchParams(params)
        for (const [key, value] of Object.entries(changes)) {
          if (value === null) next.delete(key)
          else next.set(key, value)
        }
        return next
      },
      { replace: true },
    )

  return (
    <>
      <PageHeader title={t('orders.regional.title')} />

      <div className="flex flex-col gap-4">
        <DayToggle day={day} today={today} tomorrow={tomorrow} onChange={(value) => update({ day: value })} />

        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p className="font-semibold text-slate-900">{t('orders.regional.dayTitle', { date: `${weekdayOf(day)} ${formatDate(day)}` })}</p>
          {regions.length > 0 && orders.data && !orders.isPlaceholderData && (
            <p className="text-xs text-slate-500">{t('orders.regional.updatedAt', { time: formatTime(orders.dataUpdatedAt) })}</p>
          )}
        </div>

        {/* Lot 8 (§6.1) : hors ligne, les commandes enregistrées sur l'appareil. */}
        <OfflineDataNotice
          updatedAt={orders.data ? orders.dataUpdatedAt : regionsQuery.dataUpdatedAt}
          hasData={regionsData !== undefined}
        />

        {regionsQuery.error && !regionsData && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={regionsQuery.error} />
            <Button variant="secondary" className="self-start" onClick={() => void regionsQuery.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {regionsQuery.isPending && regionsQuery.fetchStatus !== 'paused' && <Spinner />}

        {regionsData &&
          (!regionsData.opened ? (
            <Alert>
              <p className="font-semibold">{t('orders.regional.notOpened')}</p>
              <p>{t('orders.regional.notOpenedHint')}</p>
            </Alert>
          ) : regions.length === 0 ? (
            <Alert tone="warning">{t('orders.regional.noRegion')}</Alert>
          ) : (
            <>
              {regions.length > 1 && (
                <RegionFilter regions={regions} value={region} onChange={(value) => update({ region: value === null ? null : String(value) })} />
              )}
              {liveRegions.map((regionId) => (
                <RegionOrdersLive
                  key={`${day}-${regionId}`}
                  regionId={regionId}
                  day={day}
                  events={ORDER_EVENTS}
                  queryKeys={[regionalOrdersKey(day)]}
                  onInterval={report}
                />
              ))}
              <OrdersBoard
                day={day}
                tab={tab}
                showRegion={region === null && regions.length > 1}
                orders={orders}
                onTab={(next) => update({ onglet: next.slug })}
              />
            </>
          ))}
      </div>
    </>
  )
}

type BoardProps = {
  day: string
  tab: OrderTab
  showRegion: boolean
  orders: ReturnType<typeof useRegionalOrders>
  onTab: (tab: OrderTab) => void
}

/** Onglets et liste de l'onglet choisi, avec ses pages suivantes. */
function OrdersBoard({ day, tab, showRegion, orders, onTab }: BoardProps) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const wide = useWideScreen()
  const location = useLocation()
  const panelId = useId()

  const pages = orders.data?.pages ?? []
  const counts = pages[pages.length - 1]?.meta.counts ?? null
  const items = useMemo(() => ordersOf(orders.data?.pages ?? []), [orders.data])
  const total = pages[0]?.meta.total ?? 0
  const loading = orders.isPending || orders.isPlaceholderData
  const label = t('orders.regional.list.label', { tab: t(`orders.regional.tabs.${tab.key}`), date: formatDate(day) })

  return (
    <div className="flex flex-col gap-3">
      <OrderTabs active={tab} counts={counts} panelId={panelId} onSelect={onTab} />

      <div role="tabpanel" id={panelId} aria-labelledby={`${panelId}-${tab.slug}`} className="flex flex-col gap-3">
        {orders.error && !orders.data ? (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={orders.error} />
            <Button variant="secondary" className="self-start" onClick={() => void orders.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        ) : loading ? (
          // Hors ligne, onglet jamais lu sur cet appareil : rien à attendre avant le retour du réseau.
          orders.fetchStatus === 'paused' && !orders.data ? <NoOfflineData /> : <Spinner />
        ) : (
          <>
            {orders.isRefetchError && (
              <Alert tone="warning">
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                  <p>{t('orders.regional.list.refreshFailed', { reason: errorMessage(orders.error) })}</p>
                  <Button variant="secondary" onClick={() => void orders.refetch()}>
                    {t('common.retry')}
                  </Button>
                </div>
              </Alert>
            )}

            {items.length === 0 ? (
              <p className="py-6 text-center text-sm text-slate-600">{t(`orders.regional.empty.${tab.key}`)}</p>
            ) : wide ? (
              <OrdersTable orders={items} day={day} label={label} showRegion={showRegion} listSearch={location.search} />
            ) : (
              <OrderCards orders={items} day={day} label={label} showRegion={showRegion} listSearch={location.search} />
            )}

            {orders.hasNextPage && (
              <div className="flex flex-col gap-2">
                <p className="text-sm text-slate-600" aria-live="polite">
                  {t('orders.regional.list.shown', { shown: items.length, total })}
                </p>
                <Button variant="secondary" onClick={() => void orders.fetchNextPage()} disabled={orders.isFetchingNextPage}>
                  {orders.isFetchingNextPage ? t('common.loading') : t('orders.regional.list.more')}
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
