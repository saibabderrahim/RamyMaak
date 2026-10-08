import { Route, Routes } from 'react-router'
import { RoleHome } from '../home/RoleHome'

/**
 * Module ADV (back-office PC, §7.5). Chargé à la demande et exclu du
 * pré-cache du service worker (fichiers « backoffice-* »).
 */
export default function AdvModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="adv" />} />
    </Routes>
  )
}
