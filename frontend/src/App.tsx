import { QueryClientProvider, type QueryClient } from '@tanstack/react-query'
import { lazy, Suspense } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router'
import { useTranslation } from 'react-i18next'
import ChangePasswordPage from './modules/auth/ChangePasswordPage'
import LoginPage from './modules/auth/LoginPage'
import { RequireAuth, RequireRole, RoleHomeRedirect } from './shared/auth/guards'
import { AppShell } from './shared/layout/AppShell'
import { UpdateBanner } from './shared/pwa/components'
import { Alert, Spinner } from './shared/ui'

// Un module par rôle, chargé à la demande : un client ne télécharge jamais
// le code du back-office (§9.5).
const ClientModule = lazy(() => import('./modules/client'))
const RegionalModule = lazy(() => import('./modules/regional'))
const AdvModule = lazy(() => import('./modules/adv'))
const AdminModule = lazy(() => import('./modules/admin'))
const ProfilePage = lazy(() => import('./modules/profile/ProfilePage'))

function NotFound() {
  const { t } = useTranslation()
  return <Alert tone="warning">{t('errors.notFound')}</Alert>
}

export function AppRoutes() {
  return (
    <Suspense fallback={<Spinner />}>
      <Routes>
        <Route path="/connexion" element={<LoginPage />} />
        <Route element={<RequireAuth />}>
          <Route path="/mot-de-passe" element={<ChangePasswordPage />} />
          <Route element={<AppShell />}>
            <Route index element={<RoleHomeRedirect />} />
            <Route path="/profil" element={<ProfilePage />} />
            <Route path="/client/*" element={<RequireRole roles={['client']}><ClientModule /></RequireRole>} />
            <Route path="/regional/*" element={<RequireRole roles={['regional']}><RegionalModule /></RequireRole>} />
            <Route path="/adv/*" element={<RequireRole roles={['adv']}><AdvModule /></RequireRole>} />
            <Route path="/admin/*" element={<RequireRole roles={['admin']}><AdminModule /></RequireRole>} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Routes>
    </Suspense>
  )
}

export default function App({ queryClient }: { queryClient: QueryClient }) {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AppRoutes />
        <UpdateBanner />
      </BrowserRouter>
    </QueryClientProvider>
  )
}
