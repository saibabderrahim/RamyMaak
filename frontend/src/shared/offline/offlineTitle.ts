import { useTranslation } from 'react-i18next'
import { BUSINESS_TIME_ZONE, businessToday } from '../format'

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
