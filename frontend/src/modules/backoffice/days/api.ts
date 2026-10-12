import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { Day, DayCutoffs, ExtendableCutoff, RegionDay } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { addDays } from '../shared/dates'
import { OPEN_DAYS_AHEAD } from './dates'

/** Journées (EF-ADV-01, §4.3, RG-A05) — contrat : docs/lot-3-contrat-api.md §3. */

export const daysKey = ['backoffice', 'days'] as const
const dayKey = (day: string) => [...daysKey, 'detail', day] as const

/** Matrice des quotas (lot de travail E2) : son en-tête reprend la journée. */
const quotasKey = ['backoffice', 'quotas'] as const

/**
 * Événements du canal `adv.{day}` qui changent l'état des journées (§9.6) :
 * quotas (lot 3), répartition d'une région (lot 4). LimitDraftsUpdated n'y
 * arrive qu'au premier brouillon de bornes (« Répartition en cours »).
 */
export const DAY_EVENTS = ['QuotaPublished', 'DraftsUpdated', 'DayClosed', 'DistributionPublished', 'LimitDraftsUpdated'] as const

export type DaysList = {
  data: Day[]
  meta: {
    from: string
    to: string
    /** Jour métier du serveur (Africa/Algiers). */
    today: string
    /** Jours ouvrés : 0 = dimanche … 6 = samedi. */
    working_days: number[]
  }
}

/** Intervalle demandé ; sans borne, le serveur prend aujourd'hui − 7 à aujourd'hui + 14. */
export type DaysRange = { from?: string; to?: string }

type LiveOptions = { enabled?: boolean; refetchInterval?: number | false }

/** Journées ouvertes (en cours ou clôturées) de l'intervalle, par date. */
export function useDays(range: DaysRange, { enabled = true, refetchInterval = false }: LiveOptions = {}) {
  return useQuery({
    queryKey: [...daysKey, 'list', range],
    queryFn: ({ signal }) => api.get<DaysList>('/adv/days', { from: range.from, to: range.to }, signal),
    placeholderData: keepPreviousData,
    enabled,
    refetchInterval,
  })
}

/**
 * Journées ouvertes et non clôturées d'aujourd'hui à J+30 : celles qui
 * peuvent recevoir un import de quotas (§8.1).
 */
export function useOpenDays(enabled = true) {
  const today = businessToday()
  return useQuery({
    queryKey: [...daysKey, 'list', { from: today, to: addDays(today, OPEN_DAYS_AHEAD) }],
    queryFn: ({ signal }) => api.get<DaysList>('/adv/days', { from: today, to: addDays(today, OPEN_DAYS_AHEAD) }, signal),
    select: (list) => list.data.filter((day) => day.status === 'OPEN'),
    enabled,
  })
}

/** Une journée ; 404 tant qu'elle n'est pas ouverte. */
export function useDay(day: string, { enabled = true, refetchInterval = false }: LiveOptions = {}) {
  return useQuery({
    queryKey: dayKey(day),
    queryFn: ({ signal }) => api.get<Day>(`/adv/days/${day}`, undefined, signal),
    enabled,
    refetchInterval,
  })
}

function useRefreshDays() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: [...daysKey, 'list'] })
    void queryClient.invalidateQueries({ queryKey: quotasKey })
  }
}

/**
 * Ouverture (§4.2, étape 1). Un refus (journée déjà ouverte ailleurs)
 * relit le calendrier pour afficher son état réel.
 */
export function useOpenDay() {
  const queryClient = useQueryClient()
  const refresh = useRefreshDays()
  return useMutation({
    mutationFn: (day: string) => api.post<Day>('/adv/days', { day }),
    onSuccess: (opened) => queryClient.setQueryData(dayKey(opened.day), opened),
    onSettled: refresh,
  })
}

/** Modification partielle : seules les valeurs changées sont envoyées. */
export type DayScheduleUpdate = { cutoffs?: Partial<DayCutoffs>; delivery_date?: string }

export function useUpdateDay(day: string) {
  const queryClient = useQueryClient()
  const refresh = useRefreshDays()
  return useMutation({
    mutationFn: (input: DayScheduleUpdate) => api.patch<Day>(`/adv/days/${day}`, input),
    onSuccess: (updated) => {
      queryClient.setQueryData(dayKey(day), updated)
      refresh()
    },
    // Journée clôturée entre-temps (409) : relue pour afficher son état.
    onError: () => queryClient.invalidateQueries({ queryKey: dayKey(day) }),
  })
}

export type CutoffExtension = { regionId: number; kind: ExtendableCutoff; until: string }

/** Prolongation pour une région (RG-A05) : la journée régionale renvoyée remplace l'ancienne. */
export function useExtendCutoff(day: string) {
  const queryClient = useQueryClient()
  const refresh = useRefreshDays()
  return useMutation({
    mutationFn: ({ regionId, kind, until }: CutoffExtension) =>
      api.post<RegionDay>(`/adv/days/${day}/regions/${regionId}/extend-cutoff`, { kind, until }),
    onSuccess: (regionDay) => {
      queryClient.setQueryData<Day>(dayKey(day), (current) =>
        current
          ? { ...current, regions: current.regions.map((item) => (item.region.id === regionDay.region.id ? regionDay : item)) }
          : current,
      )
      void queryClient.invalidateQueries({ queryKey: dayKey(day) })
      refresh()
    },
  })
}
