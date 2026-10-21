import { type QueryClient, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import type { CurrentUser, RegionalOrder } from '../../../../shared/api/types'
import { meQueryKey } from '../../../../shared/auth/session'
import { type Draft, initialDraft, isStale } from './model'

/**
 * Brouillons de décision, par commande : gardés tant que l'application reste
 * ouverte (sortie de l'écran pour appeler le client, puis retour), une
 * décision enregistrée ou une commande déjà traitée les efface. Seuls ceux où
 * le régional a saisi quelque chose sont gardés. Attachés au client de
 * requêtes et au compte connecté : une déconnexion, ou un autre compte sur le
 * même appareil, repart sans brouillon (la décision serait enregistrée au nom
 * de celui qui valide, pas de celui qui a saisi).
 */
const stores = new WeakMap<QueryClient, { userId: number | null; drafts: Map<string, Draft> }>()

export function draftStore(queryClient: QueryClient): Map<string, Draft> {
  const userId = queryClient.getQueryData<CurrentUser | null>(meQueryKey)?.id ?? null
  let store = stores.get(queryClient)
  if (!store || store.userId !== userId) {
    store = { userId, drafts: new Map() }
    stores.set(queryClient, store)
  }
  return store.drafts
}

/** Commande décidée (ou déjà traitée) : son brouillon n'a plus d'objet. */
export function discardDraft(queryClient: QueryClient, orderId: string): void {
  draftStore(queryClient).delete(orderId)
}

/**
 * Brouillon de la commande affichée. Il part de la commande à l'ouverture
 * (ou reprend celui de la visite précédente, s'il y a saisi quelque chose) et n'est jamais remplacé par une
 * relecture en direct, qui ne met à jour que les maximums. Quand la version
 * de la commande n'est plus la sienne (`stale`), le client l'a modifiée :
 * `restart` repart de la commande à jour, après confirmation du régional.
 */
export function useDecisionDraft(order: RegionalOrder) {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Draft>(() => {
    const stored = draftStore(queryClient).get(order.id)
    return stored !== undefined && !(isStale(stored, order) && !stored.touched) ? stored : initialDraft(order)
  })

  useEffect(() => {
    if (draft.touched) draftStore(queryClient).set(order.id, draft)
    else draftStore(queryClient).delete(order.id)
  }, [queryClient, order.id, draft])

  const restart = useCallback(() => setDraft(initialDraft(order)), [order])

  return { draft, setDraft, stale: isStale(draft, order), restart }
}
