import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Day, DayCutoffs } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, ErrorAlert, Field, Input } from '../../../shared/ui'
import type { DayScheduleUpdate } from './api'
import { DAY_PATTERN, TIME_PATTERN } from './dates'

/** Jalons dans l'ordre chronologique imposé (§4.3). */
const CUTOFF_KEYS = ['distribution', 'ordering', 'validation', 'closing'] as const satisfies ReadonlyArray<keyof DayCutoffs>

type FormValues = { cutoffs: DayCutoffs; delivery_date: string }

const FIELDS = ['cutoffs.distribution', 'cutoffs.ordering', 'cutoffs.validation', 'cutoffs.closing', 'delivery_date'] as const

function makeSchema(t: TFunction, day: string) {
  const time = z.string().trim().regex(TIME_PATTERN, t('backoffice.days.cutoffs.format'))
  return z.object({
    cutoffs: z
      .object({ distribution: time, ordering: time, validation: time, closing: time })
      // Ordre strict répartition < commande < validation < clôture ; le serveur contrôle aussi la clôture à venir.
      .superRefine((cutoffs, ctx) => {
        CUTOFF_KEYS.slice(1).forEach((key, index) => {
          const previous = CUTOFF_KEYS[index]
          if (previous && cutoffs[key] <= cutoffs[previous]) {
            ctx.addIssue({ code: 'custom', path: [key], message: t('backoffice.days.cutoffs.order') })
          }
        })
      }),
    delivery_date: z
      .string()
      .regex(DAY_PATTERN, t('backoffice.days.cutoffs.deliveryFormat'))
      .refine((value) => value >= day, t('backoffice.days.cutoffs.deliveryBefore', { day: formatDate(day) })),
  })
}

type Props = {
  day: Day
  submitting: boolean
  error: unknown
  onSubmit: (input: DayScheduleUpdate) => Promise<unknown>
}

/**
 * Heures limites et date de livraison d'une journée ouverte (§4.3), avec
 * day.manage. Seules les valeurs changées sont envoyées ; les refus du
 * serveur (ordre, clôture passée, prolongation au-delà) s'affichent sur
 * le champ concerné.
 */
export function ScheduleForm({ day, submitting, error, onSubmit }: Props) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t, day.day), [t, day.day])
  const [unchanged, setUnchanged] = useState(false)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { cutoffs: { ...day.cutoffs }, delivery_date: day.delivery_date },
  })

  const submit = handleSubmit(async (values) => {
    setUnchanged(false)
    const changed = CUTOFF_KEYS.filter((key) => values.cutoffs[key] !== day.cutoffs[key])
    const input: DayScheduleUpdate = {}
    if (changed.length > 0) input.cutoffs = Object.fromEntries(changed.map((key) => [key, values.cutoffs[key]]))
    if (values.delivery_date !== day.delivery_date) input.delivery_date = values.delivery_date
    if (Object.keys(input).length === 0) {
      setUnchanged(true)
      return
    }

    try {
      await onSubmit(input)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  const fieldErrors = errors.cutoffs ?? errors.delivery_date
  const hints = {
    distribution: t('backoffice.days.cutoffs.distributionHint'),
    ordering: t('backoffice.days.cutoffs.orderingHint'),
    validation: t('backoffice.days.cutoffs.validationHint'),
    closing: t('backoffice.days.cutoffs.closingHint'),
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <p className="text-sm text-slate-600">{t('backoffice.days.cutoffs.hint')}</p>
      {unchanged && <Alert>{t('backoffice.days.cutoffs.unchanged')}</Alert>}
      {!fieldErrors && <ErrorAlert error={error} />}

      <div className="grid gap-4 sm:grid-cols-2">
        {CUTOFF_KEYS.map((key) => (
          <Field key={key} label={t(`backoffice.days.cutoffs.${key}`)} hint={hints[key]} error={errors.cutoffs?.[key]?.message}>
            {(props) => <Input {...props} {...register(`cutoffs.${key}`)} type="time" step={60} className="tabular-nums" />}
          </Field>
        ))}
        <Field label={t('backoffice.days.detail.delivery')} hint={t('backoffice.days.cutoffs.deliveryHint')} error={errors.delivery_date?.message}>
          {(props) => <Input {...props} {...register('delivery_date')} type="date" min={day.day} />}
        </Field>
      </div>

      <Button type="submit" className="self-start" disabled={submitting}>
        {submitting ? t('common.saving') : t('common.save')}
      </Button>
    </form>
  )
}
