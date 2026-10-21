import { Route, Routes } from 'react-router'
import '../../shared/i18n/orders'
import CartPage from './cart/CartPage'
import CataloguePage from './catalogue/CataloguePage'
import ClientHomePage from './home/ClientHomePage'
import OrderDetailPage from './orders/OrderDetailPage'
import OrdersPage from './orders/OrdersPage'
import { NotFound } from '../../shared/layout/NotFound'

/** Module client (PWA téléphone, §7.7) : commandes du lot 5. */
export default function ClientModule() {
  return (
    <Routes>
      <Route index element={<ClientHomePage />} />
      <Route path="catalogue" element={<CataloguePage />} />
      <Route path="panier" element={<CartPage />} />
      <Route path="commandes" element={<OrdersPage />} />
      <Route path="commandes/:id" element={<OrderDetailPage />} />
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
