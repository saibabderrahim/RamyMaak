import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { AppNotification, DayMonitoring, ExportFormat, PickingListFormat, PickingListGroup } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { ADV_DAY_ORDER_EVENTS, NOTIFICATION_EVENT, ORDER_REJECTED, useChannel } from '../../../shared/realtime'
import { fetchFile, saveFile } from '../shared/download'
import { monitoringKey, monitoringRoot } from './keys'

/** Suivi de la journée, bon de préparation et export (EF-ADV-03, RG-A04) — contrat : docs/lot-6-contrat-api.md §6. */

export { monitoringKey, monitoringRoot } from './keys'

/** Événements de `adv.{jour}` qui changent le suivi : quotas, répartitions, clôture, commandes (identifiants seulement, I-81). */
export const MONITORING_DAY_EVENTS = ['QuotaPublished', 'DistributionPublished', 'DayClosed', ...ADV_DAY_ORDER_EVENTS] as const

/**
 * Événements de `region-day.{r}.{jour}` qui n'arrivent pas sur `adv.{jour}` :
 * envois, modifications, annulations par le client, expirations et rejets. Les
 * validations, traitements et annulations ADV sont déjà reçus une fois sur
 * `adv.{jour}` : les écouter aussi par région relirait le suivi une fois par
 * région (I-97).
 */
export const MONITORING_REGION_EVENTS = ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired', ORDER_REJECTED] as const

/** Suivi : réponse sans enveloppe `data` (I-96). 404 tant que la journée n'est pas ouverte. */
export function useDayMonitoring(day: string, { enabled, refetchInterval }: { enabled: boolean; refetchInterval: number | false }) {
  return useQuery({
    queryKey: monitoringKey(day),
    queryFn: ({ signal }) => api.get<DayMonitoring>(`/adv/days/${day}/monitoring`, undefined, signal),
    enabled,
    refetchInterval,
  })
}

/**
 * N-13 « Répartition en retard » (lot 8, contrat §3) : notification reçue sur
 * `user.{id}` par l'ADV du périmètre et l'Admin. Aucun événement de
 * `adv.{jour}` ne l'accompagne (le retard vient de l'heure qui passe) : le
 * suivi est relu pour montrer l'alerte calculée par le serveur.
 */
export function useDistributionLateRefresh(): void {
  const user = useAuthenticatedUser()
  const queryClient = useQueryClient()
  useChannel(`user.${user.id}`, {
    [NOTIFICATION_EVENT]: (payload) => {
      if ((payload as Partial<AppNotification> | null)?.type === 'DISTRIBUTION_LATE') void queryClient.invalidateQueries({ queryKey: monitoringRoot })
    },
  })
}

export type PickingListRequest = { group: PickingListGroup; format: PickingListFormat }

/**
 * Bon de préparation consolidé de la journée (PDF ou Excel) : requête authentifiée, puis
 * enregistrement du fichier. Un refus (4xx) arrive en JSON et s'affiche comme un message.
 */
export function usePickingList(day: string) {
  return useMutation({
    mutationFn: async ({ group, format }: PickingListRequest) => {
      const file = await fetchFile(`/adv/days/${day}/picking-list`, { query: { format, group } })
      saveFile(file.blob, file.filename ?? `bon-de-preparation-${day}.${format}`)
    },
  })
}

/** Export des commandes de la journée (Excel, CSV pour Excel français, ERP provisoire). */
export function useOrdersExport(day: string) {
  return useMutation({
    mutationFn: async (format: ExportFormat) => {
      const file = await fetchFile(`/adv/days/${day}/orders/export`, { query: { format } })
      saveFile(file.blob, file.filename ?? `commandes-${day}.${format === 'erp' ? 'csv' : format}`)
    },
  })
}
