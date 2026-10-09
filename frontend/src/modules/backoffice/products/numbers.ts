/**
 * Saisie numérique tolérante (ENF-13) : espaces (séparateur de milliers)
 * ignorés, virgule décimale acceptée. « 1 071,50 » → « 1071.50 ».
 */
export function numericInput(value: string): string {
  return value.replace(/[\s\u00a0\u202f]/g, '').replace(',', '.')
}

const rateFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

/** Taux de TVA affiché à la française (« 9,5 »). */
export function formatRate(rate: number): string {
  return rateFormat.format(rate)
}
