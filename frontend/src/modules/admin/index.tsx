import { Navigate, Route, Routes } from 'react-router'
import '../backoffice/i18n'
import ClientCreatePage from '../backoffice/clients/ClientCreatePage'
import ClientDetailPage from '../backoffice/clients/ClientDetailPage'
import ClientsPage from '../backoffice/clients/ClientsPage'
import DayDetailPage from '../backoffice/days/DayDetailPage'
import DaysPage from '../backoffice/days/DaysPage'
import BackOfficeDistributionPage from '../backoffice/distribution/BackOfficeDistributionPage'
import ImportDetailPage from '../backoffice/imports/ImportDetailPage'
import ImportsPage from '../backoffice/imports/ImportsPage'
import ProductCreatePage from '../backoffice/products/ProductCreatePage'
import ProductDetailPage from '../backoffice/products/ProductDetailPage'
import ProductsPage from '../backoffice/products/ProductsPage'
import QuotaMatrixPage from '../backoffice/quotas/QuotaMatrixPage'
import RegionDetailPage from '../backoffice/regions/RegionDetailPage'
import RegionsPage from '../backoffice/regions/RegionsPage'
import AuditLogPage from './AuditLogPage'
import SettingsPage from './SettingsPage'
import UserCreatePage from './UserCreatePage'
import UserDetailPage from './UserDetailPage'
import UsersPage from './UsersPage'
import { NotFound } from '../../shared/layout/NotFound'

/**
 * Module Admin (back-office PC, §7.2). Chargé à la demande et exclu du
 * pré-cache du service worker (fichiers « backoffice-* »).
 */
export default function AdminModule() {
  return (
    <Routes>
      <Route index element={<Navigate to="comptes" replace />} />
      <Route path="comptes" element={<UsersPage />} />
      <Route path="comptes/nouveau" element={<UserCreatePage />} />
      <Route path="comptes/:id" element={<UserDetailPage />} />
      <Route path="regions" element={<RegionsPage />} />
      <Route path="regions/:id" element={<RegionDetailPage />} />
      <Route path="clients" element={<ClientsPage />} />
      <Route path="clients/nouveau" element={<ClientCreatePage />} />
      <Route path="clients/:id" element={<ClientDetailPage />} />
      <Route path="produits" element={<ProductsPage />} />
      <Route path="produits/nouveau" element={<ProductCreatePage />} />
      <Route path="produits/:id" element={<ProductDetailPage />} />
      {/* Journées et quotas en lecture ; « Prolonger » selon day.extend-cutoff. */}
      <Route path="journees" element={<DaysPage />} />
      <Route path="journees/:day" element={<DayDetailPage />} />
      <Route path="journees/:day/quotas" element={<QuotaMatrixPage />} />
      <Route path="journees/:day/repartition/:regionId" element={<BackOfficeDistributionPage />} />
      <Route path="imports" element={<ImportsPage />} />
      <Route path="imports/:id" element={<ImportDetailPage />} />
      <Route path="journal" element={<AuditLogPage />} />
      <Route path="parametres" element={<SettingsPage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
