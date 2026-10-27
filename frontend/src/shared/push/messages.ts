import { useTranslation } from 'react-i18next'
import { useErrorMessage } from '../ui/useErrorMessage'
import { PushError } from './device'

/**
 * Message d'un échec d'activation ou de désactivation : refus du navigateur
 * (`PushError`), sinon message du serveur ou du réseau.
 */
export function usePushErrorMessage() {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  return (error: unknown, action: 'enable' | 'disable'): string => {
    if (error instanceof PushError) {
      if (error.code === 'dismissed') return t('push.device.dismissed')
      return error.code === 'notReady' ? t('push.device.errors.notReady') : t('push.device.errors.subscribe')
    }
    return action === 'enable' ? t('push.device.errors.enable', { reason: errorMessage(error) }) : t('push.device.errors.disable', { reason: errorMessage(error) })
  }
}
