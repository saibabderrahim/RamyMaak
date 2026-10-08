import { Route, Routes } from 'react-router'
import ClientDetailPage from '../backoffice/clients/ClientDetailPage'
import ClientsPage from '../backoffice/clients/ClientsPage'
import ProductDetailPage from '../backoffice/products/ProductDetailPage'
import ProductsPage from '../backoffice/products/ProductsPage'
import RegionDetailPage from '../backoffice/regions/RegionDetailPage'
import RegionsPage from '../backoffice/regions/RegionsPage'
import { RoleHome } from '../home/RoleHome'

/**
 * Module ADV (back-office PC, §7.5). Chargé à la demande et exclu du
 * pré-cache du service worker (fichiers « backoffice-* »). Les référentiels
 * y sont en lecture : les actions dépendent des permissions.
 */
export default function AdvModule() {
  return (
    <Routes>
      <Route index element={<RoleHome role="adv" />} />
      <Route path="regions" element={<RegionsPage />} />
      <Route path="regions/:id" element={<RegionDetailPage />} />
      <Route path="clients" element={<ClientsPage />} />
      <Route path="clients/:id" element={<ClientDetailPage />} />
      <Route path="produits" element={<ProductsPage />} />
      <Route path="produits/:id" element={<ProductDetailPage />} />
    </Routes>
  )
}
