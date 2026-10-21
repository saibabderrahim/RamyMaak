import { useLocation, useNavigate } from 'react-router'

/**
 * Tests de l'accueil, du catalogue et du panier : adresse courante (chemin
 * et paramètres), message transmis à la page suivante (`state.notice`) et
 * bouton Retour du navigateur.
 */
export function TestProbe() {
  const location = useLocation()
  const navigate = useNavigate()
  const notice = (location.state as { notice?: string } | null)?.notice
  return (
    <>
      <div data-testid="location">{`${location.pathname}${location.search}`}</div>
      {notice && <div data-testid="flash">{notice}</div>}
      <button type="button" onClick={() => navigate(-1)}>
        Retour du navigateur
      </button>
    </>
  )
}
