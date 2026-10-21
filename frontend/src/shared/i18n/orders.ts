import i18n from '.'
import orders from './fr/orders'

// Enregistre les libellés des commandes (clé « orders ») au chargement des
// modules client, régional et back-office (statuts, motifs).
if (!i18n.exists('orders.status.SUBMITTED')) {
  i18n.addResourceBundle('fr', 'translation', { orders }, true, false)
}
