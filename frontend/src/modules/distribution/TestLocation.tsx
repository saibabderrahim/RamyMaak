import { useLocation } from 'react-router'

/** Adresse courante (chemin et paramètres) pour les tests de la page du régional. */
export function TestLocation() {
  const location = useLocation()
  return <output data-testid="where">{`${location.pathname}${location.search}`}</output>
}
