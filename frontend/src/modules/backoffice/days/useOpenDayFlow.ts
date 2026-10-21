import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useBackoffice } from '../shared/access'
import { useOpenDay } from './api'

/**
 * Ouverture d'une journée depuis le calendrier ou la page d'une journée non
 * ouverte (EF-ADV-01) : directe pour un jour ouvré, après confirmation pour
 * un jour non ouvré ; la matrice des quotas s'affiche ensuite (§4.2, étape 1).
 */
export function useOpenDayFlow() {
  const navigate = useNavigate()
  const { base } = useBackoffice()
  const mutation = useOpenDay()
  const [confirming, setConfirming] = useState<string | null>(null)

  const submit = (day: string) =>
    mutation.mutate(day, {
      onSuccess: (opened) => {
        setConfirming(null)
        navigate(`${base}/journees/${opened.day}/quotas`)
      },
    })

  return {
    mutation,
    /** Date en attente de confirmation (jour non ouvré), sinon null. */
    confirming,
    /** Ouverture demandée : un jour non ouvré passe d'abord par la confirmation. */
    request: (day: string, workingDay: boolean) => {
      mutation.reset()
      if (workingDay) submit(day)
      else setConfirming(day)
    },
    confirm: () => {
      if (confirming) submit(confirming)
    },
    cancel: () => {
      setConfirming(null)
      mutation.reset()
    },
  }
}

export type OpenDayFlow = ReturnType<typeof useOpenDayFlow>
