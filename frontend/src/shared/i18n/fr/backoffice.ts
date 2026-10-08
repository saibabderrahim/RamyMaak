import clients from './backoffice/clients'
import common from './backoffice/common'
import imports from './backoffice/imports'
import products from './backoffice/products'
import regions from './backoffice/regions'

// Libellés des écrans back-office des référentiels (lot 2), un fichier par
// domaine : common, regions et clients (écrans régions et clients) ;
// products et imports (écrans catalogue et imports).
const backoffice = {
  placeholder: 'Écran en cours de réalisation',
  common,
  regions,
  clients,
  products,
  imports,
} as const

export default backoffice
