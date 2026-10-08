import { Navigate, Route, Routes } from 'react-router'
import AuditLogPage from './AuditLogPage'
import SettingsPage from './SettingsPage'
import UserCreatePage from './UserCreatePage'
import UserDetailPage from './UserDetailPage'
import UsersPage from './UsersPage'

/**
 * Module Admin (back-office PC, §7.2). Chargé à la demande et exclu du
 * pré-cache du service worker (fichiers « backoffice-* »).
 */
export default function AdminModule() {
  return (
    <Routes>
      <Route index element={<Navigate to="comptes" replace />} />
      <Route path="comptes" element={<UsersPage />} />
      <Route path="comptes/nouveau" element={<UserCreatePage />} />
      <Route path="comptes/:id" element={<UserDetailPage />} />
      <Route path="journal" element={<AuditLogPage />} />
      <Route path="parametres" element={<SettingsPage />} />
    </Routes>
  )
}
