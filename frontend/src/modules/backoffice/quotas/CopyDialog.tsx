import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { ApiError } from '../../../shared/api/client'
import { COPY_MODES, type CopyMode, type QuotaMatrixRegion } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, ErrorAlert, Field, Input } from '../../../shared/ui'
import { Dialog, DialogActions } from '../shared/ui'
import { useCopyQuotas } from './api'

function makeSchema(t: TFunction, day: string) {
  return z.object({
    source_day: z
      .string()
      .regex(/^\d{4}-\d{2}-\d{2}$/, t('backoffice.quotas.copy.sourceRequired'))
      .refine((value) => value !== day, t('backoffice.quotas.copy.sourceSame')),
    mode: z.enum(COPY_MODES),
  })
}

type FormValues = { source_day: string; mode: CopyMode }

type Props = {
  day: string
  regions: QuotaMatrixRegion[]
  /** Enregistre les saisies en attente avant la copie. */
  flush: () => Promise<void>
  onDone: (changed: number, sourceDay: string) => void
  onClose: () => void
}

/**
 * « Copier depuis la journée… » (EF-ADV-02) : les quotas publiés d'une
 * autre journée deviennent des brouillons de celle-ci, pour toutes les
 * régions ou une sélection. Remplacer : chaque cellule prend la valeur
 * source ; Compléter : seules les cellules à 0 sont renseignées.
 */
export function CopyDialog({ day, regions, flush, onDone, onClose }: Props) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t, day), [t, day])
  const copy = useCopyQuotas(day)
  const [selected, setSelected] = useState<ReadonlySet<number>>(new Set())
  const [message, setMessage] = useState<string | null>(null)
  const [regionError, setRegionError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { source_day: '', mode: 'FILL' } })

  const toggle = (regionId: number, checked: boolean) =>
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(regionId)
      else next.delete(regionId)
      return next
    })

  const submit = handleSubmit(async ({ source_day, mode }) => {
    setMessage(null)
    setRegionError(null)
    try {
      await flush()
    } catch (flushError) {
      setMessage(flushError instanceof ApiError ? (flushError.problem.detail ?? t('backoffice.quotas.flushFailed')) : t('backoffice.quotas.flushFailed'))
      return
    }
    const regionIds = regions.filter((region) => selected.has(region.id)).map((region) => region.id)
    try {
      const result = await copy.mutateAsync({ source_day, mode, ...(regionIds.length > 0 ? { region_ids: regionIds } : {}) })
      onDone(result.changed, source_day)
    } catch (copyError) {
      if (!applyServerErrors(copyError, setError, ['source_day', 'mode']) && copyError instanceof ApiError) {
        const regionMessage = Object.entries(copyError.fieldErrors).find(([field]) => field.startsWith('region_ids'))?.[1][0]
        if (regionMessage) setRegionError(regionMessage)
      }
    }
  })

  const fieldError = Boolean(errors.source_day || errors.mode || regionError)

  return (
    <Dialog open title={t('backoffice.quotas.copy.title')} onClose={onClose} size="lg">
      <form onSubmit={submit} noValidate className="flex flex-col gap-4 text-sm text-slate-700">
        <p>{t('backoffice.quotas.copy.intro')}</p>
        {message && <Alert tone="error">{message}</Alert>}
        {!fieldError && <ErrorAlert error={copy.error} />}

        <Field label={t('backoffice.quotas.copy.source')} hint={t('backoffice.quotas.copy.sourceHint')} error={errors.source_day?.message}>
          {(props) => <Input {...props} {...register('source_day')} type="date" required className="sm:max-w-56" />}
        </Field>

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 font-semibold text-slate-900">{t('backoffice.quotas.copy.mode')}</legend>
          {(
            [
              ['REPLACE', 'replace'],
              ['FILL', 'fill'],
            ] as const
          ).map(([value, key]) => (
            <label key={value} className="flex min-h-11 cursor-pointer items-start gap-3 py-1">
              <input type="radio" value={value} {...register('mode')} className="mt-0.5 size-5 shrink-0 accent-brand-600" />
              <span className="flex flex-col">
                <span className="font-medium text-slate-900">{t(`backoffice.quotas.copy.${key}`)}</span>
                <span className="text-xs text-slate-600">{t(`backoffice.quotas.copy.${key}Hint`)}</span>
              </span>
            </label>
          ))}
          {errors.mode?.message && (
            <p role="alert" className="text-sm text-red-700">
              {errors.mode.message}
            </p>
          )}
        </fieldset>

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 font-semibold text-slate-900">{t('backoffice.quotas.copy.regions')}</legend>
          <p className="text-xs text-slate-600">{t('backoffice.quotas.copy.regionsHint')}</p>
          <div className="grid gap-x-4 sm:grid-cols-2">
            {regions.map((region) => (
              <label key={region.id} className="flex min-h-11 cursor-pointer items-center gap-3">
                <input
                  type="checkbox"
                  checked={selected.has(region.id)}
                  disabled={region.status === 'CLOSED'}
                  onChange={(event) => toggle(region.id, event.target.checked)}
                  className="size-5 shrink-0 rounded border-slate-300 accent-brand-600"
                />
                {`${region.code} — ${region.name}`}
              </label>
            ))}
          </div>
          {regionError && (
            <p role="alert" className="text-sm text-red-700">
              {regionError}
            </p>
          )}
        </fieldset>

        <DialogActions submitting={isSubmitting} onCancel={onClose} submitLabel={t('backoffice.quotas.copy.confirm')} />
      </form>
    </Dialog>
  )
}
