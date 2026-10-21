import { type FormEvent, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../shared/api/client'
import type { CopyMode, CopyPreviousResult } from '../../shared/api/types'
import { Alert, Button, ErrorAlert } from '../../shared/ui'
import { useCopyPrevious } from './api'
import { Dialog } from '../../shared/ui/Dialog'

const ignore = () => undefined

type Props = {
  day: string
  regionId: number
  /** Enregistre les saisies en attente avant la reprise. */
  flush: () => Promise<void>
  onDone: (result: CopyPreviousResult) => void
  onClose: () => void
}

/**
 * « Reprendre J-1 » (RG-B08) : bornes de la dernière répartition envoyée de
 * la région, pour les produits disponibles ce jour et plafonnées au quota,
 * reprises en brouillon. Écraser : chaque case prend la valeur reprise ;
 * Compléter : seules les cases vides sont renseignées. Les saisies en
 * attente sont enregistrées d'abord.
 */
export function CopyPreviousDialog({ day, regionId, flush, onDone, onClose }: Props) {
  const { t } = useTranslation()
  const modeName = useId()
  const copy = useCopyPrevious(day, regionId)
  const [mode, setMode] = useState<CopyMode>('FILL')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setMessage(null)
    setError(null)
    setBusy(true)
    try {
      await flush()
    } catch (flushError) {
      if (flushError instanceof ApiError) setError(flushError)
      else setMessage(t('distribution.flushFailed'))
      setBusy(false)
      return
    }
    try {
      onDone(await copy.mutateAsync(mode))
    } catch (copyError) {
      // Aucune répartition envoyée auparavant : 422 sur `source`.
      const source = copyError instanceof ApiError ? copyError.fieldErrors.source?.[0] : undefined
      if (source !== undefined) setMessage(source || t('distribution.copy.noPrevious'))
      else setError(copyError)
      setBusy(false)
    }
  }

  // Reprise en cours : elle ne peut plus être arrêtée, le dialogue reste
  // ouvert jusqu'à son compte rendu (Annuler, × et Échap sans effet).
  return (
    <Dialog title={t('distribution.copy.title')} onClose={busy ? ignore : onClose}>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4 text-sm text-slate-700">
        <p>{t('distribution.copy.intro')}</p>
        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 font-semibold text-slate-900">{t('distribution.copy.mode')}</legend>
          {(
            [
              ['REPLACE', 'replace'],
              ['FILL', 'fill'],
            ] as const
          ).map(([value, key]) => (
            <label key={value} className="flex min-h-11 cursor-pointer items-start gap-3 py-1">
              <input
                type="radio"
                name={modeName}
                value={value}
                checked={mode === value}
                onChange={() => setMode(value)}
                className="mt-0.5 size-5 shrink-0 accent-brand-600"
              />
              <span className="flex flex-col">
                <span className="font-medium text-slate-900">{t(`distribution.copy.${key}`)}</span>
                <span className="text-xs text-slate-600">{t(`distribution.copy.${key}Hint`)}</span>
              </span>
            </label>
          ))}
        </fieldset>
        {message && <Alert tone="error">{message}</Alert>}
        <ErrorAlert error={error} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? t('distribution.copy.copying') : t('distribution.copy.confirm')}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
