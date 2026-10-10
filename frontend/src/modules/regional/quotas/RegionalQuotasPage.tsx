import { useTranslation } from 'react-i18next'

/** Quotas du jour du régional. À implémenter (lot de travail F). */
export default function RegionalQuotasPage() {
  const { t } = useTranslation()
  return <h1 className="text-xl font-bold text-slate-900">{t('notifications.regionalQuotas.title')}</h1>
}
