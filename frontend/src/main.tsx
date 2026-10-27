import { onlineManager } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// Police Rubik auto-hébergée (charte Ramy, lot 7) : latin seulement, l'arabe viendra avec la V1.1.
import '@fontsource/rubik/latin-400.css'
import '@fontsource/rubik/latin-600.css'
import App from './App'
import './index.css'
import { createQueryClient } from './shared/api/queryClient'
import './shared/i18n'
import { startQueryPersistence } from './shared/offline/persist'

const queryClient = createQueryClient()

// TanStack Query suppose le réseau présent au démarrage : hors ligne, les
// requêtes attendent son retour en montrant les données enregistrées.
onlineManager.setOnline(navigator.onLine)

function render(): void {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App queryClient={queryClient} />
    </StrictMode>,
  )
}

// Cache de requêtes du dernier compte relu avant le premier affichage
// (lot 8, §6.1) : hors ligne, l'utilisateur et ses écrans s'affichent.
void startQueryPersistence(queryClient).finally(render)
