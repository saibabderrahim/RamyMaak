import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Product } from '../../../shared/api/types'
import { Button, Field, Input, Select } from '../../../shared/ui'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { useProduct } from '../products/api'
import { useRegionOptions } from '../regions/api'
import { useProductChoices } from './api'
import { activeFilters, QUEUE_SORTS, type QueueParams, type QueueSort } from './model'

/** « P0042 — Lben 1 L » : ce que la liste de choix propose et ce que le filtre affiche. */
const productLabel = (product: Pick<Product, 'sku' | 'short_name' | 'name'>) => `${product.sku} — ${product.short_name || product.name}`

/**
 * Filtre produit : saisie du code article ou du nom, choix dans la liste
 * proposée par le serveur (`datalist`) ; une fois choisi, le produit est
 * affiché avec « Retirer ». L'adresse ne garde que l'identifiant.
 */
function ProductFilter({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const term = useDebouncedValue(text.trim())
  const options = useProductChoices(term)
  const selected = useProduct(Number(value))
  const choices = options.data ?? []

  if (value !== '') {
    const label = selected.data ? productLabel(selected.data) : `#${value}`
    return (
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium text-slate-800">{t('backoffice.orders.filters.product')}</span>
        <div className="flex min-h-11 items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm">
          <span className="min-w-0 truncate" title={label}>
            {label}
          </span>
          <Button variant="ghost" className="shrink-0" aria-label={t('backoffice.orders.filters.productRemove')} onClick={() => onChange('')}>
            <span aria-hidden="true">×</span>
          </Button>
        </div>
      </div>
    )
  }

  return (
    <Field label={t('backoffice.orders.filters.product')}>
      {(props) => {
        const listId = `${props.id}-products`
        return (
          <>
            <Input
              {...props}
              type="search"
              list={listId}
              value={text}
              autoComplete="off"
              placeholder={t('backoffice.orders.filters.productPlaceholder')}
              onChange={(event) => {
                const next = event.target.value
                setText(next)
                const chosen = choices.find((product) => productLabel(product) === next)
                if (chosen) {
                  setText('')
                  onChange(String(chosen.id))
                }
              }}
            />
            <datalist id={listId}>
              {choices.map((product) => (
                <option key={product.id} value={productLabel(product)} />
              ))}
            </datalist>
          </>
        )
      }}
    </Field>
  )
}

type Props = {
  params: QueueParams
  search: string
  onSearch: (search: string) => void
  onChange: (patch: Partial<QueueParams>) => void
  onReset: () => void
}

/** Filtres de la file : journée, région, dépôt, client ou numéro, produit, tri (EF-ADV-04). */
export function QueueFilters({ params, search, onSearch, onChange, onReset }: Props) {
  const { t } = useTranslation()
  const regions = useRegionOptions()
  const depots = new Map<number, { id: number; code: string; name: string }>()
  for (const region of regions.data ?? []) {
    if (region.depot) depots.set(region.depot.id, region.depot)
  }

  return (
    <div className="mb-4 flex flex-col gap-3">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Field label={t('backoffice.orders.filters.day')}>
          {(props) => <Input {...props} type="date" value={params.day} onChange={(event) => onChange({ day: event.target.value })} />}
        </Field>
        <Field label={t('backoffice.orders.filters.region')}>
          {(props) => (
            <Select {...props} value={params.region} onChange={(event) => onChange({ region: event.target.value })}>
              <option value="">{t('backoffice.orders.filters.allRegions')}</option>
              {params.region !== '' && !regions.data?.some((region) => String(region.id) === params.region) && (
                <option value={params.region}>{`#${params.region}`}</option>
              )}
              {regions.data?.map((region) => (
                <option key={region.id} value={region.id}>
                  {`${region.code} — ${region.name}`}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.orders.filters.depot')}>
          {(props) => (
            <Select {...props} value={params.depot} onChange={(event) => onChange({ depot: event.target.value })}>
              <option value="">{t('backoffice.orders.filters.allDepots')}</option>
              <option value="none">{t('backoffice.orders.filters.noDepot')}</option>
              {params.depot !== '' && params.depot !== 'none' && !depots.has(Number(params.depot)) && (
                <option value={params.depot}>{`#${params.depot}`}</option>
              )}
              {[...depots.values()].map((depot) => (
                <option key={depot.id} value={depot.id}>
                  {`${depot.code} — ${depot.name}`}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.orders.filters.search')}>
          {(props) => (
            <Input
              {...props}
              type="search"
              value={search}
              placeholder={t('backoffice.orders.filters.searchPlaceholder')}
              maxLength={100}
              onChange={(event) => onSearch(event.target.value)}
            />
          )}
        </Field>
        <ProductFilter value={params.product} onChange={(product) => onChange({ product })} />
        <Field label={t('backoffice.orders.filters.sort')}>
          {(props) => (
            <Select {...props} value={params.sort} onChange={(event) => onChange({ sort: event.target.value as QueueSort })}>
              {QUEUE_SORTS.map((sort) => (
                <option key={sort} value={sort}>
                  {t(`backoffice.orders.sorts.${sort}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
      {activeFilters(params, search) > 0 && (
        <div>
          <Button variant="ghost" onClick={onReset}>
            {t('backoffice.orders.filters.reset')}
          </Button>
        </div>
      )}
    </div>
  )
}
