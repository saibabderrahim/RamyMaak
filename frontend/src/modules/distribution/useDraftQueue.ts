import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, useSyncExternalStore } from 'react'
import type { DistributionGrid } from '../../shared/api/types'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { gridKey, invalidateRegions, mergeSavedCells, saveLimits } from './api'
import { browserDraftStorage, DraftQueue, draftStorageKey } from './drafts'

/**
 * File d'enregistrement des bornes d'une région pour une journée, branchée
 * sur le cache de la grille : chaque réponse y est reportée (`setQueryData`),
 * et une relecture lancée avant l'enregistrement est relancée pour ne pas
 * écraser les valeurs enregistrées par des valeurs périmées. Aucune perte à
 * la fermeture (RG-B09) : la file part dès que la page est masquée ou
 * quittée, le navigateur avertit tant que des saisies attendent, et les
 * saisies sans accusé de réception sont gardées dans le navigateur, par
 * utilisateur, journée et région, jusqu'à la prochaine ouverture de la grille.
 */
export function useDraftQueue(day: string, regionId: number) {
  const queryClient = useQueryClient()
  const user = useAuthenticatedUser()
  const [queue] = useState(
    () =>
      new DraftQueue({
        send: (cells) => saveLimits(day, regionId, cells),
        onSaved: (result) => {
          const queryKey = gridKey(day, regionId)
          const before = queryClient.getQueryData<DistributionGrid>(queryKey)
          queryClient.setQueryData<DistributionGrid>(queryKey, (grid) => grid && mergeSavedCells(grid, result))
          if (queryClient.isFetching({ queryKey }) > 0) void queryClient.invalidateQueries({ queryKey })
          // Premier brouillon : la région passe « Répartition en cours » (§6.1).
          if (before && before.region.status !== result.status) invalidateRegions(queryClient, day)
        },
        // Journée clôturée entre-temps : la grille relue passe en lecture seule.
        onClosed: () => {
          void queryClient.invalidateQueries({ queryKey: gridKey(day, regionId) })
          invalidateRegions(queryClient, day)
        },
        storage: browserDraftStorage(draftStorageKey(user.id, day, regionId)),
      }),
  )
  const snapshot = useSyncExternalStore(queue.subscribe, queue.getSnapshot, queue.getSnapshot)

  // Départ de l'écran : les saisies en attente partent sans attendre le délai.
  useEffect(() => {
    queue.attach()
    return () => queue.dispose()
  }, [queue])

  // Onglet masqué, application mise en arrière-plan ou fermée (RG-B09).
  useEffect(() => {
    const onVisibility = () => {
      if (document.visibilityState === 'hidden') queue.flushNow()
    }
    const onPageHide = (event: PageTransitionEvent) => queue.flushNow(!event.persisted)
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('pagehide', onPageHide)
    return () => {
      document.removeEventListener('visibilitychange', onVisibility)
      window.removeEventListener('pagehide', onPageHide)
    }
  }, [queue])

  const unsaved = snapshot.unsaved > 0
  useEffect(() => {
    if (!unsaved) return
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      // Navigateurs plus anciens : une valeur de retour est exigée.
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [unsaved])

  return { queue, snapshot }
}
