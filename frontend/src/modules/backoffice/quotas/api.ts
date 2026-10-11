import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../../shared/api/client'
import type {
  CopyMode,
  PublicationResult,
  QuotaCellInput,
  QuotaMatrix,
  SavedQuotaCell,
  SaveQuotasResult,
} from '../../../shared/api/types'
import { daysKey } from '../days/api'
import { fetchFile, saveFile } from '../shared/download'

/** Matrice des quotas d'une journée — contrat : docs/lot-3-contrat-api.md §4. */

export const matrixKey = (day: string) => ['backoffice', 'quotas', 'matrix', day] as const

/** Événements du canal `adv.{day}` qui imposent de relire la matrice (§9.6). */
export const MATRIX_EVENTS = ['QuotaPublished', 'DraftsUpdated', 'DayClosed'] as const

export function useQuotaMatrix(day: string, refetchInterval: number | false) {
  return useQuery({
    queryKey: matrixKey(day),
    queryFn: ({ signal }) => api.get<QuotaMatrix>(`/adv/days/${day}/quotas`, undefined, signal),
    refetchInterval,
  })
}

/** Enregistrement de cellules en brouillon (RG-Q03) ; `quantity` null annule le brouillon. */
export function saveQuotaCells(day: string, cells: QuotaCellInput[]): Promise<SaveQuotasResult> {
  return api.put<SaveQuotasResult>(`/adv/days/${day}/quotas`, { cells })
}

/**
 * Report dans la matrice de l'état renvoyé pour les cellules enregistrées.
 * Seules les lignes touchées changent de référence (lignes mémorisées), et
 * le nombre de brouillons des régions suit les cellules entrées en
 * brouillon ou revenues à la valeur publiée.
 */
export function mergeSavedCells(matrix: QuotaMatrix, saved: readonly SavedQuotaCell[]): QuotaMatrix {
  if (saved.length === 0) return matrix
  const cells = { ...matrix.cells }
  const touched = new Set<string>()
  const drafts = new Map<number, number>()

  for (const { product_id, region_id, quantity, draft, validated } of saved) {
    const productId = String(product_id)
    if (!touched.has(productId)) {
      cells[productId] = { ...cells[productId] }
      touched.add(productId)
    }
    const row = cells[productId]!
    const before = row[String(region_id)]?.draft ?? null
    row[String(region_id)] = { quantity, draft, validated }
    const delta = (draft !== null ? 1 : 0) - (before !== null ? 1 : 0)
    if (delta !== 0) drafts.set(region_id, (drafts.get(region_id) ?? 0) + delta)
  }

  const regions =
    drafts.size === 0
      ? matrix.regions
      : matrix.regions.map((region) =>
          drafts.has(region.id) ? { ...region, pending_drafts: Math.max(0, region.pending_drafts + (drafts.get(region.id) ?? 0)) } : region,
        )

  return { ...matrix, cells, regions }
}

/**
 * Matrice relue après une publication ou une copie ; les écrans des journées
 * aussi (statuts, versions et brouillons des régions). Une journée clôturée
 * entre-temps (409 DAY_NOT_OPEN) fait relire la matrice, qui passe en
 * lecture seule.
 */
function useRefreshMatrix(day: string) {
  const queryClient = useQueryClient()
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: matrixKey(day) })
    void queryClient.invalidateQueries({ queryKey: daysKey })
  }
  const refreshIfClosed = (error: unknown) => {
    if (error instanceof ApiError && error.code === 'DAY_NOT_OPEN') refresh()
  }
  return { refresh, refreshIfClosed }
}

export type CopyInput = { source_day: string; mode: CopyMode; region_ids?: number[] }

/** « Copier depuis la journée… » : quotas publiés de la source, repris en brouillon. */
export function useCopyQuotas(day: string) {
  const { refresh, refreshIfClosed } = useRefreshMatrix(day)
  return useMutation({
    mutationFn: (input: CopyInput) => api.post<{ changed: number }>(`/adv/days/${day}/quotas/copy`, input),
    onSuccess: refresh,
    onError: refreshIfClosed,
  })
}

export type PublishInput = { all: true } | { region_ids: number[] }

/** Publication (RG-Q04) : tout ou rien, QUOTA_BELOW_VALIDATED avec `violations` (RG-Q05). */
export function usePublishQuotas(day: string) {
  const { refresh, refreshIfClosed } = useRefreshMatrix(day)
  return useMutation({
    mutationFn: (input: PublishInput) => api.post<PublicationResult>(`/adv/days/${day}/quotas/publish`, input),
    onSuccess: refresh,
    onError: refreshIfClosed,
  })
}

/** Gabarit Excel de la journée, pré-rempli avec les valeurs affichées (§8.1, annexe B.1). */
export async function downloadQuotaTemplate(day: string): Promise<void> {
  const file = await fetchFile(`/imports/templates/quotas?day=${encodeURIComponent(day)}`)
  saveFile(file.blob, file.filename ?? `quotas-${day}.xlsx`)
}
