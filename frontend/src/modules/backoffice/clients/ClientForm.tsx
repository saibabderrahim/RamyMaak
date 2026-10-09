import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Client, ClientCategory, Region } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { formatDate } from '../../../shared/format'
import { Alert, Button, ErrorAlert, Field, Input, Select } from '../../../shared/ui'
import { nextBusinessDay } from '../shared/dates'
import { FormSection } from '../shared/ui'
import type { ClientInput } from './api'
import { targetRegionId } from './regionChange'

const optionalText = (max: number) =>
  z
    .string()
    .trim()
    .max(max)
    .transform((value) => (value === '' ? null : value))

/** Coordonnée en degrés décimaux ; la virgule décimale est acceptée. */
const coordinate = (min: number, max: number) =>
  z
    .string()
    .trim()
    .transform((value) => (value === '' ? null : Number(value.replace(',', '.'))))
    .pipe(z.number().min(min).max(max).nullable())

function makeSchema(t: TFunction) {
  return z
    .object({
      // Code ERP, clé des fichiers Excel : enregistré en majuscules.
      code: z
        .string()
        .trim()
        .regex(/^[A-Za-z0-9_-]{1,30}$/, t('backoffice.clients.codeFormat'))
        .transform((value) => value.toUpperCase()),
      name: z.string().trim().min(1).max(255),
      trade_name: optionalText(255),
      category_id: z.string().transform((value) => (value === '' ? null : Number(value))),
      region_id: z
        .string()
        .min(1, t('backoffice.clients.regionRequired'))
        .transform((value) => Number(value)),
      address: optionalText(255),
      wilaya: optionalText(100),
      commune: optionalText(100),
      lat: coordinate(-90, 90),
      lng: coordinate(-180, 180),
      phone: optionalText(30),
      phone2: optionalText(30),
      email: z
        .string()
        .trim()
        .transform((value) => (value === '' ? null : value))
        .pipe(z.email().nullable()),
      nif: optionalText(30),
      nis: optionalText(30),
      rc: optionalText(30),
      ai: optionalText(30),
    })
    .superRefine((values, context) => {
      // Coordonnées GPS : les deux ou aucune.
      if ((values.lat === null) !== (values.lng === null)) {
        context.addIssue({ code: 'custom', path: [values.lat === null ? 'lat' : 'lng'], message: t('backoffice.clients.coordinatesPair') })
      }
    })
}

type FormInput = z.input<ReturnType<typeof makeSchema>>

const FIELDS = [
  'code',
  'name',
  'trade_name',
  'category_id',
  'region_id',
  'address',
  'wilaya',
  'commune',
  'lat',
  'lng',
  'phone',
  'phone2',
  'email',
  'nif',
  'nis',
  'rc',
  'ai',
] as const

const text = (value: string | null | undefined) => value ?? ''

type Props = {
  client?: Client
  regions: Region[] | undefined
  categories: ClientCategory[] | undefined
  submitting: boolean
  error: unknown
  onSubmit: (values: ClientInput) => Promise<unknown>
  onCancel?: () => void
}

/**
 * Fiche client (EF-CLI-01). Le code n'est saisi qu'à la création. En
 * modification, un changement de région prend effet à la journée suivante
 * (RG-S04) : le formulaire l'annonce avant l'enregistrement.
 */
