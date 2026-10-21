import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Day } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { ErrorAlert, Field, Input } from '../../../shared/ui'
import { Dialog, DialogActions } from '../shared/ui'
import { useExtendCutoff } from './api'
import { CUTOFF_KEYS, type DayRegion, EXTENDABLE_CUTOFFS, type ExtendableCutoff } from './cutoffs'
import { TIME_PATTERN } from './dates'

type FormValues = { kind: ExtendableCutoff; until: string }

const FIELDS = ['kind', 'until'] as const

// Libellés des heures limites : répartition et commande (lot 3), validation (lot 6, I-98).
const KIND_LABELS = {
  DISTRIBUTION: 'backoffice.days.extend.kinds.DISTRIBUTION',
  ORDERING: 'backoffice.days.extend.kinds.ORDERING',
  VALIDATION: 'backoffice.monitoring.days.kinds.VALIDATION',
} as const satisfies Record<ExtendableCutoff, string>

type Props = {
  day: Day
  /** Région à prolonger ; null : boîte fermée. */
  regionDay: DayRegion | null
  onClose: () => void
  onDone: (regionDay: DayRegion, kind: ExtendableCutoff, until: string) => void
}

/**
 * Prolongation de l'heure limite de répartition, de commande ou de validation
 * pour une région (RG-A05, I-98), avec day.extend-cutoff (ADV et Admin). Le serveur
 * vérifie que l'heure est à venir, après l'heure actuelle et avant la
 * clôture ; ses refus s'affichent sur le champ.
 */
export function ExtendCutoffDialog({ day, regionDay, onClose, onDone }: Props) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={regionDay !== null}
      title={regionDay ? t('backoffice.days.extend.title', { name: regionDay.region.name }) : ''}
      onClose={onClose}
    >
      {regionDay && <ExtendForm day={day} regionDay={regionDay} onCancel={onClose} onDone={onDone} />}
    </Dialog>
  )
}

function ExtendForm({ day, regionDay, onCancel, onDone }: { day: Day; regionDay: DayRegion; onCancel: () => void; onDone: Props['onDone'] }) {
  const { t } = useTranslation()
  const extend = useExtendCutoff(day.day)
  const schema = useMemo(
    () => z.object({ kind: z.enum(EXTENDABLE_CUTOFFS), until: z.string().trim().regex(TIME_PATTERN, t('backoffice.days.extend.untilRequired')) }),
    [t],
  )
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { kind: 'DISTRIBUTION', until: '' } })

  const submit = handleSubmit(async (values) => {
    try {
      const updated = await extend.mutateAsync({ regionId: regionDay.region.id, kind: values.kind, until: values.until })
      onDone(updated, values.kind, values.until)
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <p className="text-sm text-slate-600">{t('backoffice.days.extend.intro')}</p>
      {!hasFieldErrors && <ErrorAlert error={extend.error} />}

      <fieldset className="flex flex-col gap-1">
        <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.days.extend.kind')}</legend>
        <div className="grid gap-2 sm:grid-cols-3">
          {EXTENDABLE_CUTOFFS.map((kind) => (
            <label
              key={kind}
              className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
            >
              <input type="radio" value={kind} {...register('kind')} className="size-5 shrink-0 accent-brand-600" />
              <span>
                <span className="block font-semibold text-slate-900">{t(KIND_LABELS[kind])}</span>
                <span className="block text-xs text-slate-600">
                  {t('backoffice.days.extend.current', { time: regionDay.cutoffs[CUTOFF_KEYS[kind]] })}
                </span>
              </span>
            </label>
          ))}
        </div>
        {errors.kind?.message && (
          <p role="alert" className="text-sm text-red-700">
            {errors.kind.message}
          </p>
        )}
      </fieldset>

      <Field
        label={t('backoffice.days.extend.until')}
        hint={t('backoffice.days.extend.untilHint', { closing: day.cutoffs.closing })}
        error={errors.until?.message}
      >
        {(props) => <Input {...props} {...register('until')} type="time" step={60} className="tabular-nums" />}
      </Field>

      <DialogActions submitting={extend.isPending} onCancel={onCancel} submitLabel={t('backoffice.days.extend.submit')} />
    </form>
  )
}
