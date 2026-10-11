import { useQuery } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { RegionalQuotas } from '../../../shared/api/types'

/**
 * « Quotas du jour » du régional (§7.6) — contrat : docs/lot-3-contrat-api.md §4.
 * Le serveur applique le périmètre à la date demandée (titulaire ou
 * suppléant, §3.4) et n'expose que les quotas publiés (RG-Q03).
 */

export const regionalQuotasKey = (day: string) => ['regional', 'quotas', day] as const

export function useRegionalQuotas(day: string, refetchInterval: number | false) {
  return useQuery({
    queryKey: regionalQuotasKey(day),
    queryFn: ({ signal }) => api.get<RegionalQuotas>('/regional/quotas', { day }, signal),
    refetchInterval,
  })
}
