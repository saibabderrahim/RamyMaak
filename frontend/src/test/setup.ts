import 'fake-indexeddb/auto'
import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import '../shared/i18n'
import { db } from '../shared/offline/db'

afterEach(async () => {
  cleanup()
  // Base de l'appareil (lot 8) : cache de requêtes et boîte d'envoi vidés entre les tests.
  await Promise.all([db.kv.clear(), db.outbox.clear()])
  vi.unstubAllGlobals()
  // Panier et brouillons conservés dans le navigateur : rien ne passe d'un test à l'autre.
  localStorage.clear()
  document.cookie.split('; ').forEach((cookie) => {
    const name = cookie.split('=')[0]
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
})
