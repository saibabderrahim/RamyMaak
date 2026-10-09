import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { z } from 'zod'
import { IMPORT_TYPES, type ImportStatus, type ImportType } from '../../../shared/api/types'
import { formatDateTime } from '../../../shared/format'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../../shared/ui'
import { Pagination } from '../../admin/components'
import { useBackoffice } from '../shared/access'
import { IMPORT_PERMISSIONS, IMPORT_STATUSES, type ImportFilters, type ImportUpload, templateUrl, useImports, useUploadImport } from './api'
import { ImportStatusBadge } from './components'
import { formatCount } from './values'

/** Fichier accepté (§8.4) : .xlsx de 5 Mo au plus ; le serveur refuse aussi macros et .xls. */
const MAX_BYTES = 5 * 1024 * 1024
const XLSX_ACCEPT = '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/**
 * Imports Excel des référentiels (§8.3) : gabarits pré-remplis, dépôt du
 * fichier (analysé en tâche de fond, sans rien modifier), historique.
 * Seuls les types autorisés par les droits de l'utilisateur sont proposés.
 */
export default function ImportsPage() {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const allowed = IMPORT_TYPES.filter((type) => can(IMPORT_PERMISSIONS[type]))

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

type FormValues = { type: ImportType; file: FileList | undefined }

const FIELDS = ['type', 'file'] as const

function UploadCard({ types }: { types: ImportType[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base } = useBackoffice()
  const upload = useUploadImport()

  const schema = z.object({
    type: z.enum(IMPORT_TYPES),
    file: z
      .custom<FileList | undefined>()
      .transform((list) => list?.[0])
      .pipe(
        z
          .custom<File>((value) => value instanceof File, t('backoffice.imports.fileRequired'))
          .refine((file) => /\.xlsx$/i.test(file.name) && file.size <= MAX_BYTES, t('backoffice.imports.fileFormat')),
      ),
  })

  const {
    register,
    handleSubmit,
    setError,
    control,
    formState: { errors },
  } = useForm<FormValues, unknown, ImportUpload>({
    resolver: zodResolver(schema),
    defaultValues: { type: types[0], file: undefined },
  })
  const type = useWatch({ control, name: 'type' })

  const submit = handleSubmit(async (values) => {
    try {
      const created = await upload.mutateAsync(values)
      navigate(`${base}/imports/${created.id}`)
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <Card title={t('backoffice.imports.newImport')}>
      <form onSubmit={submit} noValidate className="flex flex-col gap-5">
        <p className="text-sm text-slate-600">{t('backoffice.imports.intro')}</p>
        {!hasFieldErrors && <ErrorAlert error={upload.error} />}

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.imports.type')}</legend>
          <div className="grid gap-2 sm:grid-cols-3">
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

        <div className="flex flex-col gap-1">
          <a
            href={templateUrl(type)}
            download
            className="inline-flex min-h-11 items-center self-start rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-brand-700 hover:bg-slate-50"
          >
            {t('backoffice.imports.template', { type: t(`backoffice.imports.types.${type}`) })}
          </a>
          <p className="text-xs text-slate-500">{t('backoffice.imports.templateHint')}</p>
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
          <div className="overflow-x-auto">
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
                    <td className="py-2 pe-4">{item.type_label}</td>
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
