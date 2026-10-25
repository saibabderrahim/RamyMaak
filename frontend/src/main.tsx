import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// Police Rubik auto-hébergée (charte Ramy, lot 7) : latin seulement, l'arabe viendra avec la V1.1.
import '@fontsource/rubik/latin-400.css'
import '@fontsource/rubik/latin-600.css'
import App from './App'
import './index.css'
import { createQueryClient } from './shared/api/queryClient'
import './shared/i18n'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App queryClient={createQueryClient()} />
  </StrictMode>,
)
