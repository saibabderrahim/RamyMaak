// Montants en dinars calculés en centimes entiers, avec les mêmes règles que
// le serveur (App\Support\Money, RG-P04) : prix HT × quantité exact, TVA par
// ligne arrondie au centime (demi supérieur), totaux = somme des lignes.
// Les montants affichés au client restent indicatifs (RG-P05).

export function cents(amount: number): number {
  return Math.round(amount * 100)
}

export function amount(value: number): number {
  return value / 100
}

/** Taux en pourcentage (19) → points de base (1 900). */
export function basisPoints(rate: number): number {
  return Math.round(rate * 100)
}

/** TVA d'un montant HT en centimes, arrondie au centime (demi supérieur). */
export function vatCents(htCents: number, rateBasisPoints: number): number {
  return Math.floor((htCents * rateBasisPoints + 5000) / 10000)
}

export type PricedLine = { unit_price_ht: number; vat_rate: number; quantity: number }

export type Totals = { ht: number; vat: number; ttc: number }

/** Totaux HT, TVA et TTC (en dinars) d'une liste de lignes. */
export function totals(lines: readonly PricedLine[]): Totals {
  let ht = 0
  let vat = 0
  for (const line of lines) {
    const lineHt = cents(line.unit_price_ht) * line.quantity
    ht += lineHt
    vat += vatCents(lineHt, basisPoints(line.vat_rate))
  }
  return { ht: amount(ht), vat: amount(vat), ttc: amount(ht + vat) }
}
