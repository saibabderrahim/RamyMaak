/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// Couleur provisoire : à remplacer par la couleur de la charte Ramy (§9.5).
const THEME_COLOR = '#0B6E4F'

// Code réservé au back-office (§9.5) : modules ADV, Admin et écrans back-office partagés.
const BACK_OFFICE_MODULE = /\/src\/modules\/(adv|admin|backoffice)\//
const BACK_OFFICE_CHUNK = 'backoffice-'

/**
 * Garantit qu'aucun fichier chargé par les modules client ou régional
 * n'importe statiquement du code back-office : sinon la PWA client le
 * téléchargerait, et il manquerait hors ligne (exclu du pré-cache).
 */
function backOfficeIsolation(): Plugin {
  return {
    name: 'ramymaak:back-office-isolation',
    apply: 'build',
    generateBundle(_, bundle) {
      for (const chunk of Object.values(bundle)) {
        if (chunk.type !== 'chunk' || chunk.fileName.includes(BACK_OFFICE_CHUNK)) continue
        const leaked = [
          ...chunk.imports.filter((file) => file.includes(BACK_OFFICE_CHUNK)),
          ...chunk.moduleIds.filter((id) => BACK_OFFICE_MODULE.test(id)),
        ]
        if (leaked.length > 0) {
          this.error(`${chunk.fileName} embarque du code back-office : ${leaked.join(', ')}`)
        }
      }
    },
  }
}

// Back-end Laravel en développement (`php artisan serve`).
const API_TARGET = process.env.VITE_API_TARGET ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    backOfficeIsolation(),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      // Mise à jour proposée par un bandeau, jamais imposée pendant une saisie (§9.5).
      registerType: 'prompt',
      injectRegister: false,
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        // Le service worker ne met en cache que les modules client et
        // régional : le code du back-office (ADV, Admin) n'est jamais
        // téléchargé par avance sur le téléphone d'un client (§9.5).
        globIgnores: [`**/assets/${BACK_OFFICE_CHUNK}*`],
      },
      manifest: {
        name: 'RamyMaak',
        short_name: 'RamyMaak',
        description: 'Commandes B2B Ramy',
        start_url: '/?source=pwa',
        scope: '/',
        display: 'standalone',
        orientation: 'any',
        lang: 'fr',
        dir: 'ltr',
        theme_color: THEME_COLOR,
        background_color: '#FFFFFF',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
  build: {
    rollupOptions: {
      output: {
        // Tout chunk contenant du code ADV ou Admin est nommé « backoffice-* »
        // et exclu du pré-cache ci-dessus ; backOfficeIsolation() vérifie
        // que le reste de l'application ne l'importe jamais statiquement.
        chunkFileNames(chunk) {
          const backOffice = chunk.moduleIds.some((id) => BACK_OFFICE_MODULE.test(id))
          return backOffice ? `assets/${BACK_OFFICE_CHUNK}[name]-[hash].js` : 'assets/[name]-[hash].js'
        },
      },
    },
  },
  server: {
    port: 5173,
    // Même origine pour la PWA et l'API : le cookie de session Sanctum
    // fonctionne sans configuration CORS (§11.1).
    proxy: {
      '/api': API_TARGET,
      '/sanctum': API_TARGET,
      '/broadcasting': API_TARGET,
      '/media': API_TARGET,
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
