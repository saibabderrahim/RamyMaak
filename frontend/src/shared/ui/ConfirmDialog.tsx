import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Dialog } from './Dialog'
import { Button, ErrorAlert } from '.'

type Props = {
  open: boolean
  title: string
  children: ReactNode
  confirmLabel: string
  tone?: 'primary' | 'danger'
  /** Action en cours : bouton de confirmation désactivé, « Enregistrement… ». */
  pending?: boolean
  /** Refus du serveur, affiché dans la boîte (Problem Details). */
  error?: unknown
  onConfirm: () => void
  onCancel: () => void
}

/**
 * Confirmation explicite d'une action (fin d'affectation, remplacement,
 * changement de statut…), commune au back-office et aux écrans terrain :
 * « Annuler » reçoit le focus, la confirmation reste un geste volontaire.
 */
export function ConfirmDialog({ open, title, children, confirmLabel, tone = 'primary', pending = false, error, onConfirm, onCancel }: Props) {
  const { t } = useTranslation()
  if (!open) return null
  return (
    <Dialog title={title} onClose={onCancel}>
      <div className="flex flex-col gap-4 text-sm text-slate-700">
        {children}
        <ErrorAlert error={error} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onCancel} data-autofocus>
            {t('common.cancel')}
          </Button>
          <Button variant={tone} onClick={onConfirm} disabled={pending}>
            {pending ? t('common.saving') : confirmLabel}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
