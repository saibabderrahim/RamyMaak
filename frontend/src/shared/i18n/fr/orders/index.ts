import client from './client'
import clientOrders from './clientOrders'
import common from './common'
import regional from './regional'

// Libellés des commandes (lot 5), sous la clé « orders » : chargés avec les
// modules client et régional (shared/i18n/orders.ts), jamais dans le
// paquet initial des autres rôles.
const orders = { ...common, client, clientOrders, regional }

export default orders
