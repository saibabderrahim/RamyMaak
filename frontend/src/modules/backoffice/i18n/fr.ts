import clients from './clients'
import common from './common'
import imports from './imports'
import products from './products'
import regions from './regions'

// Libellés des écrans back-office des référentiels (lot 2), un fichier par
// domaine. Hors du fichier fr.ts commun : ils ne sont chargés qu'avec les
// modules Admin et ADV (voir ./index.ts), jamais par la PWA terrain ou client.
const backoffice = {
  common,
  regions,
  clients,
  products,
  imports,
} as const

export default backoffice
