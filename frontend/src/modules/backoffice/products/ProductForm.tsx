import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Product } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Button, Checkbox, ErrorAlert, Field, Input, Select, Spinner } from '../../../shared/ui'
import { type ProductInput, useFamilies } from './api'
import { familyOptionLabel, orderFamilies } from './families'
import { numericInput as numeric } from './numbers'

type FormValues = {
  sku: string
  name: string
  short_name: string
  family_id: string
  format: string
  sales_unit_label: string
  units_per_sales_unit: string
  sales_units_per_pallet: string
  ean: string
  vat_rate: string
  sort_order: string
  is_active: boolean
  is_new: boolean
  is_featured: boolean
}

const FIELDS = [
  'sku',
  'name',
  'short_name',
  'family_id',
  'format',
  'sales_unit_label',
  'units_per_sales_unit',
  'sales_units_per_pallet',
  'ean',
  'vat_rate',
  'sort_order',
  'is_active',
  'is_new',
  'is_featured',
] as const

const optionalText = z
  .string()
  .trim()
  .transform((value) => (value === '' ? null : value))

function useProductSchema(creating: boolean) {
  const { t } = useTranslation()
  const integer = (min: number, max: number, message: string) =>
    z.number(message).int(message).min(min, message).max(max, message)

  return z.object({
    // Code ERP (clé des fichiers Excel) : mis en majuscules, saisi à la création seulement.
    sku: creating
      ? z
          .string()
          .trim()
          .toUpperCase()
          .regex(/^[A-Z0-9_-]{1,30}$/, t('backoffice.products.validation.sku'))
      : z.string(),
    name: z.string().trim().min(1).max(255),
    short_name: optionalText.pipe(z.string().max(60).nullable()),
    family_id: z
      .string()
      .transform((value) => (value === '' ? Number.NaN : Number(value)))
      .pipe(z.number(t('backoffice.products.validation.family')).int().positive(t('backoffice.products.validation.family'))),
    format: optionalText.pipe(z.string().max(30).nullable()),
    sales_unit_label: z.string().trim().min(1).max(60),
    units_per_sales_unit: z
      .string()
      .transform((value) => (numeric(value) === '' ? Number.NaN : Number(numeric(value))))
      .pipe(integer(1, 65_535, t('backoffice.products.validation.atLeastOne'))),
    sales_units_per_pallet: z
      .string()
      .transform((value) => (numeric(value) === '' ? null : Number(numeric(value))))
      .pipe(integer(1, 65_535, t('backoffice.products.validation.atLeastOne')).nullable()),
    ean: optionalText.pipe(z.string().regex(/^\d{8,14}$/, t('backoffice.products.validation.ean')).nullable()),
    // Taux porté par la fiche (RG-P02) : 0 à 100, deux décimales au plus.
    vat_rate: z
      .string()
      .transform(numeric)
      .pipe(z.string().regex(/^\d{1,3}(\.\d{1,2})?$/, t('backoffice.products.validation.vatRate')))
      .transform(Number)
      .pipe(z.number().min(0).max(100, t('backoffice.products.validation.vatRate'))),
    sort_order: z
      .string()
      .transform((value) => (numeric(value) === '' ? 0 : Number(numeric(value))))
      .pipe(integer(0, 1_000_000, t('backoffice.products.validation.sortOrder'))),
    is_active: z.boolean(),
    is_new: z.boolean(),
    is_featured: z.boolean(),
  })
}

function defaults(product?: Product): FormValues {
  const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value))
  return {
    sku: product?.sku ?? '',
    name: product?.name ?? '',
    short_name: text(product?.short_name),
    family_id: text(product?.family?.id),
    format: text(product?.format),
    sales_unit_label: product?.sales_unit_label ?? '',
    units_per_sales_unit: text(product?.units_per_sales_unit),
    sales_units_per_pallet: text(product?.sales_units_per_pallet),
    ean: text(product?.ean),
    // Taux normal de TVA proposé à la création.
    vat_rate: product ? String(product.vat_rate) : '19',
    sort_order: product ? String(product.sort_order) : '0',
    is_active: product?.is_active ?? true,
    is_new: product?.is_new ?? false,
    is_featured: product?.is_featured ?? false,
  }
}

