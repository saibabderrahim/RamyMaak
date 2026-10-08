import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { ROLES, type Role } from '../../shared/api/types'
import { formatDateTime } from '../../shared/format'
import { Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../shared/ui'
import { type UserFilters, useUsers } from './api'
import { Pagination, UserStatusBadges } from './components'
import { useDebouncedValue } from './useDebouncedValue'

export default function UsersPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<Omit<UserFilters, 'search'>>({ role: '', is_active: '', page: 1 })
  const debouncedSearch = useDebouncedValue(search)
  const users = useUsers({ ...filters, search: debouncedSearch })

  const update = (patch: Partial<UserFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <>
      <PageHeader title={t('admin.users.title')} actions={<Button onClick={() => navigate('nouveau')}>{t('admin.users.create')}</Button>} />

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <Field label={t('common.search')}>
            {(props) => (
              <Input
                {...props}
                type="search"
                value={search}
                placeholder={t('admin.users.searchPlaceholder')}
                onChange={(event) => {
                  setSearch(event.target.value)
                  update({})
                }}
              />
            )}
          </Field>
          <Field label={t('admin.users.columns.role')}>
            {(props) => (
              <Select {...props} value={filters.role} onChange={(event) => update({ role: event.target.value as Role | '' })}>
                <option value="">{t('common.all')}</option>
                {ROLES.map((role) => (
                  <option key={role} value={role}>
                    {t(`roles.${role}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('admin.users.columns.status')}>
            {(props) => (
              <Select {...props} value={filters.is_active} onChange={(event) => update({ is_active: event.target.value as UserFilters['is_active'] })}>
                <option value="">{t('common.all')}</option>
                <option value="1">{t('admin.users.status.active')}</option>
                <option value="0">{t('admin.users.status.inactive')}</option>
              </Select>
            )}
          </Field>
        </div>

        <ErrorAlert error={users.error} />
        {users.isPending && <Spinner />}

        {users.data && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-start text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.users.columns.username')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.users.columns.name')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.users.columns.role')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('admin.users.columns.status')}</th>
                    <th className="py-2 text-start font-medium">{t('admin.users.columns.lastLogin')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {users.data.data.map((user) => (
                    <tr key={user.id} className="hover:bg-slate-50">
                      <td className="py-2 pe-4 font-medium">
                        <Link to={String(user.id)} className="text-brand-700 hover:underline">
                          {user.username}
                        </Link>
                      </td>
                      <td className="py-2 pe-4">{user.name}</td>
                      <td className="py-2 pe-4">{t(`roles.${user.role}`)}</td>
                      <td className="py-2 pe-4">
                        <UserStatusBadges user={user} />
                      </td>
                      <td className="py-2 text-slate-600">{formatDateTime(user.last_login_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {users.data.data.length === 0 && <p className="py-6 text-center text-sm text-slate-500">{t('admin.users.empty')}</p>}
            <Pagination meta={users.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
          </>
        )}
      </Card>
    </>
  )
}
