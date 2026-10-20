import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { DayMonitoring, ExportFormat, PickingListFormat, PickingListGroup } from '../../../shared/api/types'
import { ADV_DAY_ORDER_EVENTS, REGION_DAY_DECISION_EVENTS } from '../../../shared/realtime'
import { fetchFile, saveFile } from '../shared/download'
import { monitoringKey } from './keys'

/** Suivi de la journée, bon de préparation et export (EF-ADV-03, RG-A04) — contrat : docs/lot-6-contrat-api.md §6. */

export { monitoringKey, monitoringRoot } from './keys'

/** Événements de `adv.{jour}` qui changent le suivi : quotas, répartitions, clôture, commandes (identifiants seulement, I-81). */
export const MONITORING_DAY_EVENTS = ['QuotaPublished', 'DistributionPublished', 'DayClosed', ...ADV_DAY_ORDER_EVENTS] as const

/** Événements de `region-day.{r}.{jour}` : envois, modifications et décisions de la région (commandes reçues, validées, traitées). */
export const MONITORING_REGION_EVENTS = ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired', ...REGION_DAY_DECISION_EVENTS] as const

/** Suivi : réponse sans enveloppe `data` (I-96). 404 tant que la journée n'est pas ouverte. */
export function useDayMonitoring(day: string, { enabled, refetchInterval }: { enabled: boolean; refetchInterval: number | false }) {
  return useQuery({
    queryKey: monitoringKey(day),
    queryFn: ({ signal }) => api.get<DayMonitoring>(`/adv/days/${day}/monitoring`, undefined, signal),
    enabled,
    refetchInterval,
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
