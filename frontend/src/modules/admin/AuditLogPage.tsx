import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuditLog } from '../../shared/api/types'
import { formatDateTime } from '../../shared/format'
import { Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../shared/ui'
import { type AuditFilters, auditExportUrl, useAuditLogs } from './api'
import { Pagination } from './components'
import { useDebouncedValue } from './useDebouncedValue'

const LOG_NAMES = ['auth', 'users', 'settings'] as const

function summarize(entry: AuditLog): string {
  const { old, attributes, ...rest } = entry.properties as {
    old?: Record<string, unknown>
    attributes?: Record<string, unknown>
    [key: string]: unknown
  }
  if (attributes) {
    return Object.entries(attributes)
      .map(([key, value]) => `${key} : ${JSON.stringify(old?.[key] ?? null)} → ${JSON.stringify(value)}`)
      .join(' · ')
  }
  return Object.entries(rest)
    .filter(([key]) => key !== 'user_agent')
    .map(([key, value]) => `${key} : ${typeof value === 'string' ? value : JSON.stringify(value)}`)
    .join(' · ')
}

/**
 * Journal d'audit (EF-ADM-06) : filtres par journal, action et période,
 * export CSV.
 */
export default function AuditLogPage() {
  const { t } = useTranslation()
  const [event, setEvent] = useState('')
  const [filters, setFilters] = useState<Omit<AuditFilters, 'event'>>({ log_name: '', from: '', to: '', page: 1 })
  const debouncedEvent = useDebouncedValue(event)
  const query = { ...filters, event: debouncedEvent }
  const logs = useAuditLogs(query)

  const update = (patch: Partial<AuditFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <>
      <PageHeader
        title={t('admin.audit.title')}
        actions={
          <a
            href={auditExportUrl(query)}
            className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50"
          >
            {t('admin.audit.export')}
          </a>
        }
      />

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label={t('admin.audit.filters.log')}>
            {(props) => (
              <Select {...props} value={filters.log_name} onChange={(e) => update({ log_name: e.target.value })}>
                <option value="">{t('common.all')}</option>
                {LOG_NAMES.map((name) => (
                  <option key={name} value={name}>
                    {t(`admin.audit.logs.${name}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('admin.audit.filters.event')}>
            {(props) => (
              <Input
                {...props}
                value={event}
                placeholder="login.failed"
                onChange={(e) => {
                  setEvent(e.target.value)
                  update({})
                }}
              />
            )}
          </Field>
          <Field label={t('admin.audit.filters.from')}>
            {(props) => <Input {...props} type="date" value={filters.from} onChange={(e) => update({ from: e.target.value })} />}
          </Field>
          <Field label={t('admin.audit.filters.to')}>
            {(props) => <Input {...props} type="date" value={filters.to} onChange={(e) => update({ to: e.target.value })} />}
          </Field>
        </div>

        <ErrorAlert error={logs.error} />
        {logs.isPending && <Spinner />}

        {logs.data && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.audit.columns.date')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.audit.columns.action')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.audit.columns.author')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.audit.columns.object')}</th>
                    <th className="py-2 text-start font-medium">{t('admin.audit.columns.details')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 align-top">
                  {logs.data.data.map((entry) => (
                    <tr key={entry.id}>
                      <td className="whitespace-nowrap py-2 pe-4 text-slate-600">{formatDateTime(entry.created_at)}</td>
                      <td className="py-2 pe-4">
                        <div className="font-medium text-slate-900">{entry.description}</div>
                        <div className="text-xs text-slate-500">
                          {entry.log_name} · {entry.event}
                        </div>
                      </td>
                      <td className="py-2 pe-4">{entry.causer?.username ?? t('admin.audit.system')}</td>
                      <td className="py-2 pe-4 text-slate-600">{entry.subject ? `${entry.subject.type} #${entry.subject.id}` : '—'}</td>
                      <td className="break-all py-2 text-xs text-slate-600">{summarize(entry)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {logs.data.data.length === 0 && <p className="py-6 text-center text-sm text-slate-500">{t('admin.audit.empty')}</p>}
            <Pagination meta={logs.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
          </>
        )}
      </Card>
    </>
  )
}
