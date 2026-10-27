import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { BUSINESS_TIME_ZONE, businessToday } from '../format'
import { useOnline } from '../pwa/online'
import { Alert } from '../ui'

const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const dayMonthFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, day: '2-digit', month: '2-digit' })

/**
 * « Hors ligne — données de 09:42 » ; lues un autre jour (cache enregistré
 * sur l'appareil) : « Hors ligne — données du 05/10 à 09:42 ».
 */
export function useOfflineDataTitle(): (updatedAt: number, now?: Date) => string {
  const { t } = useTranslation()
  return (updatedAt, now = new Date()) => {
    const at = new Date(updatedAt)
    const time = timeFormat.format(at)
    return businessToday(at) === businessToday(now)
      ? t('offline.data.title', { time })
      : t('offline.data.titleDay', { date: dayMonthFormat.format(at), time })
  }
}

/** Écran jamais lu sur cet appareil, hors ligne : rien à montrer avant le retour du réseau. */
export function NoOfflineData({ children }: { children?: ReactNode }) {
  const { t } = useTranslation()
  return (
    <Alert tone="warning">
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
}

/**
 * Bandeau des écrans lus hors ligne (lot 8, contrat §6.1) : en ligne, rien ;
 * hors ligne, l'heure des données affichées (cache de l'appareil), ou
 * l'absence de données enregistrées pour cet écran.
 */
export function OfflineDataNotice({ updatedAt, hasData = updatedAt > 0, hint }: Props) {
  const { t } = useTranslation()
  const online = useOnline()
  const title = useOfflineDataTitle()
  if (online) return null
  if (!hasData) return <NoOfflineData />
  return (
    <Alert tone="warning">
      <p className="font-semibold">{title(updatedAt)}</p>
      <p>{hint ?? t('offline.data.readOnly')}</p>
    </Alert>
  )
}