export function ClientForm({ client, regions, categories, submitting, error, onSubmit, onCancel }: Props) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t), [t])
  const {
    register,
    handleSubmit,
    setError,
    control,
    formState: { errors },
  } = useForm<FormInput, unknown, ClientInput>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: text(client?.code),
      name: text(client?.name),
      trade_name: text(client?.trade_name),
      category_id: client?.category ? String(client.category.id) : '',
      region_id: client ? String(targetRegionId(client)) : '',
      address: text(client?.address),
      wilaya: text(client?.wilaya),
      commune: text(client?.commune),
      lat: client?.lat === null || client?.lat === undefined ? '' : String(client.lat),
      lng: client?.lng === null || client?.lng === undefined ? '' : String(client.lng),
      phone: text(client?.phone),
      phone2: text(client?.phone2),
      email: text(client?.email),
      nif: text(client?.nif),
      nis: text(client?.nis),
      rc: text(client?.rc),
      ai: text(client?.ai),
    },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  const selectedRegion = useWatch({ control, name: 'region_id' })
  const regionNotice = client ? regionChangeNotice(client, Number(selectedRegion), t) : null

  // Régions actives, plus celles du client (actuelle, programmée) même désactivées depuis.
  const kept = client ? [client.region.id, client.pending_region_change?.region.id] : []
  const regionOptions = (regions ?? []).filter((region) => region.is_active || kept.includes(region.id))
  if (client && !regionOptions.some((region) => region.id === client.region.id)) {
    regionOptions.unshift({ ...client.region, is_active: false, depot: null, titular: null, deputies: [], clients_count: 0 })
  }
  const pendingRegion = client?.pending_region_change?.region
  if (pendingRegion && !regionOptions.some((region) => region.id === pendingRegion.id)) {
    regionOptions.push({ ...pendingRegion, is_active: false, depot: null, titular: null, deputies: [], clients_count: 0 })
  }

  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      {!hasFieldErrors && <ErrorAlert error={error} />}

      <FormSection title={t('backoffice.clients.sections.identity')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('backoffice.clients.fields.code')} hint={client ? undefined : t('backoffice.clients.fields.codeHint')} error={errors.code?.message}>
            {(props) => (
              <Input
                {...props}
                {...register('code')}
                readOnly={Boolean(client)}
                className={client ? 'bg-slate-100' : 'uppercase'}
                autoCapitalize="characters"
                spellCheck={false}
              />
            )}
          </Field>
          <Field label={t('backoffice.clients.fields.name')} error={errors.name?.message}>
            {(props) => <Input {...props} {...register('name')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.tradeName')} error={errors.trade_name?.message}>
            {(props) => <Input {...props} {...register('trade_name')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.category')} error={errors.category_id?.message}>
            {(props) => (
              <Select {...props} {...register('category_id')}>
                <option value="">{t('backoffice.clients.fields.noCategory')}</option>
                {client?.category && !categories?.some((category) => category.id === client.category?.id) && (
                  <option value={client.category.id}>{client.category.name}</option>
                )}
                {categories?.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
      </FormSection>

      <FormSection title={t('backoffice.clients.sections.location')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('backoffice.clients.fields.region')} error={errors.region_id?.message}>
            {(props) => (
              <Select {...props} {...register('region_id')}>
                <option value="">{t('backoffice.clients.fields.chooseRegion')}</option>
                {regionOptions.map((region) => (
                  <option key={region.id} value={region.id}>
                    {region.is_active
                      ? `${region.code} — ${region.name}`
                      : t('backoffice.clients.fields.inactiveRegion', { name: `${region.code} — ${region.name}` })}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <div className="sm:pt-6">
            {regionNotice && (
              <Alert tone="warning" className="text-sm">
                {regionNotice}
              </Alert>
            )}
          </div>
          <Field label={t('backoffice.clients.fields.address')} error={errors.address?.message}>
            {(props) => <Input {...props} {...register('address')} autoComplete="off" />}
          </Field>
          <Field label={t('backoffice.clients.fields.wilaya')} error={errors.wilaya?.message}>
            {(props) => <Input {...props} {...register('wilaya')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.commune')} error={errors.commune?.message}>
            {(props) => <Input {...props} {...register('commune')} />}
          </Field>
          <div className="hidden sm:block" />
          <Field label={t('backoffice.clients.fields.lat')} hint={t('backoffice.clients.fields.coordinatesHint')} error={errors.lat?.message}>
            {(props) => <Input {...props} {...register('lat')} inputMode="decimal" />}
          </Field>
          <Field label={t('backoffice.clients.fields.lng')} error={errors.lng?.message}>
            {(props) => <Input {...props} {...register('lng')} inputMode="decimal" />}
          </Field>
        </div>
      </FormSection>

      <FormSection title={t('backoffice.clients.sections.contact')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('backoffice.clients.fields.phone')} error={errors.phone?.message}>
            {(props) => <Input {...props} {...register('phone')} type="tel" />}
          </Field>
          <Field label={t('backoffice.clients.fields.phone2')} error={errors.phone2?.message}>
            {(props) => <Input {...props} {...register('phone2')} type="tel" />}
          </Field>
          <Field label={t('backoffice.clients.fields.email')} error={errors.email?.message}>
            {(props) => <Input {...props} {...register('email')} type="email" autoComplete="off" />}
          </Field>
        </div>
      </FormSection>

      <FormSection title={t('backoffice.clients.sections.tax')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('backoffice.clients.fields.nif')} error={errors.nif?.message}>
            {(props) => <Input {...props} {...register('nif')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.nis')} error={errors.nis?.message}>
            {(props) => <Input {...props} {...register('nis')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.rc')} error={errors.rc?.message}>
            {(props) => <Input {...props} {...register('rc')} />}
          </Field>
          <Field label={t('backoffice.clients.fields.ai')} error={errors.ai?.message}>
            {(props) => <Input {...props} {...register('ai')} />}
          </Field>
        </div>
      </FormSection>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={submitting}>
          {submitting ? t('common.saving') : t('common.save')}
        </Button>
        {onCancel && (
          <Button variant="secondary" onClick={onCancel}>
            {t('common.cancel')}
          </Button>
        )}
      </div>
    </form>
  )
}

/**
 * Annonce de l'effet d'un changement de région (RG-S04) : journée suivante,
 * date d'effet déjà programmée, ou annulation du changement programmé.
 */
function regionChangeNotice(client: Client, selected: number, t: TFunction): string | null {
  const pending = client.pending_region_change
  if (!selected || selected === targetRegionId(client)) {
    return pending ? t('backoffice.clients.regionChangeNotice', { date: formatDate(pending.effective_on) }) : null
  }
  if (pending && selected === client.region.id) return t('backoffice.clients.regionChangeCancelNotice')
  return t('backoffice.clients.regionChangeNotice', { date: formatDate(nextBusinessDay()) })
}
