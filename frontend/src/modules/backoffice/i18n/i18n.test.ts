import { describe, expect, it } from 'vitest'
import i18n from '../../../shared/i18n'
import '.'

// Le back-office affiche des statuts et des motifs de commande (lot 6) : le bundle partagé « orders » est chargé avec le sien.
describe('back-office labels', () => {
  it('registers the shared order labels with the back-office ones', () => {
    expect(i18n.t('orders.status.IN_PREPARATION')).toBe('En préparation')
    expect(i18n.t('orders.reasons.placeholder')).toBe('Choisir un motif')
    expect(i18n.t('backoffice.orders.title')).toBe('Commandes')
    expect(i18n.t('backoffice.reasons.types.SHIPPING_GAP')).toBe('Écart d’expédition')
  })
})
