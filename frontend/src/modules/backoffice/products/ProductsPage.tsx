import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { formatAmount, formatDate } from '../../../shared/format'
import { Button, Card, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../../shared/ui'
import { Pagination } from '../../admin/components'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { useBackoffice } from '../shared/access'
import { useTabParam } from '../shared/tabs'
import { TabPanel, Tabs } from '../shared/ui'
import { type ProductFilters, useFamilies, useProducts } from './api'
import { ProductBadges, ProductThumbnail } from './components'
import { FamiliesPanel } from './FamiliesPanel'
import { familyOptionLabel, orderFamilies } from './families'

const TABS = ['produits', 'familles'] as const

/**
 * Catalogue back-office (§7.3) : produits (vignette, prix HT et TTC du jour,
 * statut) et onglet Familles. Admin, et ADV en lecture seule : les actions
 * n'apparaissent qu'avec product.manage.
 */
export default function ProductsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base, can } = useBackoffice()
  const [tab, setTab] = useTabParam(TABS, 'produits')
  const canManage = can('product.manage')

  return (
    <>
      <PageHeader
        title={t('backoffice.products.title')}
        actions={
          canManage && tab === 'produits' ? (
            <Button onClick={() => navigate(`${base}/produits/nouveau`)}>{t('backoffice.products.create')}</Button>
          ) : undefined
        }
      />

      <Tabs
        id="catalogue"
        label={t('backoffice.products.tabs.label')}
        tabs={[
          { id: 'produits', label: t('backoffice.products.tabs.products') },
          { id: 'familles', label: t('backoffice.products.tabs.families') },
        ]}
        value={tab}
        onChange={setTab}
      />

      <TabPanel id="catalogue" tab={tab}>
        {tab === 'produits' ? <ProductList /> : <FamiliesPanel canManage={canManage} />}
      </TabPanel>
    </>
  )
}

function ProductList() {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<Omit<ProductFilters, 'search'>>({ family_id: '', is_active: '', page: 1 })
  const debouncedSearch = useDebouncedValue(search)
  const products = useProducts({ ...filters, search: debouncedSearch })
  const families = useFamilies()

  const update = (patch: Partial<ProductFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  return (
    <Card>
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Field label={t('common.search')}>
          {(props) => (
            <Input
              {...props}
              type="search"
              value={search}
              placeholder={t('backoffice.products.searchPlaceholder')}
              onChange={(event) => {
                setSearch(event.target.value)
                update({})
              }}
            />
          )}
        </Field>
        <Field label={t('backoffice.products.columns.family')}>
          {(props) => (
            <Select {...props} value={filters.family_id} onChange={(event) => update({ family_id: event.target.value })}>
              <option value="">{t('common.all')}</option>
              {orderFamilies(families.data?.data ?? []).map((node) => (
                <option key={node.family.id} value={node.family.id}>
                  {familyOptionLabel(node)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.products.columns.status')}>
          {(props) => (
            <Select {...props} value={filters.is_active} onChange={(event) => update({ is_active: event.target.value as ProductFilters['is_active'] })}>
              <option value="">{t('common.all')}</option>
              <option value="1">{t('backoffice.products.statusFilter.active')}</option>
              <option value="0">{t('backoffice.products.statusFilter.inactive')}</option>
            </Select>
          )}
        </Field>
      </div>

      <ErrorAlert error={products.error} />
      {products.isPending && <Spinner />}

      {products.data && (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-start text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                <tr>
                  <th className="py-2 pe-4 text-start font-medium">
                    <span className="sr-only">{t('backoffice.products.columns.photo')}</span>
                  </th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.columns.sku')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.columns.name')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.columns.family')}</th>
                  <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.columns.salesUnit')}</th>
                  <th className="py-2 pe-4 text-end font-medium">{t('backoffice.products.columns.priceHt')}</th>
                  <th className="py-2 pe-4 text-end font-medium">{t('backoffice.products.columns.priceTtc')}</th>
                  <th className="py-2 text-start font-medium">{t('backoffice.products.columns.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {products.data.data.map((product) => (
                  <tr key={product.id} className="hover:bg-slate-50">
                    <td className="py-2 pe-4">
                      <ProductThumbnail product={product} />
                    </td>
                    <td className="py-2 pe-4 font-mono text-xs">{product.sku}</td>
                    <td className="py-2 pe-4">
                      <Link to={`${base}/produits/${product.id}`} className="font-medium text-brand-700 hover:underline">
                        {product.name}
                      </Link>
                      {product.format && <div className="text-xs text-slate-500">{product.format}</div>}
                    </td>
                    <td className="py-2 pe-4">{product.family?.name ?? t('common.none')}</td>
                    <td className="py-2 pe-4">{product.sales_unit_label}</td>
                    <td className="py-2 pe-4 text-end tabular-nums whitespace-nowrap">
                      {formatAmount(product.price?.price_ht)}
                      {product.next_price && (
                        <div className="text-xs text-slate-500">
                          {t('backoffice.products.nextPriceShort', {
                            price: formatAmount(product.next_price.price_ht),
                            date: formatDate(product.next_price.valid_from),
                          })}
                        </div>
                      )}
                    </td>
                    <td className="py-2 pe-4 text-end tabular-nums whitespace-nowrap">{formatAmount(product.price?.price_ttc)}</td>
                    <td className="py-2">
                      <ProductBadges product={product} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {products.data.data.length === 0 && <p className="py-6 text-center text-sm text-slate-500">{t('backoffice.products.empty')}</p>}
          <Pagination meta={products.data.meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </>
      )}
    </Card>
  )
}
