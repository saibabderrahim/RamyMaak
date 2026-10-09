import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useId, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Depot, Region } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Button, Checkbox, ErrorAlert, Field, Input, Select } from '../../../shared/ui'
import { DialogActions } from '../shared/ui'
import type { RegionInput } from './api'

function makeSchema(t: TFunction) {
  return z.object({
    // Code des fichiers Excel (Annexe B.3) : enregistré en majuscules.
    code: z
      .string()
      .trim()
      .toUpperCase()
      .regex(/^[A-Z0-9_-]{2,20}$/, t('backoffice.regions.codeFormat')),
    name: z.string().trim().min(1).max(255),
    depot_id: z.string().transform((value) => (value === '' ? null : Number(value))),
    is_active: z.boolean(),
  })
}

type Schema = ReturnType<typeof makeSchema>
type FormInput = z.input<Schema>

const FIELDS = ['code', 'name', 'depot_id', 'is_active'] as const

type Props = {
  region?: Region
  depots: Depot[] | undefined
  submitting: boolean
  error: unknown
  onSubmit: (values: RegionInput) => Promise<unknown>
  /** En boîte de dialogue : bouton Annuler à côté d'Enregistrer. */
  onCancel?: () => void
}

/**
 * Région (EF-ADM-03) : code saisi à la création seulement (clé des fichiers
 * Excel), nom, dépôt d'expédition (option), statut.
 */
export function RegionForm({ region, depots, submitting, error, onSubmit, onCancel }: Props) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t), [t])
  const activeHintId = useId()
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormInput, unknown, RegionInput>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: region?.code ?? '',
      name: region?.name ?? '',
      depot_id: region?.depot ? String(region.depot.id) : '',
      is_active: region?.is_active ?? true,
    },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  // Dépôts actifs, plus le dépôt actuel s'il a été désactivé depuis.
  const options = (depots ?? []).filter((depot) => depot.is_active || depot.id === region?.depot?.id)
  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {!hasFieldErrors && <ErrorAlert error={error} />}

      <Field
        label={t('backoffice.regions.fields.code')}
        hint={region ? t('backoffice.regions.fields.codeLocked') : t('backoffice.regions.fields.codeHint')}
        error={errors.code?.message}
      >
        {(props) => (
          <Input
            {...props}
            {...register('code')}
            readOnly={Boolean(region)}
            className={region ? 'bg-slate-100' : 'uppercase'}
            autoCapitalize="characters"
            spellCheck={false}
          />
        )}
      </Field>
      <Field label={t('backoffice.regions.fields.name')} error={errors.name?.message}>
        {(props) => <Input {...props} {...register('name')} />}
      </Field>
      <Field label={t('backoffice.regions.fields.depot')} error={errors.depot_id?.message}>
        {(props) => (
          <Select {...props} {...register('depot_id')}>
            <option value="">{t('backoffice.regions.fields.noDepot')}</option>
            {region?.depot && !options.some((depot) => depot.id === region.depot?.id) && (
              <option value={region.depot.id}>{`${region.depot.code} — ${region.depot.name}`}</option>
            )}
            {options.map((depot) => (
              <option key={depot.id} value={depot.id}>
                {depot.is_active
                  ? `${depot.code} — ${depot.name}`
                  : t('backoffice.regions.fields.inactiveDepot', { name: `${depot.code} — ${depot.name}` })}
              </option>
            ))}
          </Select>
        )}
      </Field>
      <div>
        <Checkbox label={t('backoffice.regions.fields.active')} {...register('is_active')} aria-describedby={activeHintId} />
        <p id={activeHintId} className="text-xs text-slate-500">
          {t('backoffice.regions.fields.activeHint')}
        </p>
        {errors.is_active?.message && (
          <p role="alert" className="mt-1 text-sm text-red-700">
            {errors.is_active.message}
          </p>
        )}
      </div>

      {onCancel ? (
        <DialogActions submitting={submitting} onCancel={onCancel} />
      ) : (
        <Button type="submit" className="self-start" disabled={submitting}>
          {submitting ? t('common.saving') : t('common.save')}
        </Button>
      )}
    </form>
  )
}
