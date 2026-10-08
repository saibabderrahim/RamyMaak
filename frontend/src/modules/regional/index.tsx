import { Route, Routes } from 'react-router'
import { RoleHome } from '../home/RoleHome'

/** Module régional (PWA téléphone, tablette et PC, §7.6). */
export default function RegionalModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="regional" />} />
    </Routes>
  )
}
