import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { api } from '../../../shared/api/client'
import type { RegionalOrder, RegionalOrdersPage } from '../../../shared/api/types'
import { REGION_DAY_DECISION_EVENTS } from '../../../shared/realtime'
import { type Intervals, type OrderTab, type OrderTabKey, refreshInterval } from './model'

/**
 * Commandes reçues par le régional, en lecture (EF-REG-04) — contrat :
 * docs/lot-5-contrat-api.md §4 et §5. Le serveur applique le périmètre à la
 * date de la journée (titulaire ou suppléant) ; l'écran ne filtre rien.
 */

/** Toutes les listes d'une journée (onglets, régions) : relues ensemble sur un événement de commande. */
export const regionalOrdersKey = (day: string) => ['regional', 'orders', day] as const

export const regionalOrdersListKey = (day: string, region: number | null, tab: OrderTabKey) =>
  [...regionalOrdersKey(day), region ?? 'all', tab] as const

export const regionalOrderKey = (id: string) => ['regional', 'order', id] as const

/**
 * Événements du canal `region-day.{regionId}.{day}` qui changent les commandes reçues : ceux du client
 * (contrat du lot 5, §5), puis les décisions et le traitement ADV (contrat du lot 6, §7).
 */
export const ORDER_EVENTS = ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired', ...REGION_DAY_DECISION_EVENTS] as const

/**
 * Événements qui changent le détail d'une commande : la commande elle-même,
 * ou le contexte publié de ses lignes (borne, quota, validé de la région).
 */
export const ORDER_DETAIL_EVENTS = [...ORDER_EVENTS, 'DistributionPublished', 'QuotaPublished'] as const

/**
 * Commandes d'un onglet, par heure d'envoi (RG-V01), 50 par page ; pages
 * suivantes à la demande (« Afficher plus »). `region` null : toutes les
 * régions du périmètre. Pendant le chargement d'un autre onglet de la même
 * journée et des mêmes régions, la liste précédente sert de donnée
 * provisoire : ses compteurs (`meta.counts`, indépendants de l'onglet)
 * restent affichés, pas ses commandes.
 */
export function useRegionalOrders(day: string, region: number | null, tab: OrderTab, options: { enabled: boolean; refetchInterval: number | false }) {
  const scope = region ?? 'all'
  return useInfiniteQuery({
    queryKey: regionalOrdersListKey(day, region, tab.key),
    queryFn: ({ pageParam, signal }) =>
      api.get<RegionalOrdersPage>(
        '/regional/orders',
        { day, 'filter[status]': tab.statuses.join(','), 'filter[region]': region ?? undefined, page: pageParam },
        signal,
      ),
    initialPageParam: 1,
    getNextPageParam: ({ meta }) => (meta.current_page < meta.last_page ? meta.current_page + 1 : undefined),
    placeholderData: (previous, previousQuery) => {
      const key = previousQuery?.queryKey
      return key !== undefined && key[2] === day && key[3] === scope ? previous : undefined
    },
    enabled: options.enabled,
    refetchInterval: options.refetchInterval,
    // Événements manqués pendant l'absence de l'écran (détail ouvert, autre page) : relue à chaque retour.
    refetchOnMount: 'always',
  })
}

/**
 * Détail d'une commande reçue (404 inconnue ou hors du périmètre à sa date) :
 * relu à chaque ouverture, puis en secours seulement une fois chargé.
 */
export function useRegionalOrder(id: string, refetchInterval: number | false) {
  return useQuery({
    queryKey: regionalOrderKey(id),
    queryFn: ({ signal }) => api.get<RegionalOrder>(`/regional/orders/${encodeURIComponent(id)}`, undefined, signal),
    refetchInterval: (query) => (query.state.data === undefined ? false : refetchInterval),
    refetchOnMount: 'always',
  })
}

/**
 * Intervalles de secours signalés par les abonnements de chaque région
 * (`RegionOrdersLive`) : `interval` est le plus court, à passer en
 * `refetchInterval` ; `report(regionId, null)` au départ d'un abonnement.
 */
export function useLiveIntervals() {
  const [intervals, setIntervals] = useState<Intervals>(() => new Map())
  const report = useCallback((regionId: number, interval: number | false | null) => {
    setIntervals((previous) => {
      if (interval === null ? !previous.has(regionId) : previous.get(regionId) === interval) return previous
      const next = new Map(previous)
      if (interval === null) next.delete(regionId)
      else next.set(regionId, interval)
      return next
    })
  }, [])
  return { interval: refreshInterval(intervals), report }
}
