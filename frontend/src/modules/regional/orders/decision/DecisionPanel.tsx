import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../../../shared/api/client'
import type { RegionalOrder, RegionalOrderLine } from '../../../../shared/api/types'
import { useOnline } from '../../../../shared/pwa/online'
import { useReasonCodes } from '../../../../shared/reasons'
import { Alert, Button, Card } from '../../../../shared/ui'
import { LineItem } from '../lines'
import { ActionBar } from './ActionBar'
import { type Outcome, useDecision } from './api'
import { BlockerAlert, FailureAlert, StaleBanner } from './banners'
import { DecisionLine } from './DecisionLine'
import { useDecisionDraft } from './drafts'
import {
  adjustedCount,
  blockerOf,
  buildValidateBody,
  isAboveMax,
  isAllZero,
  lineIssues,
  lineProblems,
  maxOf,
  quantityOf,
  setComment,
  setQuantity,
  setReason,
  withMaximum,
} from './model'
import { RejectDialog } from './RejectDialog'

/** Refus du rejet qui font passer à un bandeau de la page : la boîte se referme. */
const CLOSING_CODES = ['VERSION_CONFLICT', 'INVALID_TRANSITION', 'DAY_NOT_OPEN']

/**
 * Décision du régional sur une commande envoyée (EF-REG-05, RG-V01 à RG-V08,
 * contrat du lot 6 §8) : lignes à valider avec leur contexte, ajustement
 * motivé, « Ajuster au maximum possible », puis « Valider » ou « Rejeter » dans
 * une barre fixe. Le brouillon est local, par commande et par version ; une
 * relecture en direct ne l'écrase jamais (maximums seuls mis à jour, lignes
 * au-dessus signalées) ; si le client modifie la commande, un bandeau le dit et
 * le brouillon repart de la commande à jour après confirmation. Hors ligne,
 * les actions sont désactivées.
 */
