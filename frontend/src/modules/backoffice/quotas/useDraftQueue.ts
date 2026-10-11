import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, useSyncExternalStore } from 'react'
import type { QuotaMatrix } from '../../../shared/api/types'
import { matrixKey, mergeSavedCells, saveQuotaCells } from './api'
import { DraftQueue } from './drafts'

/**
 * File d'enregistrement des brouillons d'une journée, branchée sur le cache
 * de la matrice : chaque réponse y est reportée (`setQueryData`), et une
 * relecture lancée avant l'enregistrement est relancée pour ne pas écraser
 * les valeurs enregistrées par des valeurs périmées. Avertit avant de
 * quitter la page tant que des saisies ne sont pas enregistrées.
 */
export function useDraftQueue(day: string) {
  const queryClient = useQueryClient()
  const [queue] = useState(
    () =>
      new DraftQueue({
        send: (cells) => saveQuotaCells(day, cells),
        onSaved: (result) => {
          const queryKey = matrixKey(day)
          queryClient.setQueryData<QuotaMatrix>(queryKey, (matrix) => matrix && mergeSavedCells(matrix, result.cells))
          if (queryClient.isFetching({ queryKey }) > 0) void queryClient.invalidateQueries({ queryKey })
        },
        // Journée clôturée entre-temps : la matrice relue passe en lecture seule.
        onClosed: () => void queryClient.invalidateQueries({ queryKey: matrixKey(day) }),
      }),
  )
  const snapshot = useSyncExternalStore(queue.subscribe, queue.getSnapshot, queue.getSnapshot)

  // Départ de l'écran : les saisies en attente partent sans attendre le délai.
  useEffect(() => () => queue.dispose(), [queue])

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
