import { type InfiniteData, type QueryClient, useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, ApiError } from '../../../../shared/api/client'
import type { RegionalOrder, RegionalOrdersPage, RejectOrderBody, ValidateOrderBody } from '../../../../shared/api/types'
import { gridKey, invalidateRegions } from '../../../distribution/api'
import { DASHBOARD_KEY } from '../../home/api'
import { regionalOrderKey, regionalOrdersKey } from '../api'
import { ordersOf } from '../model'
import { discardDraft } from './drafts'
import { oldestSubmitted } from './model'

/**
 * Écritures de la décision du régional (EF-REG-05) — contrat :
 * docs/lot-6-contrat-api.md §3. `networkMode: 'always'` : hors ligne, la
 * requête échoue aussitôt au lieu d'attendre le retour du réseau (pas de
 * décision différée : le serveur reste seul juge, version et quotas).
 */

/** Décision enregistrée : ce que la page montre ensuite (« Commande suivante »). */
export type Decided = {
  kind: 'validated' | 'rejected'
  order: RegionalOrder
  /** Lignes réduites (validation avec ajustement). */
  adjusted: number
}

/** Ce qu'il reste à dire après une tentative de décision : enregistrée, ou commande déjà traitée par un autre compte. */
export type Outcome = Decided | { kind: 'processed' }

/** Refus de la dernière tentative ; `sent` : lignes envoyées, pour rapporter les erreurs `lines.N.*` aux produits. */
/**
 * Dernier refus. `at` : la commande affichée au moment du refus ; dès qu'une
 * relecture en livre une autre, c'est le serveur (`blocked_reason`) qui décide.
 */
export type Failure =
  | { kind: 'validate'; error: unknown; sent: ValidateOrderBody['lines']; at: RegionalOrder }
  | { kind: 'reject'; error: unknown; at: RegionalOrder }

const decisionPath = (id: string, action: 'validate' | 'reject') => `/regional/orders/${encodeURIComponent(id)}/${action}`

type Target = Pick<RegionalOrder, 'id' | 'day'> & { region: Pick<RegionalOrder['region'], 'id'> }

/** Commande des listes en cache passée à son nouveau statut : la « Commande suivante » ne la reproposera pas. */
function markDecided(queryClient: QueryClient, day: string, order: RegionalOrder): void {
  queryClient.setQueriesData<InfiniteData<RegionalOrdersPage>>({ queryKey: regionalOrdersKey(day) }, (data) =>
    data && {
      ...data,
      pages: data.pages.map((page) => ({
        ...page,
        data: page.data.map((entry) =>
          entry.id === order.id
            ? {
                ...entry,
                status: order.status,
                status_label: order.status_label,
                version: order.version,
                is_adjusted: order.is_adjusted,
                decided_at: order.decided_at,
                decided_by: order.decided_by,
              }
            : entry,
        ),
      })),
    },
  )
}

/**
 * Après une décision : la commande renvoyée remplace celle du cache ; les
 * listes, la grille de répartition (V et S changent), « Quotas du jour » et
 * « Ma journée » sont relues ; le brouillon disparaît.
 */
function applyDecision(queryClient: QueryClient, target: Target, updated: RegionalOrder): void {
  queryClient.setQueryData(regionalOrderKey(target.id), updated)
  markDecided(queryClient, target.day, updated)
  discardDraft(queryClient, target.id)
  void queryClient.invalidateQueries({ queryKey: regionalOrdersKey(target.day) })
  void queryClient.invalidateQueries({ queryKey: gridKey(target.day, target.region.id) })
  void queryClient.invalidateQueries({ queryKey: DASHBOARD_KEY })
  invalidateRegions(queryClient, target.day)
}

/**
 * Refus qui montrent que la commande lue n'est plus la bonne (version,
 * statut, journée, heure limite, client, maximums) : elle est relue, et le
 * brouillon est gardé (sauf commande déjà traitée).
 */
const STALE_CODES = ['VERSION_CONFLICT', 'INVALID_TRANSITION', 'DAY_NOT_OPEN', 'CUTOFF_PASSED', 'CLIENT_BLOCKED', 'REGION_QUOTA_EXCEEDED', 'CLIENT_LIMIT_EXCEEDED']

