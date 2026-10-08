import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { z } from 'zod'
import { fr as zodFr } from 'zod/locales'
import fr from './fr'

export const defaultNS = 'translation'

declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: typeof defaultNS
    resources: { translation: typeof fr }
  }
}

void i18n.use(initReactI18next).init({
  lng: 'fr',
  fallbackLng: 'fr',
  resources: { fr: { translation: fr } },
  interpolation: { escapeValue: false },
})

// Messages de validation des formulaires en français.
z.config(zodFr())

// Sens d'écriture du document : prêt pour l'arabe (droite à gauche, ENF-12).
function applyDocumentLanguage(lng: string): void {
  document.documentElement.lang = lng
  document.documentElement.dir = i18n.dir(lng)
}

applyDocumentLanguage(i18n.language)
i18n.on('languageChanged', applyDocumentLanguage)

export default i18n
