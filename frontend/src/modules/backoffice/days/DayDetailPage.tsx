import { useTranslation } from 'react-i18next'

/** Journée : heures limites, date de livraison, régions. À implémenter (lot de travail E1). */
export default function DayDetailPage() {
  const { t } = useTranslation()
  return <h1 className="text-xl font-bold text-slate-900">{t('backoffice.days.title')}</h1>
}