function settleError(queryClient: QueryClient, target: Target, error: unknown, onOutcome: (outcome: Outcome) => void): void {
  if (!(error instanceof ApiError) || !STALE_CODES.includes(error.code)) return
  void queryClient.invalidateQueries({ queryKey: regionalOrderKey(target.id) })
  if (error.code === 'INVALID_TRANSITION' || error.code === 'DAY_NOT_OPEN') {
    // Traitée par un autre compte, ou journée close : les listes et la grille ont changé.
    if (error.code === 'INVALID_TRANSITION') {
      discardDraft(queryClient, target.id)
      onOutcome({ kind: 'processed' })
    }
    void queryClient.invalidateQueries({ queryKey: regionalOrdersKey(target.day) })
    void queryClient.invalidateQueries({ queryKey: gridKey(target.day, target.region.id) })
    void queryClient.invalidateQueries({ queryKey: DASHBOARD_KEY })
    invalidateRegions(queryClient, target.day)
  } else if (error.code === 'REGION_QUOTA_EXCEEDED' || error.code === 'CLIENT_LIMIT_EXCEEDED') {
    // V a bougué sous les pieds du régional : la grille aussi.
    void queryClient.invalidateQueries({ queryKey: gridKey(target.day, target.region.id) })
  }
}

/**
 * Validation et rejet d'une commande. `onOutcome` est appelé dès la réponse du
 * serveur, même si l'écran a déjà basculé sur la commande décidée.
 */
export function useDecision(order: RegionalOrder, onOutcome: (outcome: Outcome) => void) {
  const queryClient = useQueryClient()
  const [failure, setFailure] = useState<Failure | null>(null)

  const validate = useMutation({
    networkMode: 'always',
    mutationFn: (body: ValidateOrderBody) => api.post<RegionalOrder>(decisionPath(order.id, 'validate'), body),
    onSuccess: (updated) => {
      applyDecision(queryClient, order, updated)
      onOutcome({
        kind: 'validated',
        order: updated,
        adjusted: updated.lines.filter((line) => line.validated_qty !== null && line.validated_qty < line.requested_qty).length,
      })
    },
    onError: (error, body) => {
      setFailure({ kind: 'validate', error, sent: body.lines, at: order })
      settleError(queryClient, order, error, onOutcome)
    },
  })

  const reject = useMutation({
    networkMode: 'always',
    mutationFn: (body: RejectOrderBody) => api.post<RegionalOrder>(decisionPath(order.id, 'reject'), body),
    onSuccess: (updated) => {
      applyDecision(queryClient, order, updated)
      onOutcome({ kind: 'rejected', order: updated, adjusted: 0 })
    },
    onError: (error) => {
      setFailure({ kind: 'reject', error, at: order })
      settleError(queryClient, order, error, onOutcome)
    },
  })

  return {
    failure,
    pending: validate.isPending || reject.isPending,
    validating: validate.isPending,
    clear: () => setFailure(null),
    validate: (body: ValidateOrderBody) => {
      setFailure(null)
      validate.mutate(body)
    },
    reject: (body: RejectOrderBody) => {
      setFailure(null)
      reject.mutate(body)
    },
  }
}

/**
 * « Commande suivante » lue sur le serveur : la plus ancienne commande encore
 * envoyée de la journée (et de la région de la liste d'où l'on vient), hors la
 * commande en cours. Première page triée par heure d'envoi : la plus ancienne
 * y figure. Couvre l'ouverture depuis une notification (aucune liste en cache)
 * et les commandes décidées entre-temps par un autre régional.
 */
export async function fetchNextSubmitted(day: string, region: number | null, currentId: string, signal?: AbortSignal) {
  const page = await api.get<RegionalOrdersPage>('/regional/orders', { day, 'filter[status]': 'SUBMITTED', 'filter[region]': region ?? undefined }, signal)
  return oldestSubmitted(page.data, currentId)
}

/**
 * « Commande suivante » : la plus ancienne commande à valider (RG-V01) des
 * listes « À valider » en cache pour cette journée, de préférence celle de la
 * liste d'où l'on vient (`region`, null : toutes les régions) ; null si
 * aucune liste n'est en cache ou si elle est épuisée.
 */
export function nextSubmitted(queryClient: QueryClient, day: string, region: number | null, currentId: string) {
  const lists = queryClient
    .getQueriesData<InfiniteData<RegionalOrdersPage>>({ queryKey: regionalOrdersKey(day) })
    .filter(([key]) => key[4] === 'SUBMITTED')
  const scope = region ?? 'all'
  const sameList = lists.filter(([key]) => key[3] === scope)
  const chosen = sameList.length > 0 ? sameList : lists
  return oldestSubmitted(
    chosen.flatMap(([, data]) => (data ? ordersOf(data.pages) : [])),
    currentId,
  )
}
