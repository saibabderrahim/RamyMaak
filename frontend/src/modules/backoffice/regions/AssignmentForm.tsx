import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { ASSIGNMENT_KINDS, type Assignment } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { businessToday, formatDate } from '../../../shared/format'
import { Button, ErrorAlert, Field, Input, Select } from '../../../shared/ui'
import { ConfirmDialog } from '../../../shared/ui/ConfirmDialog'
import { type AssignmentInput, useAssignableUsers, useCreateAssignment } from './api'
import { overlappingTitulars } from './assignments'

function makeSchema(t: TFunction, today: string) {
  return z
    .object({
      kind: z.enum(ASSIGNMENT_KINDS),
      user_id: z
        .string()
        .min(1, t('backoffice.regions.assignments.userRequired'))
        .transform((value) => Number(value)),
      // Une affectation se crée aujourd'hui ou plus tard.
      starts_on: z
        .string()
        .min(1, t('backoffice.regions.assignments.startsRequired'))
        .refine((value) => value >= today, t('backoffice.regions.assignments.startsInPast')),
      ends_on: z.string().transform((value) => (value === '' ? null : value)),
    })
    .superRefine((values, context) => {
      // Une suppléance couvre une période bornée (§3.4).
      if (values.kind === 'DEPUTY' && values.ends_on === null) {
        context.addIssue({ code: 'custom', path: ['ends_on'], message: t('backoffice.regions.assignments.deputyNeedsEnd') })
      } else if (values.ends_on !== null && values.ends_on < values.starts_on) {
        context.addIssue({ code: 'custom', path: ['ends_on'], message: t('backoffice.regions.assignments.endsBeforeStart') })
      }
    })
}

type FormInput = z.input<ReturnType<typeof makeSchema>>
type FormOutput = Omit<AssignmentInput, 'replace'>

const FIELDS = ['kind', 'user_id', 'starts_on', 'ends_on'] as const

type Props = {
  regionId: number
  /** Historique des affectations de la région, pour détecter un titulaire en place. */
  history: readonly Assignment[]
  onCreated: (assignment: Assignment) => void
}

/**
 * Nouvelle affectation (§3.3, §3.4) : titulaire, suppléant (période
 * obligatoire) ou ADV. Un seul titulaire à la fois (H-02) : remplacer le
 * titulaire en place exige une confirmation explicite.
 */
export function AssignmentForm({ regionId, history, onCreated }: Props) {
  const { t } = useTranslation()
  const today = businessToday()
  const schema = useMemo(() => makeSchema(t, today), [t, today])
  const createAssignment = useCreateAssignment(regionId)
  const [replacing, setReplacing] = useState<{ values: FormOutput; titulars: Assignment[]; userName: string } | null>(null)

  const defaults: FormInput = { kind: 'TITULAR', user_id: '', starts_on: today, ends_on: '' }
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<FormInput, unknown, FormOutput>({ resolver: zodResolver(schema), defaultValues: defaults })

  const kind = useWatch({ control, name: 'kind' })
  const role = kind === 'ADV' ? 'adv' : 'regional'
  const users = useAssignableUsers(role)

  const save = async (values: FormOutput, replace: boolean) => {
    try {
      const assignment = await createAssignment.mutateAsync(replace ? { ...values, replace: true } : values)
      reset({ ...defaults, kind: values.kind })
      onCreated(assignment)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  }

  const submit = handleSubmit(async (values) => {
    const titulars = values.kind === 'TITULAR' ? overlappingTitulars(history, values.starts_on, values.ends_on) : []
    if (titulars.length > 0) {
      const userName = users.data?.find((user) => user.id === values.user_id)?.name ?? ''
      setReplacing({ values, titulars, userName })
      return
    }
    await save(values, false)
  })

  const confirmReplace = async () => {
    if (!replacing) return
    const { values } = replacing
    setReplacing(null)
    await save(values, true)
  }

  const hasFieldErrors = Object.keys(errors).length > 0
  const submitting = isSubmitting || createAssignment.isPending

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {!hasFieldErrors && <ErrorAlert error={createAssignment.error} />}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('backoffice.regions.assignments.kind')} error={errors.kind?.message}>
          {(props) => (
            <Select {...props} {...register('kind', { onChange: () => setValue('user_id', '') })}>
              {ASSIGNMENT_KINDS.map((value) => (
                <option key={value} value={value}>
                  {t(`backoffice.regions.assignments.kinds.${value}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field
          label={t('backoffice.regions.assignments.user')}
          hint={
            users.data?.length === 0
              ? t('backoffice.regions.assignments.noUsers')
              : role === 'adv'
                ? t('backoffice.regions.assignments.userHintAdv')
                : t('backoffice.regions.assignments.userHintRegional')
          }
          error={errors.user_id?.message}
        >
          {(props) => (
            <Select {...props} {...register('user_id')} aria-busy={users.isPending}>
              <option value="">{users.isPending ? t('common.loading') : t('backoffice.regions.assignments.chooseUser')}</option>
              {users.data?.map((user) => (
                <option key={user.id} value={user.id}>
                  {`${user.name} (${user.username})`}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.regions.assignments.startsOn')} error={errors.starts_on?.message}>
          {(props) => <Input {...props} {...register('starts_on')} type="date" min={today} />}
        </Field>
        <Field
          label={t('backoffice.regions.assignments.endsOn')}
          hint={kind === 'DEPUTY' ? t('backoffice.regions.assignments.endsOnRequiredHint') : t('backoffice.regions.assignments.endsOnOptionalHint')}
          error={errors.ends_on?.message}
        >
          {(props) => <Input {...props} {...register('ends_on')} type="date" min={today} required={kind === 'DEPUTY'} />}
        </Field>
      </div>
      <ErrorAlert error={users.error} />

      <Button type="submit" className="self-start" disabled={submitting}>
        {submitting ? t('common.saving') : t('backoffice.regions.assignments.submit')}
      </Button>

      <ConfirmDialog
        open={replacing !== null}
        title={t('backoffice.regions.assignments.replaceTitle')}
        confirmLabel={t('backoffice.regions.assignments.replaceConfirm')}
        tone="danger"
        onCancel={() => setReplacing(null)}
        onConfirm={() => void confirmReplace()}
      >
        {replacing && (
          <>
            <p>
              {t('backoffice.regions.assignments.replaceIntro', {
                count: replacing.titulars.length,
                names: replacing.titulars.map((titular) => titular.user.name).join(', '),
              })}
            </p>
            <p>
              {t('backoffice.regions.assignments.replaceEffect', {
                count: replacing.titulars.length,
                name: replacing.userName,
                date: formatDate(replacing.values.starts_on),
              })}
            </p>
          </>
        )}
      </ConfirmDialog>
    </form>
  )
}
