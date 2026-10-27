import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuthenticatedUser } from '../auth/session'
import { Button, Icon } from '../ui'
import { notificationPermission } from './device'
import { dismissInvite, isInviteDismissed } from './invite'
import { usePushErrorMessage } from './messages'
import { usePushState } from './usePushState'

/**
 * Invitation « Recevez vos notifications » de l'accueil du client et de
 * « Ma journée » du régional (contrat §8) : une fois par compte et par
 * appareil, quand le push est disponible et que la permission n'a pas encore
 * été demandée. « Activer » demande la permission et abonne l'appareil ;
 * « Plus tard » l'écarte (le profil garde l'activation).
 */

/** Titre de l'écran (PageHeader), qui reçoit le focus quand l'invitation disparaît avec le bouton touché. */
function focusPageHeading(): void {
  const heading = document.querySelector<HTMLElement>('main h1')
  if (!heading) return
  if (!heading.hasAttribute('tabindex')) heading.tabIndex = -1
  heading.focus()
}

export function PushInvite() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const push = usePushState()
  const pushError = usePushErrorMessage()
  const titleId = useId()
  const [dismissed, setDismissed] = useState(() => isInviteDismissed(user.id))
  // Activation lancée depuis l'invitation : elle reste affichée tant qu'elle n'a pas abouti, avec son échec.
  const [engaged, setEngaged] = useState(false)
  // « Activer » ou « Plus tard » touché : l'invitation peut disparaître avec le bouton qui a le focus.
  const acted = useRef(false)

  const visible =
    !dismissed && (engaged ? push.status === 'off' || push.status === 'checking' : push.status === 'off' && notificationPermission() === 'default')

  // Invitation retirée par l'utilisateur : le focus, retombé sur la page, passe au titre de l'écran.
  useEffect(() => {
    if (visible || !acted.current) return
    acted.current = false
    if (document.activeElement === null || document.activeElement === document.body) focusPageHeading()
  }, [visible])

  if (!visible) return null

  const later = () => {
    acted.current = true
    dismissInvite(user.id)
    setDismissed(true)
  }

  const activate = async () => {
    acted.current = true
    setEngaged(true)
    await push.enable()
    // Question du navigateur fermée sans réponse : l'invitation ne revient pas, le profil propose toujours l'activation.
    if (notificationPermission() === 'default') later()
  }

  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:gap-4">
      <div className="flex min-w-0 flex-1 items-start gap-3">
        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
          <Icon name="bell" />
        </span>
        <div className="min-w-0">
          <h2 id={titleId} className="font-semibold text-slate-900">
            {t('push.invite.title')}
          </h2>
          <p className="text-sm text-slate-700">{t('push.invite.text')}</p>
          {push.error !== null && (
            <p role="alert" className="mt-1 text-sm text-danger">
              {pushError(push.error, 'enable')}
            </p>
          )}
        </div>
      </div>
      <div className="flex shrink-0 flex-wrap gap-2">
        <Button onClick={() => void activate()} disabled={push.busy}>
          {push.busy ? t('push.device.enabling') : t('push.invite.enable')}
        </Button>
        <Button variant="ghost" onClick={later} disabled={push.busy}>
          {t('push.invite.later')}
        </Button>
      </div>
    </section>
  )
}
