import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { API_BASE, api } from '../../../shared/api/client'
import type { Import, ImportStatus, ImportType, Paginated } from '../../../shared/api/types'
import type { BackofficePermission } from '../shared/access'
import { formData, postMultipart } from './multipart'

/** Droit exigé pour déposer, appliquer un import ou télécharger son gabarit (§3.2). */
export const IMPORT_PERMISSIONS: Record<ImportType, BackofficePermission> = {
  CLIENTS: 'client.manage',
  PRODUCTS: 'product.manage',
  PRICES: 'product.manage',
}

/** Statuts d'un import dont l'analyse n'est pas terminée. */
export const IN_PROGRESS: readonly ImportStatus[] = ['PENDING', 'ANALYZING']

/** Intervalle d'interrogation pendant l'analyse (§8.1, étape 2). */
export const POLL_INTERVAL_MS = 1_000

export const IMPORT_STATUSES: readonly ImportStatus[] = ['PENDING', 'ANALYZING', 'READY', 'INVALID', 'APPLIED', 'FAILED']

const importsKey = ['backoffice', 'imports'] as const
const importKey = (id: number) => [...importsKey, 'detail', id] as const

export type ImportFilters = {
  type?: ImportType | ''
  status?: ImportStatus | ''
  page?: number
}

/** Historique : ses imports, tous pour l'Admin. */
export function useImports(filters: ImportFilters) {
  return useQuery({
    queryKey: [...importsKey, 'list', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<Import>>(
        '/imports',
        {
          'filter[type]': filters.type,
          'filter[status]': filters.status,
          page: filters.page,
          per_page: 25,
          sort: '-created_at',
        },
        signal,
      ),
    placeholderData: keepPreviousData,
    // Un import encore en analyse : la liste se rafraîchit d'elle-même.
    refetchInterval: (query) => (query.state.data?.data.some((item) => IN_PROGRESS.includes(item.status)) ? 5_000 : false),
  })
}

/** Suivi d'un import : interrogé chaque seconde tant que l'analyse dure. */
export function useImport(id: number) {
  return useQuery({
    queryKey: importKey(id),
    queryFn: ({ signal }) => api.get<Import>(`/imports/${id}`, undefined, signal),
    enabled: Number.isInteger(id) && id > 0,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status !== undefined && IN_PROGRESS.includes(status) ? POLL_INTERVAL_MS : false
    },
  })
}

export type ImportUpload = { type: ImportType; file: File }

export function useUploadImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ type, file }: ImportUpload) => postMultipart<Import>('/imports', formData({ type, file })),
    onSuccess: (created) => {
      queryClient.setQueryData(importKey(created.id), created)
      void queryClient.invalidateQueries({ queryKey: [...importsKey, 'list'] })
    },
  })
}

/**
 * Application tout ou rien (§8.1, étape 5). Un refus (erreurs apparues
 * entre-temps, autre statut) relit l'import pour afficher son état réel.
 */
export function useApplyImport(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<Import>(`/imports/${id}/apply`),
    onSuccess: (applied) => {
      queryClient.setQueryData(importKey(id), applied)
      void queryClient.invalidateQueries({ queryKey: [...importsKey, 'list'] })
    },
    onError: () => queryClient.invalidateQueries({ queryKey: importKey(id) }),
  })
}

/** Gabarit pré-rempli avec les données actuelles (Annexes B.3 à B.5). */
export function templateUrl(type: ImportType): string {
  return `${API_BASE}/imports/templates/${type.toLowerCase()}`
}

/** Fichier d'origine annoté : colonne « erreurs », cellules fautives surlignées. */
export function errorReportUrl(id: number): string {
  return `${API_BASE}/imports/${id}/error-report`
}
