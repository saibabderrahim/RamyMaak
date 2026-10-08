import { Route, Routes } from 'react-router'
import { RoleHome } from '../home/RoleHome'

/** Module client (PWA téléphone, §7.7). */
export default function ClientModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="client" />} />
    </Routes>
  )
}
