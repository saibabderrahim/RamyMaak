import { describe, expect, it } from 'vitest'
import { isAppNavigation } from './navigation'

describe('service worker navigation', () => {
  it('serves the application shell for the screens only', () => {
    expect(isAppNavigation('/admin/produits/42')).toBe(true)
    expect(isAppNavigation('/regional/clients')).toBe(true)
  })

  it('never answers an API call, a server endpoint or a product photo with the shell', () => {
    for (const path of ['/api/v1/clients', '/sanctum/csrf-cookie', '/up', '/media/31/conversions/p0042-large.webp']) {
      expect(isAppNavigation(path)).toBe(false)
    }
  })
})
