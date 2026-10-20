import { describe, expect, it } from 'vitest'

describe('module boundaries', () => {
  // Code de la PWA du régional : rien du back-office (modules adv, admin, backoffice), qui n'est jamais précaché (§9.5).
  const sources = import.meta.glob(['./*.{ts,tsx}', '../orders/*.{ts,tsx}', '../orders/decision/*.{ts,tsx}', '!**/*.test.{ts,tsx}', '!**/testing.tsx'], {
    query: '?raw',
    import: 'default',
    eager: true,
  })

  it('imports nothing from the back-office modules', () => {
    // Ma journée, commandes reçues et décision.
    expect(Object.keys(sources).length).toBeGreaterThanOrEqual(20)
    for (const [file, source] of Object.entries(sources)) {
      expect(String(source), file).not.toMatch(/(from |import\()'[^']*\b(adv|admin|backoffice)\//)
    }
  })
})
