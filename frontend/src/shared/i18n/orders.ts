import i18n from '.'
import orders from './fr/orders'

// Enregistre les libellés des commandes (clé « orders ») au chargement des
// modules client et régional.
if (!i18n.exists('orders.status.SUBMITTED')) {
  i18n.addResourceBundle('fr', 'translation', { orders }, true, false)
}