export function DecisionPanel({ order, onOutcome }: { order: RegionalOrder; onOutcome: (outcome: Outcome) => void }) {
  const { t } = useTranslation()
  const online = useOnline()
  const reasons = useReasonCodes('ADJUSTMENT')
  const { draft, setDraft, stale, restart } = useDecisionDraft(order)
  const decision = useDecision(order, onOutcome)
  const [rejecting, setRejecting] = useState(false)
  const [attempts, setAttempts] = useState(0)
  const lines = useRef<HTMLUListElement>(null)

  const { failure } = decision
  // Le refus ne bloque que jusqu'à la relecture de la commande (prolongation, client réactivé).
  const blocker = blockerOf(order, failure?.at === order ? failure.error : undefined)
  const canValidate = order.can_validate && blocker === null && !stale
  const canReject = order.can_reject && blocker !== 'DAY_NOT_OPEN' && !stale
  const editable = canValidate && !decision.pending

  const problems = useMemo(() => lineProblems(order, draft, reasons.data), [order, draft, reasons.data])
  const issues = useMemo(() => (failure?.kind === 'validate' ? lineIssues(failure.error, failure.sent).lines : new Map()), [failure])
  const adjusted = adjustedCount(order, draft)
  const allZero = isAllZero(order, draft)
  const belowMaximum = order.lines.some((line) => quantityOf(draft, line) !== maxOf(line))
  // Ligne au-dessus du maximum validable : signalée sous la barre, sans bloquer (le serveur reste seul juge).
  const aboveMax = order.lines.some((line) => isAboveMax(line, quantityOf(draft, line)))

  const onQuantity = useCallback((line: RegionalOrderLine, quantity: number) => setDraft((current) => setQuantity(current, line, quantity)), [setDraft])
  const onReason = useCallback((line: RegionalOrderLine, reason: string) => setDraft((current) => setReason(current, line, reason)), [setDraft])
  const onComment = useCallback((line: RegionalOrderLine, comment: string) => setDraft((current) => setComment(current, line, comment)), [setDraft])

  // Tentative de validation incomplète : le premier champ à compléter reçoit le focus.
  useEffect(() => {
    if (attempts > 0) lines.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [attempts])

  const validate = () => {
    if (problems.size > 0) {
      setAttempts((count) => count + 1)
      return
    }
    decision.validate(buildValidateBody(order, draft))
  }

  const rejectFailure = failure?.kind === 'reject' ? failure : null
  // Refus qui passe à un bandeau de la page : la boîte se ferme pour de bon (elle ne se rouvre pas à la validation suivante).
  if (rejecting && rejectFailure?.error instanceof ApiError && CLOSING_CODES.includes(rejectFailure.error.code)) setRejecting(false)
  const openReject = () => {
    decision.clear()
    setRejecting(true)
  }

  const cutoff = order.validation

  return (
    <>
      {stale && <StaleBanner order={order} onRestart={restart} />}
      {blocker !== null && <BlockerAlert blocker={blocker} order={order} canReject={canReject} />}
      <FailureAlert failure={failure} stale={stale} />

      <Card
        title={t('orders.regional.detail.lines.title')}
        actions={
          editable && belowMaximum ? (
            <Button variant="secondary" onClick={() => setDraft((current) => withMaximum(order, current))}>
              {t('orders.decision.maximum')}
            </Button>
          ) : undefined
        }
      >
        {canValidate && (
          <p className="mb-3 text-sm text-slate-700">
            {cutoff.passed ? t('orders.decision.cutoff.passed', { time: cutoff.cutoff }) : t('orders.decision.cutoff.before', { time: cutoff.cutoff })}
            {cutoff.extended && <span className="font-semibold text-warning"> ({t('orders.extended')})</span>}
          </p>
        )}
        {canValidate && allZero && (
          <div className="mb-3">
            <Alert tone="warning">
              <p className="font-semibold">{t('orders.decision.allZero')}</p>
              {canReject && (
                <Button variant="secondary" className="mt-3" disabled={!online || decision.pending} onClick={openReject}>
                  {t('orders.decision.allZeroAction')}
                </Button>
              )}
            </Alert>
          </div>
        )}
        <ul ref={lines} aria-label={t('orders.regional.detail.lines.title')} className="-mb-4 flex flex-col divide-y divide-slate-200 sm:-mb-5">
          {order.lines.map((line) =>
            canValidate ? (
              <DecisionLine
                key={line.product_id}
                line={line}
                quantity={quantityOf(draft, line)}
                reason={draft.lines[line.product_id]?.reason ?? ''}
                comment={draft.lines[line.product_id]?.comment ?? ''}
                reasonMissing={attempts > 0 && (problems.get(line.product_id)?.reason ?? false)}
                commentMissing={attempts > 0 && (problems.get(line.product_id)?.comment ?? false)}
                issue={issues.get(line.product_id)}
                disabled={!editable}
                onQuantity={onQuantity}
                onReason={onReason}
                onComment={onComment}
              />
            ) : (
              <LineItem key={line.product_id} line={line} />
            ),
          )}
        </ul>
      </Card>

      {(canValidate || canReject) && (
        <ActionBar label={t('orders.decision.actions.label')}>
          {!online && (
            <p role="status" className="basis-full text-sm font-semibold text-warning sm:basis-auto sm:me-auto">
              {t('orders.decision.offline')}
            </p>
          )}
          {canReject && (
            <Button variant="danger" className="min-h-12 shrink-0" disabled={!online || decision.pending} onClick={openReject}>
              {t('orders.decision.actions.reject')}
            </Button>
          )}
          {canValidate && aboveMax && !allZero && (
            <p className="basis-full text-xs text-slate-600 sm:order-first sm:basis-auto sm:me-auto">{t('orders.decision.aboveHint')}</p>
          )}
          {canValidate && (
            <Button className="min-h-12 flex-1 text-base sm:flex-none" disabled={!online || decision.pending || allZero} onClick={validate}>
              {decision.validating
                ? t('orders.decision.actions.validating')
                : adjusted > 0
                  ? t('orders.decision.actions.validateAdjusted', { count: adjusted })
                  : t('orders.decision.actions.validate')}
            </Button>
          )}
        </ActionBar>
      )}

      {rejecting && (
        <RejectDialog
          order={{ number: order.number, version: draft.version }}
          pending={decision.pending}
          error={rejectFailure?.error ?? null}
          onSubmit={decision.reject}
          onClose={() => setRejecting(false)}
        />
      )}
    </>
  )
}
