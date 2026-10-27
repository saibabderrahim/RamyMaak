import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useOnline } from '../pwa/online'
import { Alert } from '../ui'
import { useOfflineDataTitle } from './offlineTitle'

/** Écran jamais lu sur cet appareil, hors ligne : rien à montrer avant le retour du réseau. */
export function NoOfflineData({ children, className }: { children?: ReactNode; className?: string }) {
  const { t } = useTranslation()
  return (
    <Alert tone="warning" className={className}>
      <p className="font-semibold">{t('offline.data.none')}</p>
      {children}
    </Alert>
  )
}

type Props = {
  /** `dataUpdatedAt` de la requête principale de l'écran : heure de sa dernière lecture réussie. */
  updatedAt: number
  /** Données présentes (par défaut : une lecture réussie a eu lieu). */
  hasData?: boolean
  /** Ce qui reste possible ou non hors ligne (par défaut : les écritures reprendront au retour du réseau). */
  hint?: ReactNode
  className?: string
}

/**
 * Bandeau des écrans lus hors ligne (lot 8, contrat §6.1) : en ligne, rien ;
 * hors ligne, l'heure des données affichées (cache de l'appareil), ou
 * l'absence de données enregistrées pour cet écran.
 */
export function OfflineDataNotice({ updatedAt, hasData = updatedAt > 0, hint, className }: Props) {
  const { t } = useTranslation()
  const online = useOnline()
  const title = useOfflineDataTitle()
  if (online) return null
  if (!hasData) return <NoOfflineData className={className} />
  return (
    <Alert tone="warning" className={className}>
      <p className="font-semibold">{title(updatedAt)}</p>
      <p>{hint ?? t('offline.data.readOnly')}</p>
    </Alert>
  )
}
