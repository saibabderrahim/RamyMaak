import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '../../shared/api/client'
import type { Client, Paginated } from '../../shared/api/types'
import { Button, Field, Input } from '../../shared/ui'
import { useDebouncedValue } from '../../shared/useDebouncedValue'

export type PickedClient = { id: number; code: string; name: string }

type Props = {
  value: PickedClient | null
  onChange: (client: PickedClient | null) => void
  error?: string
}

/**
 * Rattachement d'un compte à un client (EF-ADM-01) : recherche par code ou
 * raison sociale dans le référentiel, au lieu d'un identifiant technique.
 * Un client inactif n'est pas proposé (§3.5).
 */
export function ClientPicker({ value, onChange, error }: Props) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const term = useDebouncedValue(search.trim(), 300)
  const results = useQuery({
    queryKey: ['admin', 'client-picker', term],
    queryFn: ({ signal }) => api.get<Paginated<Client>>('/clients', { 'filter[search]': term, per_page: 10, sort: 'code' }, signal),
    enabled: value === null && term !== '',
  })

  if (value) {
    return (
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium text-slate-800">{t('admin.users.fields.client')}</span>
        <div className="flex flex-wrap items-center gap-3">
          <Link to={`/admin/clients/${value.id}`} className="font-medium text-brand-700 hover:underline">
            {`${value.code} — ${value.name}`}
          </Link>
          <Button variant="secondary" onClick={() => onChange(null)}>
            {t('admin.users.fields.clientChange')}
          </Button>
        </div>
        {error && (
          <p role="alert" className="text-sm text-red-700">
            {error}
          </p>
        )}
      </div>
    )
  }

  const clients = results.data?.data ?? []

  return (
    <div className="flex flex-col gap-2">
      <Field label={t('admin.users.fields.client')} hint={t('admin.users.fields.clientHint')} error={error}>
        {(props) => (
          <Input
            {...props}
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('admin.users.fields.clientPlaceholder')}
            autoComplete="off"
          />
        )}
      </Field>
      {term !== '' && (
        <div aria-live="polite">
          {results.isPending ? (
            <p className="text-sm text-slate-600">{t('common.loading')}</p>
          ) : clients.length === 0 ? (
            <p className="text-sm text-slate-600">{t('admin.users.fields.clientNone')}</p>
          ) : (
            <ul className="flex flex-col gap-1">
              {clients.map((client) => (
                <li key={client.id}>
                  <button
                    type="button"
                    disabled={client.status === 'INACTIVE'}
                    onClick={() => onChange({ id: client.id, code: client.code, name: client.name })}
                    className="min-h-11 w-full rounded-lg border border-slate-200 px-3 py-2 text-start text-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400"
                  >
                    {client.status === 'INACTIVE'
                      ? t('admin.users.fields.clientInactive', { label: `${client.code} — ${client.name}` })
                      : `${client.code} — ${client.name} · ${client.region.name}`}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
