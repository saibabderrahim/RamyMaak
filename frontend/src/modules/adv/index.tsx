import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router'
import '../backoffice/i18n'
import ClientDetailPage from '../backoffice/clients/ClientDetailPage'
import ClientsPage from '../backoffice/clients/ClientsPage'
import DayDetailPage from '../backoffice/days/DayDetailPage'
import DaysPage from '../backoffice/days/DaysPage'
import ImportDetailPage from '../backoffice/imports/ImportDetailPage'
import ImportsPage from '../backoffice/imports/ImportsPage'
import DayMonitoringPage from '../backoffice/monitoring/DayMonitoringPage'
import OrderPage from '../backoffice/orders/OrderPage'
import OrdersQueuePage from '../backoffice/orders/OrdersQueuePage'
import ProductCreatePage from '../backoffice/products/ProductCreatePage'
import ProductDetailPage from '../backoffice/products/ProductDetailPage'
import ProductsPage from '../backoffice/products/ProductsPage'
import QuotaMatrixPage from '../backoffice/quotas/QuotaMatrixPage'
import RegionDetailPage from '../backoffice/regions/RegionDetailPage'
import RegionsPage from '../backoffice/regions/RegionsPage'
import { RoleHome } from '../home/RoleHome'
import { NotFound } from '../../shared/layout/NotFound'
import { Spinner } from '../../shared/ui'

// Grille de répartition (lot 4) chargée à la demande : lourde, rarement ouverte depuis le back-office.
const BackOfficeDistributionPage = lazy(() => import('../backoffice/distribution/BackOfficeDistributionPage'))

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
      <Route path="journees/:day/suivi" element={<DayMonitoringPage />} />
      <Route
        path="journees/:day/repartition/:regionId"
        element={
          <Suspense fallback={<Spinner />}>
            <BackOfficeDistributionPage />
          </Suspense>
        }
      />
      {/* Commandes validées et suivantes (lot 6) : file, détail, expédition, annulation. */}
      <Route path="commandes" element={<OrdersQueuePage />} />
      <Route path="commandes/:id" element={<OrderPage />} />
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
