import { Route, Routes } from 'react-router'
import { RoleHome } from '../home/RoleHome'
import { NotFound } from '../../shared/layout/NotFound'

/** Module client (PWA téléphone, §7.7). */
export default function ClientModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="client" />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
