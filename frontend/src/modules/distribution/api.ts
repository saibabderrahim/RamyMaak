import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, buildUrl, csrfHeaders, NetworkError } from '../../shared/api/client'
import { FALLBACK_REFRESH_MS, REGION_DAY_DECISION_EVENTS } from '../../shared/realtime'
import type {
  CopyMode,
  CopyPreviousResult,
  DistributionGrid,
  DistributionResult,
  LimitCellInput,
  RegionalQuotas,
  SaveLimitsResult,
} from '../../shared/api/types'
import { KEEPALIVE_MAX_BYTES } from './model'

/** Grille de répartition — contrat : docs/lot-4-contrat-api.md §3. */

/** Régions du périmètre du régional pour une journée (`GET /regional/quotas`). */
export const regionsKey = (day: string) => ['distribution', 'regions', day] as const

export const gridKey = (day: string, regionId: number) => ['distribution', 'grid', day, regionId] as const

export const backOfficeGridKey = (day: string, regionId: number) => ['distribution', 'backoffice', day, regionId] as const

/**
 * Événements du canal `region-day.{regionId}.{day}` qui imposent de relire la
 * grille (§9.6) ; ceux des commandes tiennent S à jour en direct (contrat du
 * lot 5, §5), les décisions et l'annulation ADV tiennent S et V à jour
 * (contrat du lot 6, §7). Toujours un sur-ensemble de ORDER_EVENTS.
 */
export const GRID_EVENTS = [
  'DistributionPublished',
  'LimitDraftsUpdated',
  'QuotaPublished',
  'OrderSubmitted',
  'OrderUpdated',
  'OrderCancelled',
  'OrdersExpired',
  ...REGION_DAY_DECISION_EVENTS,
] as const

/**
 * Régions du régional ce jour-là. Journée pas encore ouverte, ou aucune
 * région affectée : relue toutes les 30 s (l'ouverture n'est pas diffusée
 * au régional) ; ensuite, la grille et ses événements la tiennent à jour.
 */
export function useRegionalRegions(day: string) {
  return useQuery({
    queryKey: regionsKey(day),
    queryFn: ({ signal }) => api.get<RegionalQuotas>('/regional/quotas', { day }, signal),
    refetchInterval: (query) => {
      const data = query.state.data
      return data && data.opened && data.regions.length > 0 ? false : FALLBACK_REFRESH_MS
    },
  })
}

/** Grille du régional pour une région de son périmètre (404 hors périmètre ou journée non ouverte). */
export function useDistributionGrid(day: string, regionId: number, refetchInterval: number | false) {
  return useQuery({
    queryKey: gridKey(day, regionId),
    queryFn: ({ signal }) => api.get<DistributionGrid>(`/regional/days/${day}/grid`, { region: regionId }, signal),
    refetchInterval,
  })
}

/** Grille en lecture seule de l'ADV et de l'Admin. */
export function useBackOfficeGrid(day: string, regionId: number, refetchInterval: number | false) {
  return useQuery({
    queryKey: backOfficeGridKey(day, regionId),
    queryFn: ({ signal }) => api.get<DistributionGrid>(`/adv/days/${day}/regions/${regionId}/grid`, undefined, signal),
    refetchInterval,
  })
}

/**
 * Bornes enregistrées en brouillon (RG-B09) ; `limit` null : pas de borne.
 * Un lot assez petit part en `keepalive` : il aboutit même si la page se
 * ferme pendant l'envoi (le navigateur plafonne à 64 Kio le corps des
 * requêtes qui survivent à la page).
 */
export async function saveLimits(day: string, regionId: number, cells: LimitCellInput[]): Promise<SaveLimitsResult> {
  const body = JSON.stringify({ region_id: regionId, cells })
  const send = async (renew: boolean) => {
    try {
      return await fetch(buildUrl(`/regional/days/${day}/limits`), {
        method: 'PUT',
        credentials: 'include',
        keepalive: body.length <= KEEPALIVE_MAX_BYTES,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(await csrfHeaders(renew)) },
        body,
      })
    } catch {
      throw new NetworkError()
    }
  }

  let response = await send(false)
  // Jeton anti-CSRF expiré (419) : renouvelé, la requête est rejouée une fois.
  if (response.status === 419) response = await send(true)
  const payload: unknown = await response.json().catch(() => null)
  if (!response.ok) throw ApiError.fromResponse(response.status, payload)
  return payload as SaveLimitsResult
}

