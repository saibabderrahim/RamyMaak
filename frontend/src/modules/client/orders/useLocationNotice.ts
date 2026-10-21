import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { locationNotice } from './view'

/**
 * Message transmis par l'écran précédent (`state.notice`, ex. « Commande … envoyée. ») :
 * affiché une fois, puis retiré de l'historique, pour qu'un rechargement ou un retour
 * arrière ne remontre pas un message périmé (commande validée depuis).
 */
export function useLocationNotice(): string | null {
  const location = useLocation()
  const navigate = useNavigate()
  const received = locationNotice(location.state)
  const [notice, setNotice] = useState(received)
  // Nouveau message reçu alors que la page est déjà affichée : il remplace l'ancien.
  if (received !== null && received !== notice) setNotice(received)

  useEffect(() => {
    if (received === null) return
    void navigate({ pathname: location.pathname, search: location.search, hash: location.hash }, { replace: true, state: null })
  }, [received, navigate, location.pathname, location.search, location.hash])

  return notice
}
