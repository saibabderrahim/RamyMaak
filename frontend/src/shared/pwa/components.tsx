import { useTranslation } from 'react-i18next'
import { useRegisterSW } from 'virtual:pwa-register/react'
import { Alert, Button } from '../ui'
import { useInstallPrompt } from './install'
import { useOnline } from './online'

/**
 * Bandeau « Nouvelle version disponible — Recharger » : la mise à jour n'est
 * jamais imposée pendant une saisie (§9.5).
 */
export function UpdateBanner() {
  const { t } = useTranslation()
  const {
    needRefresh: [needRefresh],
    updateServiceWorker,
  } = useRegisterSW()

  if (!needRefresh) return null

  return (
    <div className="fixed inset-x-0 bottom-20 z-50 mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lg md:bottom-6">
      <span>{t('pwa.updateAvailable')}</span>
      <Button variant="secondary" className="min-h-9" onClick={() => void updateServiceWorker(true)}>
        {t('pwa.reload')}
      </Button>
    </div>
  )
}

export function OfflineBanner() {
  const { t } = useTranslation()
  const online = useOnline()
  if (online) return null
  return (
    <div role="status" className="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">
      {t('pwa.offline')}
    </div>
  )
}

/** Aide à l'installation de l'application (EF-CLT-06). */
export function InstallHelp() {
  const { t } = useTranslation()
  const install = useInstallPrompt()

  if (install.installed) return <Alert tone="success">{t('pwa.installed')}</Alert>

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-slate-700">{t('pwa.installIntro')}</p>
      {install.canPrompt && (
        <Button className="self-start" onClick={() => void install.prompt()}>
          {t('pwa.install')}
        </Button>
      )}
      {install.needsIosGuide && <Alert>{t('pwa.iosSteps')}</Alert>}
      {!install.canPrompt && !install.needsIosGuide && <p className="text-sm text-slate-500">{t('pwa.unsupported')}</p>}
    </div>
  )
}