/**
 * Clé de la page « Quotas du jour » (`regionalQuotasKey` de
 * modules/regional/quotas/api.ts) : même point d'accès que `regionsKey`,
 * mis en cache à part. Reprise telle quelle pour ne pas dépendre de ce module.
 */
const quotasPageKey = (day: string) => ['regional', 'quotas', day] as const

/**
 * Statut d'une région changé (premier brouillon, « Reprendre J-1 », envoi,
 * clôture) : les deux listes des régions du jour sont relues.
 */
export function invalidateRegions(queryClient: QueryClient, day: string): void {
  void queryClient.invalidateQueries({ queryKey: regionsKey(day) })
  void queryClient.invalidateQueries({ queryKey: quotasPageKey(day) })
}

/**
 * Grille et listes des régions relues après « Reprendre J-1 » ou un envoi
 * (statut, version, brouillons) ; aussi quand la journée a été clôturée
 * entre-temps (409 DAY_NOT_OPEN) : la grille passe en lecture seule.
 */
function useRefreshGrid(day: string, regionId: number) {
  const queryClient = useQueryClient()
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: gridKey(day, regionId) })
    invalidateRegions(queryClient, day)
  }
  const refreshIfClosed = (error: unknown) => {
    if (error instanceof ApiError && error.code === 'DAY_NOT_OPEN') refresh()
  }
  return { refresh, refreshIfClosed }
}

/** « Reprendre J-1 » (RG-B08) : écraser ou compléter les cases vides, en brouillon ; 422 sur `source` sans répartition envoyée. */
export function useCopyPrevious(day: string, regionId: number) {
  const { refresh, refreshIfClosed } = useRefreshGrid(day, regionId)
  return useMutation({
    mutationFn: (mode: CopyMode) => api.post<CopyPreviousResult>(`/regional/days/${day}/limits/copy-previous`, { region_id: regionId, mode }),
    onSuccess: refresh,
    onError: refreshIfClosed,
  })
}

/** « Valider et envoyer aux clients » (RG-B06, RG-B07) : tout ou rien, `violations` en cas de refus. */
export function usePublishDistribution(day: string, regionId: number) {
  const { refresh, refreshIfClosed } = useRefreshGrid(day, regionId)
  return useMutation({
    mutationFn: () => api.post<DistributionResult>(`/regional/days/${day}/distribution/publish`, { region_id: regionId }),
    onSuccess: refresh,
    onError: refreshIfClosed,
  })
}

/**
 * Report dans la grille de l'état renvoyé pour les cellules enregistrées.
 * Seules les lignes touchées changent de référence (lignes mémorisées) ; le
 * statut de la région et le nombre de brouillons à envoyer suivent.
 */
export function mergeSavedCells(grid: DistributionGrid, result: SaveLimitsResult): DistributionGrid {
  if (result.cells.length === 0 && result.status === grid.region.status) return grid
  const cells = { ...grid.cells }
  const touched = new Set<string>()
  let drafts = grid.publication.drafts

  for (const { client_id, product_id, limit, draft, pending, validated } of result.cells) {
    const clientId = String(client_id)
    if (!touched.has(clientId)) {
      cells[clientId] = { ...cells[clientId] }
      touched.add(clientId)
    }
    const row = cells[clientId]!
    const before = row[String(product_id)]?.draft ?? null
    row[String(product_id)] = { limit, draft, pending, validated }
    drafts += (draft !== null ? 1 : 0) - (before !== null ? 1 : 0)
  }

  return {
    ...grid,
    region: result.status === grid.region.status ? grid.region : { ...grid.region, status: result.status },
    cells,
    publication: drafts === grid.publication.drafts ? grid.publication : { ...grid.publication, drafts: Math.max(0, drafts) },
  }
}
