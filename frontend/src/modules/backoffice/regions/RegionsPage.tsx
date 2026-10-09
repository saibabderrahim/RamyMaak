import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../../shared/ui'
import { Pagination } from '../../admin/components'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { useBackoffice } from '../shared/access'
import { useTabParam } from '../shared/tabs'
import { DataTable, Dialog, EmptyState, TabPanel, Tabs, TableHead, Th } from '../shared/ui'
import { type RegionFilters, useCreateRegion, useDepots, useRegions } from './api'
import { RegionStatusBadge } from './components'
import { DepotsPanel } from './DepotsPanel'
import { RegionForm } from './RegionForm'

const TABS = ['regions', 'depots'] as const
type Tab = (typeof TABS)[number]

function CreateRegionDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const navigate = useNavigate()
  const createRegion = useCreateRegion()
  const depots = useDepots()

  return (
    <Dialog open title={t('backoffice.regions.create')} onClose={onClose}>
      <RegionForm
        depots={depots.data}
        submitting={createRegion.isPending}
        error={createRegion.error}
        onCancel={onClose}
        onSubmit={async (values) => {
          const region = await createRegion.mutateAsync(values)
          // Suite logique : désigner le titulaire sur la fiche de la région.
          navigate(`${base}/regions/${region.id}`, { state: { created: true } })
        }}
      />
    </Dialog>
  )
}

function RegionList({ manage }: { manage: boolean }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<Omit<RegionFilters, 'search'>>({ is_active: '', page: 1 })
  const debouncedSearch = useDebouncedValue(search)
  const regions = useRegions({ ...filters, search: debouncedSearch })
  const [creating, setCreating] = useState(false)

  const update = (patch: Partial<RegionFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <Card
      title={t('backoffice.regions.tabs.regions')}
      actions={manage ? <Button onClick={() => setCreating(true)}>{t('backoffice.regions.create')}</Button> : undefined}
    >
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Field label={t('common.search')}>
          {(props) => (
            <Input
              {...props}
              type="search"
              value={search}
              placeholder={t('backoffice.regions.searchPlaceholder')}
              onChange={(event) => {
                setSearch(event.target.value)
                update({})
              }}
            />
          )}
        </Field>
        {/* Seuls les gestionnaires voient les régions inactives. */}
        {manage && (
          <Field label={t('backoffice.common.statusFilter')}>
            {(props) => (
              <Select {...props} value={filters.is_active} onChange={(event) => update({ is_active: event.target.value as RegionFilters['is_active'] })}>
                <option value="">{t('backoffice.regions.statusFilter.all')}</option>
                <option value="1">{t('backoffice.regions.statusFilter.active')}</option>
                <option value="0">{t('backoffice.regions.statusFilter.inactive')}</option>
              </Select>
            )}
          </Field>
        )}
      </div>

      <ErrorAlert error={regions.error} />
      {regions.isPending && <Spinner />}

      {regions.data && (
        <>
          <DataTable label={t('backoffice.regions.title')}>
            <TableHead>
              <Th>{t('backoffice.regions.columns.code')}</Th>
              <Th>{t('backoffice.regions.columns.name')}</Th>
              <Th>{t('backoffice.regions.columns.depot')}</Th>
              <Th>{t('backoffice.regions.columns.titular')}</Th>
              <Th>{t('backoffice.regions.columns.deputies')}</Th>
              <Th className="text-end">{t('backoffice.regions.columns.clients')}</Th>
              <Th>{t('backoffice.regions.columns.status')}</Th>
            </TableHead>
            <tbody className="divide-y divide-slate-100">
              {regions.data.data.map((region) => (
                <tr key={region.id} className="hover:bg-slate-50">
                  <td className="py-2 pe-4 font-medium">
                    <Link to={`${base}/regions/${region.id}`} className="text-brand-700 hover:underline">
                      {region.code}
                    </Link>
                  </td>
                  <td className="py-2 pe-4">{region.name}</td>
                  <td className="py-2 pe-4">{region.depot ? region.depot.name : '—'}</td>
                  <td className="py-2 pe-4">
                    {region.titular ? region.titular.user.name : <span className="text-amber-800">{t('backoffice.regions.noTitular')}</span>}
                  </td>
                  <td className="py-2 pe-4">{region.deputies.length > 0 ? region.deputies.map((deputy) => deputy.user.name).join(', ') : '—'}</td>
                  <td className="py-2 pe-4 text-end tabular-nums">
                    <Link
                      to={`${base}/clients?region_id=${region.id}`}
                      className="text-brand-700 hover:underline"
                      aria-label={`${region.clients_count} — ${t('backoffice.regions.clientsOfRegion', { name: region.name })}`}
                    >
                      {region.clients_count}
                    </Link>
                  </td>
                  <td className="py-2 pe-4">
                    <RegionStatusBadge active={region.is_active} />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          {regions.data.data.length === 0 && <EmptyState>{t('backoffice.regions.empty')}</EmptyState>}
          <Pagination meta={regions.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </>
      )}

      {creating && <CreateRegionDialog onClose={() => setCreating(false)} />}
    </Card>
  )
}

/**
 * Régions (EF-ADM-03) : liste dans le périmètre (RG-S02), création ; onglet
 * Dépôts pour les gestionnaires. L'ADV consulte sans action.
 */
export default function RegionsPage() {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const manage = can('region.manage')
  const [tab, setTab] = useTabParam<Tab>(manage ? TABS : ['regions'], 'regions')

  return (
    <>
      <PageHeader title={t('backoffice.regions.title')} />
      {manage ? (
        <>
          <Tabs
            id="regions"
            label={t('backoffice.common.sections')}
            tabs={TABS.map((id) => ({ id, label: t(`backoffice.regions.tabs.${id}`) }))}
            value={tab}
            onChange={setTab}
          />
          <TabPanel id="regions" tab={tab}>
            {tab === 'depots' ? <DepotsPanel /> : <RegionList manage />}
          </TabPanel>
        </>
      ) : (
        <RegionList manage={false} />
      )}
    </>
  )
}
