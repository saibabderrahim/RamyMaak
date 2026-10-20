import i18n from '../../../shared/i18n'
// Statuts et motifs des commandes (clé « orders ») : partagés avec la PWA, utilisés par la file ADV et le suivi.
import '../../../shared/i18n/orders'
import backoffice from './fr'

// Enregistre les libellés back-office (clé « backoffice ») au chargement des
// modules Admin et ADV : la PWA terrain et client ne les télécharge pas.
if (!i18n.exists('backoffice.common.close')) {
  i18n.addResourceBundle('fr', 'translation', { backoffice }, true, false)
}
