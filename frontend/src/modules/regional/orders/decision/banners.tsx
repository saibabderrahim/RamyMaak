import { useTranslation } from 'react-i18next'
import { ApiError } from '../../../../shared/api/client'
import type { DecisionBlocker, RegionalOrder } from '../../../../shared/api/types'
import { Alert, Button } from '../../../../shared/ui'
import type { Failure } from './api'
import { decisionErrorText, ELSEWHERE_CODES } from './messages'
import { lineIssues } from './model'

/** Erreur de la dernière validation à montrer en tête du panneau ; null : rien (bandeau dédié, ou refus du rejet). */
export function FailureAlert({ failure, stale }: { failure: Failure | null; stale: boolean }) {
  const { t } = useTranslation()
  if (failure?.kind !== 'validate') return null
  const { error } = failure
  if (error instanceof ApiError && ELSEWHERE_CODES.includes(error.code)) return null
  // Commande modifiée par le client : le bandeau « modifiée par le client » prend le relais.
  if (error instanceof ApiError && error.code === 'VERSION_CONFLICT' && stale) return null
  const { general } = lineIssues(error, failure.sent)
  return (
    <Alert tone="error">
      <p className="font-semibold">{decisionErrorText(t, error)}</p>
      {general.length > 0 && (
        <ul className="mt-1 list-disc ps-5">
          {general.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
    </Alert>
  )
}

/**
 * Commande modifiée par le client depuis l'ouverture du brouillon (version
 * différente) : le brouillon ne s'applique plus, il repart de la commande à
 * jour après confirmation (contrat du lot 6, §8).
 */
export function StaleBanner({ order, onRestart }: { order: Pick<RegionalOrder, 'version'>; onRestart: () => void }) {
  const { t } = useTranslation()
  return (
    <Alert tone="warning">
      <p className="font-semibold">{t('orders.decision.stale.title')}</p>
      <p>{t('orders.decision.stale.body', { version: order.version })}</p>
      <Button variant="secondary" className="mt-3" onClick={onRestart}>
        {t('orders.decision.stale.restart')}
      </Button>
    </Alert>
  )
}

/**
 * Pourquoi la validation est impossible : client bloqué, heure limite de
 * validation passée (bloquante), journée ou région clôturée. Le rejet reste
 * possible, sauf journée clôturée.
 */
export function BlockerAlert({ blocker, order, canReject }: { blocker: DecisionBlocker; order: Pick<RegionalOrder, 'validation'>; canReject: boolean }) {
  const { t } = useTranslation()
  return (
    <Alert tone="warning">
      <p className="font-semibold">{t(`orders.decision.blocker.${blocker}`, { time: order.validation.cutoff })}</p>
      {canReject && blocker !== 'DAY_NOT_OPEN' && <p>{t('orders.decision.blocker.onlyReject')}</p>}
    </Alert>
  )
}