type Props = {
  product?: Product
  submitting: boolean
  error: unknown
  onSubmit: (values: ProductInput) => Promise<unknown>
}

/**
 * Fiche produit (EF-PRD-01, EF-PRD-05). Le code article n'est saisi qu'à la
 * création ; la désignation courte vaut par défaut la désignation tronquée.
 */
export function ProductForm({ product, submitting, error, onSubmit }: Props) {
  const { t } = useTranslation()
  const families = useFamilies()
  const schema = useProductSchema(!product)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues, unknown, ProductInput>({ resolver: zodResolver(schema), defaultValues: defaults(product) })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  // Liste des familles chargée avant le formulaire : la famille actuelle reste sélectionnée.
  if (families.isPending) return <Spinner />

  const hasFieldErrors = Object.keys(errors).length > 0
  const nodes = orderFamilies(families.data?.data ?? [])

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {!hasFieldErrors && <ErrorAlert error={error} />}
      <ErrorAlert error={families.error} />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('backoffice.products.fields.sku')} hint={t('backoffice.products.fields.skuHint')} error={errors.sku?.message}>
          {(props) => <Input {...props} {...register('sku')} disabled={Boolean(product)} autoCapitalize="characters" spellCheck={false} />}
        </Field>
        <Field label={t('backoffice.products.fields.family')} error={errors.family_id?.message}>
          {(props) => (
            <Select {...props} {...register('family_id')}>
              <option value="">{t('backoffice.products.fields.familyPlaceholder')}</option>
              {nodes.map((node) => (
                <option key={node.family.id} value={node.family.id}>
                  {familyOptionLabel(node)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.products.fields.name')} error={errors.name?.message}>
          {(props) => <Input {...props} {...register('name')} />}
        </Field>
        <Field label={t('backoffice.products.fields.shortName')} hint={t('backoffice.products.fields.shortNameHint')} error={errors.short_name?.message}>
          {(props) => <Input {...props} {...register('short_name')} maxLength={60} />}
        </Field>
        <Field label={t('backoffice.products.fields.format')} hint={t('backoffice.products.fields.formatHint')} error={errors.format?.message}>
          {(props) => <Input {...props} {...register('format')} />}
        </Field>
        <Field
          label={t('backoffice.products.fields.salesUnitLabel')}
          hint={t('backoffice.products.fields.salesUnitLabelHint')}
          error={errors.sales_unit_label?.message}
        >
          {(props) => <Input {...props} {...register('sales_unit_label')} />}
        </Field>
        <Field label={t('backoffice.products.fields.unitsPerSalesUnit')} error={errors.units_per_sales_unit?.message}>
          {(props) => <Input {...props} {...register('units_per_sales_unit')} inputMode="numeric" />}
        </Field>
        <Field label={t('backoffice.products.fields.salesUnitsPerPallet')} error={errors.sales_units_per_pallet?.message}>
          {(props) => <Input {...props} {...register('sales_units_per_pallet')} inputMode="numeric" />}
        </Field>
        <Field label={t('backoffice.products.fields.ean')} hint={t('backoffice.products.fields.eanHint')} error={errors.ean?.message}>
          {(props) => <Input {...props} {...register('ean')} inputMode="numeric" />}
        </Field>
        <Field label={t('backoffice.products.fields.vatRate')} error={errors.vat_rate?.message}>
          {(props) => <Input {...props} {...register('vat_rate')} inputMode="decimal" />}
        </Field>
        <Field label={t('backoffice.products.fields.sortOrder')} error={errors.sort_order?.message}>
          {(props) => <Input {...props} {...register('sort_order')} inputMode="numeric" />}
        </Field>
      </div>

      <fieldset className="flex flex-col">
        <legend className="text-sm font-medium text-slate-800">{t('backoffice.products.fields.status')}</legend>
        <Checkbox label={t('backoffice.products.fields.isActive')} {...register('is_active')} />
        <Checkbox label={t('backoffice.products.fields.isNew')} {...register('is_new')} />
        <Checkbox label={t('backoffice.products.fields.isFeatured')} {...register('is_featured')} />
      </fieldset>

      <Button type="submit" className="self-start" disabled={submitting}>
        {submitting ? t('common.saving') : t('common.save')}
      </Button>
    </form>
  )
}
