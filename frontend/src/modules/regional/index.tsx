import { Route, Routes } from 'react-router'
import { RoleHome } from '../home/RoleHome'
import MyClientPage from './clients/MyClientPage'
import MyClientsPage from './clients/MyClientsPage'
import RegionalQuotasPage from './quotas/RegionalQuotasPage'
import { NotFound } from '../../shared/layout/NotFound'

/** Module régional (PWA téléphone, tablette et PC, §7.6). */
export default function RegionalModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="regional" />} />
      <Route path="quotas" element={<RegionalQuotasPage />} />
      <Route path="clients" element={<MyClientsPage />} />
      <Route path="clients/:id" element={<MyClientPage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
