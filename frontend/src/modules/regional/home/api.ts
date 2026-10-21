import { useQuery } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { RegionalDashboard } from '../../../shared/api/types'
import { ORDER_EVENTS } from '../orders/api'

/**
 * « Ma journée » du régional (EF-REG-01) — contrat : docs/lot-6-contrat-api.md §3.
 * `GET /regional/dashboard?day=` : réponse sans enveloppe `data` (I-96) ; le
 * serveur applique le périmètre à la date demandée (titulaire ou suppléant).
 */

/** Racine des tableaux de bord en cache : relue ensemble sur une prolongation d'heure limite ou une décision. */
export const DASHBOARD_KEY = ['regional', 'dashboard'] as const

export const regionalDashboardKey = (day: string) => [...DASHBOARD_KEY, day] as const

/**
 * Événements du canal `region-day.{regionId}.{day}` qui changent « Ma journée » : les
 * commandes (envoi, modification, annulation, décision, traitement ADV), la
 * publication des quotas et l'envoi de la répartition aux clients (clients notifiés),
 * le premier brouillon de répartition (statut de la région). La prolongation d'une
 * heure limite arrive par la notification CUTOFF_EXTENDED du canal `user.{id}`.
 */
export const DASHBOARD_EVENTS = [...ORDER_EVENTS, 'DistributionPublished', 'QuotaPublished', 'LimitDraftsUpdated'] as const

export function useRegionalDashboard(day: string, refetchInterval: number | false) {
  return useQuery({
    queryKey: regionalDashboardKey(day),
    queryFn: ({ signal }) => api.get<RegionalDashboard>('/regional/dashboard', { day }, signal),
    refetchInterval,
    // Événements manqués pendant l'absence de l'écran (liste, détail d'une commande) : relu à chaque retour.
    refetchOnMount: 'always',
  })
}
