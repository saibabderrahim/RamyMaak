import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { type FieldErrors, type Resolver, type UseFormRegister, useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { IMPORT_MODES, IMPORT_TYPES, type ImportMode, type ImportStatus, type ImportType } from '../../../shared/api/types'
import { formatDate, formatDateTime } from '../../../shared/format'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../../shared/ui'
import { Pagination } from '../../admin/components'
import { useOpenDays } from '../days/api'
import { DAY_PATTERN, formatDayLong, isDay } from '../days/dates'
import { useBackoffice } from '../shared/access'
import { IMPORT_PERMISSIONS, IMPORT_STATUSES, type ImportFilters, type ImportUpload, templateUrl, useImports, useUploadImport } from './api'
import { ImportStatusBadge } from './components'
import { formatCount } from './values'

/** Fichier accepté (§8.4) : .xlsx de 5 Mo au plus ; le serveur refuse aussi macros et .xls. */
const MAX_BYTES = 5 * 1024 * 1024
const XLSX_ACCEPT = '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/** Ordre de présentation : les quotas d'abord (import quotidien de l'ADV). */
const TYPE_ORDER: readonly ImportType[] = ['QUOTAS', 'CLIENTS', 'PRODUCTS', 'PRICES']

const TEMPLATE_LINK =
  'inline-flex min-h-11 items-center self-start rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-brand-700 hover:bg-slate-50'

/**
 * Imports Excel des quotas (§8.1) et des référentiels (§8.3) : gabarits
 * pré-remplis, dépôt du fichier (analysé en tâche de fond, sans rien
 * modifier), historique. Seuls les types autorisés par les droits de
 * l'utilisateur sont proposés. Quotas : journée ouverte et mode, présélectionnés
 * depuis la matrice (`?type=QUOTAS&day=AAAA-MM-JJ`).
 */
export default function ImportsPage() {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const allowed = TYPE_ORDER.filter((type) => can(IMPORT_PERMISSIONS[type]))

  return (
    <>
      <PageHeader title={t('backoffice.imports.title')} />
      <div className="flex flex-col gap-6">
        {allowed.length > 0 ? <UploadCard types={allowed} /> : <Alert tone="warning">{t('backoffice.imports.noRight')}</Alert>}
        <HistoryCard />
      </div>
    </>
  )
}

type FormValues = { type: ImportType; file: FileList | undefined; business_day: string; mode: ImportMode | '' }

const FIELDS = ['type', 'file', 'business_day', 'mode'] as const

function UploadCard({ types }: { types: ImportType[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base } = useBackoffice()
  const upload = useUploadImport()
  const [params] = useSearchParams()

  // Présélection depuis la matrice des quotas (« Importer »).
  const requestedType = params.get('type')
  const requestedDay = params.get('day')
  const initialType = types.find((type) => type === requestedType) ?? types[0]
  const initialDay = isDay(requestedDay) ? requestedDay : ''

  const file = z
    .custom<FileList | undefined>()
    .transform((list) => list?.[0])
    .pipe(
      z
        .custom<File>((value) => value instanceof File, t('backoffice.imports.fileRequired'))
        .refine((value) => /\.xlsx$/i.test(value.name) && value.size <= MAX_BYTES, t('backoffice.imports.fileFormat')),
    )
  const schema = z.discriminatedUnion('type', [
    z.object({
      type: z.literal('QUOTAS'),
      file,
      business_day: z.string().regex(DAY_PATTERN, t('backoffice.imports.quotas.dayRequired')),
      mode: z.enum(IMPORT_MODES, t('backoffice.imports.quotas.modeRequired')),
    }),
    z.object({ type: z.enum(['CLIENTS', 'PRODUCTS', 'PRICES']), file }),
  ])

  const {
    register,
    handleSubmit,
    setError,
    control,
    formState: { errors },
  } = useForm<FormValues, unknown, ImportUpload>({
    // Formulaire à plat, schéma discriminé par le type : même données, typages différents.
    resolver: zodResolver(schema) as unknown as Resolver<FormValues, unknown, ImportUpload>,
    defaultValues: { type: initialType, file: undefined, business_day: initialDay, mode: '' },
  })
  const type = useWatch({ control, name: 'type' })
  const businessDay = useWatch({ control, name: 'business_day' })

  const submit = handleSubmit(async (values) => {
    try {
      const created = await upload.mutateAsync(values)
      navigate(`${base}/imports/${created.id}`)
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  const hasFieldErrors = Object.keys(errors).length > 0
  const quotas = type === 'QUOTAS'
  const typeLabel = t(`backoffice.imports.types.${type}`)

  return (
    <Card title={t('backoffice.imports.newImport')}>
      <form onSubmit={submit} noValidate className="flex flex-col gap-5">
        <p className="text-sm text-slate-600">{t('backoffice.imports.intro')}</p>
        {!hasFieldErrors && <ErrorAlert error={upload.error} />}

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.imports.type')}</legend>
          <div className={`grid gap-2 ${types.length > 3 ? 'sm:grid-cols-2 xl:grid-cols-4' : 'sm:grid-cols-3'}`}>
            {types.map((option) => (
              <label
                key={option}
                className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
              >
                <input type="radio" value={option} {...register('type')} className="mt-0.5 size-5 shrink-0 accent-brand-600" />
                <span>
                  <span className="block font-semibold text-slate-900">{t(`backoffice.imports.types.${option}`)}</span>
                  <span className="block text-xs text-slate-600">{t(`backoffice.imports.typeHints.${option}`)}</span>
                </span>
              </label>
            ))}
          </div>
          {errors.type?.message && (
            <p role="alert" className="text-sm text-red-700">
              {errors.type.message}
            </p>
          )}
        </fieldset>

        {quotas && <QuotaFields register={register} errors={errors} initialDay={initialDay} />}

        <div className="flex flex-col gap-1">
          {quotas && !isDay(businessDay) ? (
            <Button variant="secondary" disabled className="self-start">
              {t('backoffice.imports.template', { type: typeLabel })}
            </Button>
          ) : (
            <a href={templateUrl(type, businessDay)} download className={TEMPLATE_LINK}>
              {t('backoffice.imports.template', { type: typeLabel })}
            </a>
          )}
          <p className="text-xs text-slate-500">
            {quotas
              ? isDay(businessDay)
                ? t('backoffice.imports.quotas.templateHint')
                : t('backoffice.imports.quotas.templateDisabled')
              : t('backoffice.imports.templateHint')}
          </p>
        </div>

        <Field label={t('backoffice.imports.file')} hint={t('backoffice.imports.fileHint')} error={errors.file?.message}>
          {(props) => <Input {...props} {...register('file')} type="file" accept={XLSX_ACCEPT} className="py-2" />}
        </Field>

        <Button type="submit" className="self-start" disabled={upload.isPending}>
          {upload.isPending ? t('backoffice.imports.submitting') : t('backoffice.imports.submit')}
        </Button>
      </form>
    </Card>
  )
}

type QuotaFieldsProps = {
  register: UseFormRegister<FormValues>
  errors: FieldErrors<FormValues>
  initialDay: string
}

/**
 * Journée (ouverte, d'aujourd'hui à J+30) et mode d'un import de quotas
 * (§8.1). La liste n'est affichée qu'une fois les journées connues : la
 * journée présélectionnée y reste choisie.
 */
function QuotaFields({ register, errors, initialDay }: QuotaFieldsProps) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const openDays = useOpenDays()
  const days = (openDays.data ?? []).map((day) => day.day)
  // Journée demandée par l'adresse mais absente de la liste : gardée, le serveur dira pourquoi.
  if (initialDay && !days.includes(initialDay)) days.unshift(initialDay)

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="flex flex-col gap-2">
        <ErrorAlert error={openDays.error} />
        {openDays.isPending ? (
          <Spinner />
        ) : (
          <>
            {days.length === 0 && (
              <Alert tone="warning">
                {t('backoffice.imports.quotas.noOpenDay')}{' '}
                <Link to={`${base}/journees`} className="font-semibold underline">
                  {t('backoffice.imports.quotas.calendarLink')}
                </Link>
              </Alert>
            )}
            <Field label={t('backoffice.imports.quotas.day')} hint={t('backoffice.imports.quotas.dayHint')} error={errors.business_day?.message}>
              {(props) => (
                <Select {...props} {...register('business_day')}>
                  <option value="">{t('backoffice.imports.quotas.dayPlaceholder')}</option>
                  {days.map((day) => (
                    <option key={day} value={day}>
                      {formatDayLong(day)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </>
        )}
      </div>

      <fieldset className="flex flex-col gap-1">
        <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.imports.quotas.mode')}</legend>
        <div className="grid gap-2">
          {IMPORT_MODES.map((mode) => (
            <label
              key={mode}
              className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
            >
              <input
                type="radio"
                value={mode}
                {...register('mode')}
                aria-invalid={Boolean(errors.mode)}
                className="mt-0.5 size-5 shrink-0 accent-brand-600"
              />
              <span>
                <span className="block font-semibold text-slate-900">{t(`backoffice.imports.quotas.modes.${mode}`)}</span>
                <span className="block text-xs text-slate-600">{t(`backoffice.imports.quotas.modeHints.${mode}`)}</span>
              </span>
            </label>
          ))}
        </div>
        {errors.mode?.message && (
          <p role="alert" className="text-sm text-red-700">
            {errors.mode.message}
          </p>
        )}
      </fieldset>
    </div>
  )
}

function HistoryCard() {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const [filters, setFilters] = useState<ImportFilters>({ type: '', status: '', page: 1 })
  const imports = useImports(filters)

  const update = (patch: Partial<ImportFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <Card title={t('backoffice.imports.history')}>
      <div className="mb-4 grid gap-3 sm:grid-cols-2">
        <Field label={t('backoffice.imports.columns.type')}>
          {(props) => (
            <Select {...props} value={filters.type} onChange={(event) => update({ type: event.target.value as ImportType | '' })}>
              <option value="">{t('common.all')}</option>
              {IMPORT_TYPES.map((type) => (
                <option key={type} value={type}>
                  {t(`backoffice.imports.types.${type}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.imports.columns.status')}>
          {(props) => (
            <Select {...props} value={filters.status} onChange={(event) => update({ status: event.target.value as ImportStatus | '' })}>
              <option value="">{t('common.all')}</option>
              {IMPORT_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {t(`backoffice.imports.status.${status}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>

      <ErrorAlert error={imports.error} />
      {imports.isPending && <Spinner />}

      {imports.data && (
        <>
          <div className="relative overflow-x-auto">
            <table className="w-full min-w-[720px] text-start text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                <tr>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.columns.createdAt')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.columns.type')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.columns.file')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.columns.user')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.columns.status')}</th>
                  <th className="py-2 text-start font-medium">{t('backoffice.imports.columns.rows')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {imports.data.data.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50">
                    <td className="py-2 pe-4 whitespace-nowrap text-slate-600">{formatDateTime(item.created_at)}</td>
                    <td className="py-2 pe-4">
                      {item.type_label}
                      {item.business_day && (
                        <span className="block text-xs text-slate-500">
                          {t('backoffice.imports.quotas.historyDetail', {
                            day: formatDate(item.business_day),
                            mode: item.mode ? t(`backoffice.imports.quotas.modes.${item.mode}`) : t('common.none'),
                          })}
                        </span>
                      )}
                    </td>
                    <td className="py-2 pe-4">
                      <Link to={`${base}/imports/${item.id}`} className="font-medium text-brand-700 hover:underline">
                        {item.original_name}
                      </Link>
                    </td>
                    <td className="py-2 pe-4">{item.user?.name ?? t('common.none')}</td>
                    <td className="py-2 pe-4">
                      <ImportStatusBadge status={item.status} />
                    </td>
                    <td className="py-2 text-slate-600">
                      {item.rows_total > 0 ? (
                        <>
                          {t('backoffice.imports.rowsSummary', { ok: formatCount(item.rows_ok), total: formatCount(item.rows_total) })}
                          {item.rows_error > 0 && (
                            <span className="block text-red-700">{t('backoffice.imports.rowsErrors', { count: item.rows_error })}</span>
                          )}
                        </>
                      ) : (
                        t('common.none')
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {imports.data.data.length === 0 && <p className="py-6 text-center text-sm text-slate-500">{t('backoffice.imports.historyEmpty')}</p>}
          <Pagination meta={imports.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </>
      )}
    </Card>
  )
}
