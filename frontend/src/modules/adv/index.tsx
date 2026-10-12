import { Route, Routes } from 'react-router'
import '../backoffice/i18n'
import ClientDetailPage from '../backoffice/clients/ClientDetailPage'
import ClientsPage from '../backoffice/clients/ClientsPage'
import DayDetailPage from '../backoffice/days/DayDetailPage'
import DaysPage from '../backoffice/days/DaysPage'
import BackOfficeDistributionPage from '../distribution/BackOfficeDistributionPage'
import ImportDetailPage from '../backoffice/imports/ImportDetailPage'
import ImportsPage from '../backoffice/imports/ImportsPage'
import ProductCreatePage from '../backoffice/products/ProductCreatePage'
import ProductDetailPage from '../backoffice/products/ProductDetailPage'
import ProductsPage from '../backoffice/products/ProductsPage'
import QuotaMatrixPage from '../backoffice/quotas/QuotaMatrixPage'
import RegionDetailPage from '../backoffice/regions/RegionDetailPage'
import RegionsPage from '../backoffice/regions/RegionsPage'
import { RoleHome } from '../home/RoleHome'
import { NotFound } from '../../shared/layout/NotFound'

/**
 * Module ADV (back-office PC, §7.5). Chargé à la demande et exclu du
 * pré-cache du service worker (fichiers « backoffice-* »). Les référentiels
 * y sont en lecture : les actions dépendent des permissions.
 */
export default function AdvModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="adv" />} />
      <Route path="journees" element={<DaysPage />} />
      <Route path="journees/:day" element={<DayDetailPage />} />
      <Route path="journees/:day/quotas" element={<QuotaMatrixPage />} />
      <Route path="journees/:day/repartition/:regionId" element={<BackOfficeDistributionPage />} />
      <Route path="regions" element={<RegionsPage />} />
      <Route path="regions/:id" element={<RegionDetailPage />} />
      <Route path="clients" element={<ClientsPage />} />
      <Route path="clients/:id" element={<ClientDetailPage />} />
      <Route path="produits" element={<ProductsPage />} />
      {/* Création possible si l'ADV reçoit en option le droit product.manage. */}
      <Route path="produits/nouveau" element={<ProductCreatePage />} />
      <Route path="produits/:id" element={<ProductDetailPage />} />
      {/* Imports : quotas (quota.edit) et, selon les droits optionnels (product.manage, client.manage), référentiels. La page ne propose que les types permis. */}
      <Route path="imports" element={<ImportsPage />} />
      <Route path="imports/:id" element={<ImportDetailPage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
