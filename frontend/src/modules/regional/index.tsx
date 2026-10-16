import { Route, Routes } from 'react-router'
import '../../shared/i18n/orders'
import RegionalDistributionPage from '../distribution/RegionalDistributionPage'
import { RoleHome } from '../home/RoleHome'
import MyClientPage from './clients/MyClientPage'
import MyClientsPage from './clients/MyClientsPage'
import RegionalOrderPage from './orders/RegionalOrderPage'
import RegionalOrdersPage from './orders/RegionalOrdersPage'
import RegionalQuotasPage from './quotas/RegionalQuotasPage'
import { NotFound } from '../../shared/layout/NotFound'

/** Module régional (PWA téléphone, tablette et PC, §7.6). */
export default function RegionalModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="regional" />} />
      <Route path="quotas" element={<RegionalQuotasPage />} />
      <Route path="repartition" element={<RegionalDistributionPage />} />
      <Route path="commandes" element={<RegionalOrdersPage />} />
      <Route path="commandes/:id" element={<RegionalOrderPage />} />
      <Route path="clients" element={<MyClientsPage />} />
      <Route path="clients/:id" element={<MyClientPage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
