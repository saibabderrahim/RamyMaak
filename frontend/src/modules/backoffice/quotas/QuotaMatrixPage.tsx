import { useTranslation } from 'react-i18next'

/** Matrice produit × région (EF-ADV-02). À implémenter (lot de travail E2). */
export default function QuotaMatrixPage() {
  const { t } = useTranslation()
  return <h1 className="text-xl font-bold text-slate-900">{t('backoffice.quotas.title')}</h1>
}
