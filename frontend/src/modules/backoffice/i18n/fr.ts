import clients from './clients'
import common from './common'
import days from './days'
import documents from './documents'
import imports from './imports'
import monitoring from './monitoring'
import orders from './orders'
import products from './products'
import quotas from './quotas'
import reasons from './reasons'
import regions from './regions'

// Libellés des écrans back-office (référentiels du lot 2, journées et quotas
// du lot 3, commandes, suivi, documents et motifs du lot 6), un fichier par
// domaine. Hors du fichier fr.ts commun : ils ne sont chargés qu'avec les
// modules Admin et ADV (voir ./index.ts), jamais par la PWA terrain ou client.
const backoffice = {
  common,
  regions,
  clients,
  products,
  imports,
  days,
  quotas,
  orders,
  monitoring,
  documents,
  reasons,
} as const

export default backoffice
