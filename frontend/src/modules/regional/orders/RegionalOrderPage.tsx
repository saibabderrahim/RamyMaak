import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import { Alert, Button, ErrorAlert, Spinner } from '../../../shared/ui'
import { useErrorMessage } from '../../../shared/ui/useErrorMessage'
import { ORDER_DETAIL_EVENTS, regionalOrderKey, useLiveIntervals, useRegionalOrder } from './api'
import { RegionOrdersLive } from './components'
import type { Outcome } from './decision/api'
import { OutcomeBar } from './decision/OutcomeBar'
import { OrderDetail } from './OrderDetail'

const LINK_CLASSES =
  'inline-flex min-h-11 items-center gap-2 self-start rounded-lg font-medium text-brand-700 hover:underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

/**
 * Détail d'une commande reçue et décision (EF-REG-04, EF-REG-05) :
 * `/regional/commandes/:id`, lien des notifications N-05 et N-06. Tenu à jour
 * sur le canal de la région de la commande, ce jour-là (§9.6). Commande
 * inconnue ou hors du périmètre à sa date : 404. Le lien de retour reprend la
 * liste d’où l’on vient (journée, onglet, région), sinon la journée de la
 * commande ; après une décision, « Commande suivante » reprend cette liste.
 * Contrat : docs/lot-5-contrat-api.md §4 à §6, docs/lot-6-contrat-api.md §3 et §8.
 */
export default function RegionalOrderPage() {
  // Une commande par écran : le compte rendu d'une décision ne passe pas à la commande suivante.
  const id = useParams().id ?? ''
  return <OrderScreen key={id} id={id} />
}

function OrderScreen({ id }: { id: string }) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const location = useLocation()
  const { interval, report } = useLiveIntervals()
  const query = useRegionalOrder(id, interval)
  const order = query.data
  const [outcome, setOutcome] = useState<Outcome | null>(null)

  // Une commande par écran (« Commande suivante » en ouvre une autre) : toujours lue depuis le haut.
  useEffect(() => window.scrollTo({ top: 0 }), [])

  const state: unknown = location.state
  const listSearch = state !== null && typeof state === 'object' && 'list' in state && typeof state.list === 'string' ? state.list : null
  const backTo = `/regional/commandes${listSearch ?? (order ? `?${new URLSearchParams({ day: order.day })}` : '')}`
  const back = (
    <Link to={backTo} className={LINK_CLASSES}>
      <span aria-hidden="true">←</span>
      {t('orders.regional.detail.back')}
    </Link>
  )

  if (!order) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return (
        <div className="flex flex-col gap-3">
          {back}
          <Alert tone="warning">{t('orders.regional.detail.notFound')}</Alert>
        </div>
      )
    }
    if (query.error) {
      return (
        <div className="flex flex-col gap-3">
          {back}
          <ErrorAlert error={query.error} />
          <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      )
    }
    return <Spinner />
  }

  return (
    <div className="flex flex-col gap-4">
      {back}
      <RegionOrdersLive
        key={`${order.day}-${order.region.id}`}
        regionId={order.region.id}
        day={order.day}
        events={ORDER_DETAIL_EVENTS}
        queryKeys={[regionalOrderKey(id)]}
        onInterval={report}
      />
      {query.isRefetchError && (
        <Alert tone="warning">
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <p>{t('orders.regional.detail.refreshFailed', { reason: errorMessage(query.error) })}</p>
            <Button variant="secondary" onClick={() => void query.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        </Alert>
      )}
      <OrderDetail order={order} onOutcome={setOutcome} />
      {outcome && <OutcomeBar outcome={outcome} order={order} listSearch={listSearch} backTo={backTo} />}
    </div>
  )
}
