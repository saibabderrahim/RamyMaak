import { useTranslation } from 'react-i18next'

/** Calendrier des journées (EF-ADV-01). À implémenter (lot de travail E1). */
export default function DaysPage() {
  const { t } = useTranslation()
  return <h1 className="text-xl font-bold text-slate-900">{t('backoffice.days.title')}</h1>
}
