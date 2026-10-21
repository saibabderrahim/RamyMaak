import { useQueryClient } from '@tanstack/react-query'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { AdvOrder, ProcessingStatus } from '../../../shared/api/types'
import { useCoalescedRefresh } from '../../../shared/orders'
import { useOnline } from '../../../shared/pwa/online'
import { FALLBACK_REFRESH_MS, useRealtimeStatus } from '../../../shared/realtime'
import { Alert, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { isOrderId, orderKey, QUEUE_EVENTS, useAdvOrder, useBulkStatus, useOrderPdf, useUpdateShipping } from './api'
import { CancelDialog } from './CancelDialog'
import { LinesCard } from './LinesCard'
import { shippingFieldErrors } from './model'
import { ClientCard, HistoryCard, SummaryCard, TotalsCard } from './sections'
import { useShippingDraft } from './useShippingDraft'

/** Abonnement à `adv.{jour}` de la journée de la commande : tout événement de la journée relit celle-ci (aucun rendu). */
function OrderLive({ day, id }: { day: string; id: string }) {
  useCoalescedRefresh(`adv.${day}`, QUEUE_EVENTS, [orderKey(id)])
  return null
}

/**
 * Détail d'une commande de la file ADV (EF-ADV-04 à EF-ADV-06, RG-A03,
 * RG-A04) : `/adv/commandes/:id`, `/admin/commandes/:id` en lecture. Client,
 * lignes avec demandé, validé et motifs, expédition (quantités, motif
 * d'écart, bon de livraison), étape suivante, annulation exceptionnelle,
 * bon de commande PDF, historique. Les actions suivent les droits `can` du
 * serveur (vides pour l'Admin) et sont désactivées hors ligne.
 */
export default function OrderPage() {
  const { id } = useParams()
  return <OrderScreen key={id} id={id} />
}

function OrderScreen({ id }: { id: string | undefined }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const status = useRealtimeStatus()
  const query = useAdvOrder(id, status === 'connected' ? false : FALLBACK_REFRESH_MS)
  const order = query.data

  const back = (
    <Link to={`${base}/commandes`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
      {t('backoffice.orders.detail.back')}
    </Link>
  )

  if (!order) {
    const notFound = !isOrderId(id) || (query.error instanceof ApiError && query.error.status === 404)
    return (
      <>
        <PageHeader title={t('backoffice.orders.title')} actions={back} />
        {notFound ? (
          <Alert tone="warning">
            <p className="font-semibold">{t('backoffice.orders.detail.notFound')}</p>
            <p>{t('backoffice.orders.detail.notFoundHint')}</p>
          </Alert>
        ) : query.isError ? (
          <ErrorAlert error={query.error} />
        ) : (
          <Spinner label={t('backoffice.orders.detail.loading')} />
        )}
      </>
    )
  }

  return (
    <>
      <OrderLive day={order.day} id={order.id} />
      <OrderView order={order} back={back} />
    </>
  )
}

type Notice = { tone: 'success' | 'warning'; text: string }

const NO_FIELD_ERRORS: Record<string, string> = {}

/** Refus par commande du changement de statut en masse, présenté comme une erreur d'API (message du serveur). */
function refusalError(result: { code: string; detail: string }): ApiError {
  return ApiError.fromResponse(409, { code: result.code, detail: result.detail })
}

function OrderView({ order, back }: { order: AdvOrder; back: ReactNode }) {
  const { t } = useTranslation()
  const online = useOnline()
  const queryClient = useQueryClient()
  const draft = useShippingDraft(order)
  const shipping = useUpdateShipping(order.id)
  const bulk = useBulkStatus()
  const pdf = useOrderPdf(order)
  const [notice, setNotice] = useState<Notice | null>(null)
  const [advanceError, setAdvanceError] = useState<unknown>(null)
  // Refus du serveur à l'enregistrement, gardé tant que la saisie refusée n'a pas changé.
  const [failure, setFailure] = useState<{ sent: string; fields: Record<string, string> } | null>(null)
  const [cancelling, setCancelling] = useState(false)

  const working = shipping.isPending || bulk.isPending
  const failed = failure !== null && failure.sent === JSON.stringify(draft.body)

  const refreshOrder = () => void queryClient.invalidateQueries({ queryKey: orderKey(order.id) })

  /** Un refus de version ou d'étape : les valeurs à jour sont relues, les saisies abandonnées. */
  const abandon = (error: unknown) => {
    if (error instanceof ApiError && (error.code === 'VERSION_CONFLICT' || error.code === 'INVALID_TRANSITION')) {
      draft.reset()
      setNotice({ tone: 'warning', text: t('backoffice.orders.shipping.conflict') })
    }
  }

  const save = () => {
    const body = draft.body
    if (!body) return
    const submitted = draft.submittedLines
    setNotice(null)
    setAdvanceError(null)
    shipping.mutate(body, {
      onSuccess: () => {
        draft.reset()
        setFailure(null)
        setNotice({ tone: 'success', text: t('backoffice.orders.shipping.saved') })
      },
      onError: (error) => {
        setFailure({ sent: JSON.stringify(body), fields: shippingFieldErrors(error, submitted) })
        abandon(error)
      },
    })
  }

  /** Étape suivante : les saisies d'expédition sont d'abord enregistrées, puis un changement de statut à une commande (RG-A02). */
  const advance = async (target: ProcessingStatus) => {
    setNotice(null)
    setAdvanceError(null)
    let version = order.version
    try {
      const body = draft.body
      if (body) {
        const submitted = draft.submittedLines
        try {
          version = (await shipping.mutateAsync(body)).version
        } catch (error) {
          setFailure({ sent: JSON.stringify(body), fields: shippingFieldErrors(error, submitted) })
          abandon(error)
          return
        }
        draft.reset()
        setFailure(null)
      }
      const result = await bulk.mutateAsync({ status: target, orders: [{ id: order.id, version }] })
      const refusal = result.refused[0]
      if (refusal) setAdvanceError(refusalError(refusal))
      else setNotice({ tone: 'success', text: t('backoffice.orders.actions.advanced', { status: t(`orders.status.${target}`) }) })
    } catch (error) {
      setAdvanceError(error)
    } finally {
      refreshOrder()
    }
  }

  const hasActions = order.can.next_statuses.length > 0 || order.can.cancel

  return (
    <>
      <PageHeader
        title={t('backoffice.orders.detail.title', { number: order.number })}
        actions={
          <>
            {back}
            {order.can.print && (
              <Button variant="secondary" disabled={pdf.isPending || !online} onClick={() => pdf.mutate()}>
                {t('backoffice.documents.orderPdf')}
              </Button>
            )}
          </>
        }
      />

      <div className="flex flex-col gap-6">
        {!online && <Alert tone="warning">{t('backoffice.orders.offline')}</Alert>}
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <ErrorAlert error={pdf.error} />
        <ErrorAlert error={advanceError} />

        {hasActions && (
          <Card>
            <div role="group" aria-label={t('backoffice.orders.actions.region')} className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center gap-3">
                {order.can.next_statuses.map((target) => (
                  <Button key={target} disabled={working || !online || !draft.valid} onClick={() => void advance(target)}>
                    {working ? t('backoffice.orders.actions.nextWorking') : t(`backoffice.orders.actions.next.${target}`)}
                  </Button>
                ))}
                {order.can.cancel && (
                  <Button variant="danger" disabled={working || !online} onClick={() => setCancelling(true)}>
                    {t('backoffice.orders.actions.cancel')}
                  </Button>
                )}
              </div>
              {draft.dirty && order.can.next_statuses.length > 0 && (
                <p className="text-xs text-slate-600">{t('backoffice.orders.actions.nextSaveFirst')}</p>
              )}
            </div>
          </Card>
        )}

        <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
          <div className="flex min-w-0 flex-col gap-6">
            <SummaryCard order={order} />
            <LinesCard
              order={order}
              draft={draft}
              error={failed ? shipping.error : null}
              fieldErrors={failed ? failure.fields : NO_FIELD_ERRORS}
              saving={shipping.isPending}
              online={online}
              onSave={save}
            />
          </div>
          <div className="flex min-w-0 flex-col gap-6">
            <ClientCard order={order} />
            <TotalsCard order={order} />
            <HistoryCard history={order.history} />
          </div>
        </div>
      </div>

      <CancelDialog
        order={order}
        open={cancelling}
        online={online}
        onClose={() => setCancelling(false)}
        onDone={() => {
          setCancelling(false)
          draft.reset()
          setNotice({ tone: 'success', text: t('backoffice.orders.cancel.done') })
        }}
      />
    </>
  )
}
