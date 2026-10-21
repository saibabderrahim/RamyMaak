import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useEffect, useMemo } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { REASON_TYPES, type ReasonCode, type ReasonType } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Checkbox, ErrorAlert, Field, Input, Select } from '../../../shared/ui'
import { DialogActions } from '../shared/ui'
import { useAdminReasons } from './api'
import { canRequireComment, CODE_PATTERN, LABEL_MAX, nextSortOrder, type ReasonValues, SORT_ORDER_MAX } from './model'

function makeSchema(t: TFunction, editing: boolean) {
  return z.object({
    type: z.enum(REASON_TYPES),
    // Le code ne se saisit qu'à la création : il n'est jamais modifiable ensuite.
    code: editing
      ? z.string()
      : z
          .string()
          .trim()
          .toUpperCase()
          .regex(CODE_PATTERN, t('backoffice.reasons.validation.code')),
    label: z
      .string()
      .trim()
      .min(1, t('backoffice.reasons.validation.label'))
      .max(LABEL_MAX, t('backoffice.reasons.validation.labelMax')),
    requires_comment: z.boolean(),
    sort_order: z
      .string()
      .trim()
      .regex(/^\d{1,5}$/, t('backoffice.reasons.validation.sortOrder'))
      .transform(Number)
      .pipe(z.number().max(SORT_ORDER_MAX, t('backoffice.reasons.validation.sortOrder'))),
  })
}

type FormInput = z.input<ReturnType<typeof makeSchema>>

const FIELDS = ['type', 'code', 'label', 'requires_comment', 'sort_order'] as const

type Props = {
  /** Motif à modifier ; absent : création. */
  reason?: ReasonCode
  /** Type proposé à la création (celui de l'onglet). */
  initialType: ReasonType
  submitting: boolean
  error: unknown
  onSubmit: (values: ReasonValues) => Promise<unknown>
  onCancel: () => void
}

/**
 * Formulaire d'un motif (EF-ADM-05) : type, code, libellé, commentaire
 * obligatoire, ordre. Le type et le code ne se choisissent qu'à la création ;
 * un écart d'expédition n'a pas de commentaire (I-95). Les refus 422 du
 * serveur (code ou libellé déjà pris, format) sont reportés sur les champs.
 */
export function ReasonForm({ reason, initialType, submitting, error, onSubmit, onCancel }: Props) {
  const { t } = useTranslation()
  const editing = reason !== undefined
  const schema = useMemo(() => makeSchema(t, editing), [t, editing])
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    control,
    formState: { errors, dirtyFields },
  } = useForm<FormInput, unknown, ReasonValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      type: reason?.type ?? initialType,
      code: reason?.code ?? '',
      label: reason?.label ?? '',
      requires_comment: reason?.requires_comment ?? false,
      sort_order: reason ? String(reason.sort_order) : '',
    },
  })

  // Ordre proposé à la création : à la suite des motifs du type choisi, tant que l'Admin n'a pas saisi le sien.
  const type = useWatch({ control, name: 'type' })
  const siblings = useAdminReasons(type, !editing)
  const proposed = siblings.data ? nextSortOrder(siblings.data) : undefined
  const sortEdited = Boolean(dirtyFields.sort_order)
  useEffect(() => {
    if (!editing && proposed !== undefined && !sortEdited) setValue('sort_order', String(proposed))
  }, [editing, proposed, sortEdited, setValue])

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {Object.keys(errors).length === 0 && <ErrorAlert error={error} />}
      {editing && <p className="text-sm text-slate-600">{t('backoffice.reasons.fields.codeLocked')}</p>}
      <Field label={t('backoffice.reasons.fields.type')} error={errors.type?.message}>
        {(props) =>
          reason ? (
            <Input {...props} value={t(`backoffice.reasons.types.${reason.type}`)} readOnly className="bg-slate-100" />
          ) : (
            <Select {...props} {...register('type')}>
              {REASON_TYPES.map((option) => (
                <option key={option} value={option}>
                  {t(`backoffice.reasons.types.${option}`)}
                </option>
              ))}
            </Select>
          )
        }
      </Field>
      <Field label={t('backoffice.reasons.fields.code')} hint={editing ? undefined : t('backoffice.reasons.fields.codeHint')} error={errors.code?.message}>
        {(props) => (
          <Input
            {...props}
            {...register('code')}
            readOnly={editing}
            className={editing ? 'bg-slate-100 font-mono' : 'font-mono uppercase'}
            maxLength={30}
            autoCapitalize="characters"
            spellCheck={false}
            data-autofocus={editing ? undefined : ''}
          />
        )}
      </Field>
      <Field label={t('backoffice.reasons.fields.label')} hint={t('backoffice.reasons.fields.labelHint')} error={errors.label?.message}>
        {(props) => <Input {...props} {...register('label')} maxLength={LABEL_MAX} data-autofocus={editing ? '' : undefined} />}
      </Field>
      {canRequireComment(type) ? (
        <Checkbox label={t('backoffice.reasons.fields.requiresComment')} {...register('requires_comment')} />
      ) : (
        <p className="text-sm text-slate-600">{t('backoffice.reasons.fields.noComment')}</p>
      )}
      {errors.requires_comment?.message && (
        <p role="alert" className="text-sm text-red-700">
          {errors.requires_comment.message}
        </p>
      )}
      <Field label={t('backoffice.reasons.fields.sortOrder')} hint={t('backoffice.reasons.fields.sortOrderHint')} error={errors.sort_order?.message}>
        {(props) => <Input {...props} {...register('sort_order')} inputMode="numeric" />}
      </Field>
      <DialogActions submitting={submitting} onCancel={onCancel} />
    </form>
  )
}
