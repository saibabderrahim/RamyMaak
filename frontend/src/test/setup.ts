import 'fake-indexeddb/auto'
import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import '../shared/i18n'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  // Panier et brouillons conservés dans le navigateur : rien ne passe d'un test à l'autre.
  localStorage.clear()
  document.cookie.split('; ').forEach((cookie) => {
    const name = cookie.split('=')[0]
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
})
