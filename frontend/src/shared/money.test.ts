import { describe, expect, it } from 'vitest'
import { totals, vatCents } from './money'

// Mêmes valeurs que tests/Feature/Domain/ClientOrdersTest.php (App\Support\Money).
describe('money', () => {
  it('computes the totals line by line like the server (RG-P04)', () => {
    expect(
      totals([
        { unit_price_ht: 900, vat_rate: 19, quantity: 40 },
        { unit_price_ht: 850.5, vat_rate: 19, quantity: 3 },
        { unit_price_ht: 120, vat_rate: 9, quantity: 7 },
      ]),
    ).toEqual({ ht: 39391.5, vat: 7400.39, ttc: 46791.89 })
  })

  it('rounds the VAT half up to the cent', () => {
    // 2 551,50 × 19 % = 484,785 → 484,79.
    expect(vatCents(255150, 1900)).toBe(48479)
    expect(totals([])).toEqual({ ht: 0, vat: 0, ttc: 0 })
  })
})
