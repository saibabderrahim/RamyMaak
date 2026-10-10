import i18n from '../../../shared/i18n'
import backoffice from './fr'

// Enregistre les libellés back-office (clé « backoffice ») au chargement des
// modules Admin et ADV : la PWA terrain et client ne les télécharge pas.
if (!i18n.exists('backoffice.common.close')) {
  i18n.addResourceBundle('fr', 'translation', { backoffice }, true, false)
}
