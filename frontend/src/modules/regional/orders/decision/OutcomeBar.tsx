import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { RegionalOrder } from '../../../../shared/api/types'
import { orderPath, regionParam } from '../model'
import { ActionBar } from './ActionBar'
import { nextSubmitted, type Outcome } from './api'

const LINK =
  'inline-flex min-h-11 flex-1 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors sm:flex-none ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
const PRIMARY = 'bg-brand-600 text-white hover:bg-brand-700'
const SECONDARY = 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'

/**
 * Après une décision (contrat du lot 6, §8) : compte rendu, puis « Commande
 * suivante » — la plus ancienne commande à valider (RG-V01) des listes en cache
 * pour la journée — ou retour à la liste d'où l'on vient. La commande suivante
 * est choisie une fois, à l'enregistrement de la décision.
 */
export function OutcomeBar({
  outcome,
  order,
  listSearch,
  backTo,
}: {
  outcome: Outcome
  order: Pick<RegionalOrder, 'id' | 'day' | 'number' | 'region'>
  /** Paramètres de la liste d'où l'on vient (`?day=…&onglet=…&region=…`), repris par la commande suivante. */
  listSearch: string | null
  backTo: string
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const status = useRef<HTMLParagraphElement>(null)
  // Le bouton de la décision a disparu avec le panneau : le focus passe au compte rendu, lu par les lecteurs d'écran.
  useEffect(() => status.current?.focus(), [])
  const [next] = useState(() => {
    const region = listSearch === null ? null : regionParam(new URLSearchParams(listSearch).get('region'))
    return nextSubmitted(queryClient, order.day, region, order.id)
  })

  const text =
    outcome.kind === 'processed'
      ? t('orders.decision.outcome.processed')
      : outcome.kind === 'rejected'
        ? t('orders.decision.outcome.rejected', { number: order.number })
        : outcome.adjusted > 0
          ? t('orders.decision.outcome.validatedAdjusted', { number: order.number, count: outcome.adjusted })
          : t('orders.decision.outcome.validated', { number: order.number })

  return (
    <ActionBar label={t('orders.decision.outcome.label')}>
      <p ref={status} tabIndex={-1} role="status" className="text-sm font-medium text-slate-900 outline-none basis-full sm:basis-auto sm:me-auto">
        {text}
      </p>
      <Link to={backTo} className={`${LINK} ${next ? SECONDARY : PRIMARY}`}>
        {t('orders.decision.outcome.back')}
      </Link>
      {next && (
        <Link
          to={orderPath(next.id)}
          state={listSearch === null ? undefined : { list: listSearch }}
          aria-label={t('orders.decision.outcome.nextLabel', { number: next.number })}
          className={`${LINK} ${PRIMARY}`}
        >
          {t('orders.decision.outcome.next')}
        </Link>
      )}
    </ActionBar>
  )
}
