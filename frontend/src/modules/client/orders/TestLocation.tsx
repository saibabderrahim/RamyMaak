import { useLocation } from 'react-router'
import { locationNotice } from './view'

/** Tests de « Mes commandes » : adresse courante (chemin et paramètres) et message transmis à la page suivante (`state.notice`). */
export function TestLocation() {
  const location = useLocation()
  const notice = locationNotice(location.state)
  return (
    <>
      <div data-testid="where">{`${location.pathname}${location.search}`}</div>
      {notice && <div data-testid="notice">{notice}</div>}
    </>
  )
}
