import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { CLIENT_STATUSES, type Client, type ClientStatus } from '../../../shared/api/types'
import { Alert, Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../../shared/ui'
import { Pagination } from '../../admin/components'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { useRegionOptions } from '../regions/api'
import { useBackoffice } from '../shared/access'
import { useTabParam } from '../shared/tabs'
import { DataTable, EmptyState, TabPanel, Tabs, TableHead, Th } from '../shared/ui'
import { type ClientFilters, MAX_ACCOUNT_BATCH, useCategories, useClients, useCreateClientAccounts } from './api'
import { CategoriesPanel } from './CategoriesPanel'
import { ClientStatusBadge, PendingRegionMarker } from './components'

const TABS = ['clients', 'categories'] as const
type Tab = (typeof TABS)[number]

const CHECKBOX_CLASSES = 'size-5 cursor-pointer rounded border-slate-300 accent-brand-600 disabled:cursor-not-allowed'

function isStatus(value: string | null): value is ClientStatus {
  return CLIENT_STATUSES.some((status) => status === value)
}

/**
 * Création des comptes des clients sélectionnés et téléchargement des
 * fiches d'accès PDF (EF-ADM-02).
 */
function AccountsBar({ selected, onClear }: { selected: number[]; onClear: () => void }) {
  const { t } = useTranslation()
  const createAccounts = useCreateClientAccounts()

  return (
    <div className="mb-4 flex flex-col gap-3">
      {createAccounts.data && (
        <Alert tone="success">{t('backoffice.clients.accountsCreated', { count: createAccounts.data.created })}</Alert>
      )}
      <ErrorAlert error={createAccounts.error} />
      {selected.length > 0 && (
        <div className="flex flex-col gap-2 rounded-lg border border-brand-200 bg-brand-50 p-3" role="region" aria-label={t('backoffice.clients.createAccounts')}>
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm font-semibold text-brand-900" aria-live="polite">
              {t('backoffice.clients.selected', { count: selected.length })}
            </span>
            <Button
              disabled={createAccounts.isPending}
              onClick={() => createAccounts.mutate(selected, { onSuccess: onClear })}
            >
              {createAccounts.isPending ? t('backoffice.clients.creatingAccounts') : t('backoffice.clients.createAccounts')}
            </Button>
            <Button variant="ghost" onClick={onClear} disabled={createAccounts.isPending}>
              {t('backoffice.clients.clearSelection')}
            </Button>
          </div>
          <p className="text-xs text-slate-700">{t('backoffice.clients.accountsHint')}</p>
          {selected.length >= MAX_ACCOUNT_BATCH && (
            <p className="text-xs font-medium text-amber-800">{t('backoffice.clients.maxSelection', { max: MAX_ACCOUNT_BATCH })}</p>
          )}
        </div>
      )}
    </div>
  )
}

function ClientList() {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const [params] = useSearchParams()
  const selectable = can('user.manage')

  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<Omit<ClientFilters, 'search'>>(() => {
    const status = params.get('status')
    return {
      region_id: params.get('region_id') ?? '',
      category_id: params.get('category_id') ?? '',
      status: isStatus(status) ? status : '',
      page: 1,
    }
  })
  const debouncedSearch = useDebouncedValue(search)
  const clients = useClients({ ...filters, search: debouncedSearch })
  const regions = useRegionOptions()
  const categories = useCategories()
  const [selected, setSelected] = useState<number[]>([])

  const update = (patch: Partial<ClientFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  const rows: Client[] = clients.data?.data ?? []
  const pageIds = rows.map((client) => client.id)
  const selectedOnPage = pageIds.filter((id) => selected.includes(id))
  const allOnPage = pageIds.length > 0 && selectedOnPage.length === pageIds.length
  const full = selected.length >= MAX_ACCOUNT_BATCH

  const toggle = (id: number) =>
    setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : current.length >= MAX_ACCOUNT_BATCH ? current : [...current, id]))

  const togglePage = () =>
    setSelected((current) =>
      allOnPage
        ? current.filter((id) => !pageIds.includes(id))
        : [...current, ...pageIds.filter((id) => !current.includes(id))].slice(0, MAX_ACCOUNT_BATCH),
    )

  const showAccounts = rows.some((client) => client.accounts !== undefined)

  return (
    <Card title={t('backoffice.clients.tabs.clients')}>
      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label={t('common.search')}>
          {(props) => (
            <Input
              {...props}
              type="search"
              value={search}
              placeholder={t('backoffice.clients.searchPlaceholder')}
              onChange={(event) => {
                setSearch(event.target.value)
                update({})
              }}
            />
          )}
        </Field>
        <Field label={t('backoffice.clients.filters.region')}>
          {(props) => (
            <Select {...props} value={filters.region_id} onChange={(event) => update({ region_id: event.target.value })}>
              <option value="">{t('backoffice.clients.filters.all')}</option>
              {filters.region_id && !regions.data?.some((region) => String(region.id) === filters.region_id) && (
                <option value={filters.region_id}>{`#${filters.region_id}`}</option>
              )}
              {regions.data?.map((region) => (
                <option key={region.id} value={region.id}>
                  {`${region.code} — ${region.name}`}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.clients.filters.category')}>
          {(props) => (
            <Select {...props} value={filters.category_id} onChange={(event) => update({ category_id: event.target.value })}>
              <option value="">{t('backoffice.clients.filters.all')}</option>
              {categories.data?.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.clients.filters.status')}>
          {(props) => (
            <Select {...props} value={filters.status} onChange={(event) => update({ status: event.target.value as ClientStatus | '' })}>
              <option value="">{t('backoffice.clients.filters.allStatuses')}</option>
              {CLIENT_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {t(`backoffice.clients.status.${status}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>

      {selectable && <AccountsBar selected={selected} onClear={() => setSelected([])} />}

      <ErrorAlert error={clients.error} />
      {clients.isPending && <Spinner />}

      {clients.data && (
        <>
          <DataTable label={t('backoffice.clients.title')} className="min-w-[860px]">
            <TableHead>
              {selectable && (
                <Th className="w-10">
                  <input
                    type="checkbox"
                    className={CHECKBOX_CLASSES}
                    aria-label={t('backoffice.clients.selectPage')}
                    checked={allOnPage}
                    disabled={pageIds.length === 0}
                    ref={(element) => {
                      if (element) element.indeterminate = selectedOnPage.length > 0 && !allOnPage
                    }}
                    onChange={togglePage}
                  />
                </Th>
              )}
              <Th>{t('backoffice.clients.columns.code')}</Th>
              <Th>{t('backoffice.clients.columns.name')}</Th>
              <Th>{t('backoffice.clients.columns.category')}</Th>
              <Th>{t('backoffice.clients.columns.region')}</Th>
              <Th>{t('backoffice.clients.columns.phone')}</Th>
              <Th>{t('backoffice.clients.columns.status')}</Th>
              {showAccounts && <Th>{t('backoffice.clients.columns.account')}</Th>}
            </TableHead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((client) => {
                const checked = selected.includes(client.id)
                return (
                  <tr key={client.id} className={checked ? 'bg-brand-50' : 'hover:bg-slate-50'}>
                    {selectable && (
                      <td className="py-2 pe-4">
                        <input
                          type="checkbox"
                          className={CHECKBOX_CLASSES}
                          aria-label={t('backoffice.clients.selectOne', { code: client.code })}
                          checked={checked}
                          disabled={!checked && full}
                          onChange={() => toggle(client.id)}
                        />
                      </td>
                    )}
                    <td className="py-2 pe-4 font-medium">
                      <Link to={`${base}/clients/${client.id}`} className="text-brand-700 hover:underline">
                        {client.code}
                      </Link>
                    </td>
                    <td className="py-2 pe-4">
                      {client.name}
                      {client.trade_name && <span className="block text-xs text-slate-600">{client.trade_name}</span>}
                    </td>
                    <td className="py-2 pe-4">{client.category?.name ?? '—'}</td>
                    <td className="py-2 pe-4">
                      <span className="flex flex-wrap items-center gap-1">
                        <span>{client.region.name}</span>
                        {client.pending_region_change && <PendingRegionMarker change={client.pending_region_change} />}
                      </span>
                    </td>
                    <td className="py-2 pe-4 whitespace-nowrap">{client.phone ?? '—'}</td>
                    <td className="py-2 pe-4">
                      <ClientStatusBadge status={client.status} />
                    </td>
                    {showAccounts && (
                      <td className="py-2 pe-4">
                        {client.accounts && client.accounts.length > 0 ? (
                          client.accounts.map((account) => account.username).join(', ')
                        ) : (
                          <span className="text-slate-500">{t('backoffice.clients.noAccount')}</span>
                        )}
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
          {rows.length === 0 && <EmptyState>{t('backoffice.clients.empty')}</EmptyState>}
          <Pagination meta={clients.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </>
      )}
    </Card>
  )
}

/**
 * Référentiel clients (EF-CLI-01, EF-CLI-04) : liste dans le périmètre
 * (RG-S02) avec recherche et filtres, sélection multiple pour créer les
 * comptes et leurs fiches d'accès (EF-ADM-02) ; onglet Catégories.
 * L'ADV consulte sans action.
 */
export default function ClientsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base, can } = useBackoffice()
  const manage = can('client.manage')
  const [tab, setTab] = useTabParam<Tab>(TABS, 'clients')

  return (
    <>
      <PageHeader
        title={t('backoffice.clients.title')}
        actions={manage && base === '/admin' ? <Button onClick={() => navigate(`${base}/clients/nouveau`)}>{t('backoffice.clients.create')}</Button> : undefined}
      />
      <Tabs
        id="clients"
        label={t('backoffice.common.sections')}
        tabs={TABS.map((id) => ({ id, label: t(`backoffice.clients.tabs.${id}`) }))}
        value={tab}
        onChange={setTab}
      />
      <TabPanel id="clients" tab={tab}>
        {tab === 'categories' ? <CategoriesPanel manage={manage} /> : <ClientList />}
      </TabPanel>
    </>
  )
}
