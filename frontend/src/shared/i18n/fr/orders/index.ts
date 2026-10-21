import client from './client'
import clientOrders from './clientOrders'
import common from './common'
import decision from './decision'
import myDay from './myDay'
import reasons from './reasons'
import regional from './regional'

// Libellés des commandes (lots 5 et 6), sous la clé « orders » : chargés avec
// les modules client, régional et back-office (shared/i18n/orders.ts), jamais
// dans le paquet initial.
const orders = { ...common, client, clientOrders, regional, decision, myDay, reasons }

export default orders
