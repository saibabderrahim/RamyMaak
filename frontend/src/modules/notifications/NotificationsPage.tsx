import { useTranslation } from 'react-i18next'

/** Centre de notifications (EF-NOT-01). À implémenter (lot de travail F). */
export default function NotificationsPage() {
  const { t } = useTranslation()
  return <h1 className="text-xl font-bold text-slate-900">{t('notifications.title')}</h1>
}
