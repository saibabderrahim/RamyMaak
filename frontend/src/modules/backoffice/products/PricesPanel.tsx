import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Product, ProductPrice } from '../../../shared/api/types'
import { businessToday, formatAmount, formatDate, formatDateTime } from '../../../shared/format'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Badge, Button, Card, ErrorAlert, Field, Input, Spinner } from '../../../shared/ui'
import { type PriceInput, useAddPrice, useProductPrices } from './api'
import { formatRate, numericInput } from './numbers'

type PriceState = 'current' | 'future' | 'past'

/** Un seul prix en vigueur à une date donnée (RG-P01) ; valid_to = veille du suivant. */
function priceState(price: ProductPrice, today: string): PriceState {
  if (price.valid_from > today) return 'future'
  if (price.valid_to !== null && price.valid_to < today) return 'past'
  return 'current'
}

const STATE_TONES = { current: 'success', future: 'brand', past: 'neutral' } as const

/**
 * Prix du produit (EF-PRD-03) : prix en vigueur (HT, TTC au taux de la
 * fiche, prix unitaire indicatif), prochain prix programmé, historique et
 * saisie d'un nouveau prix avec sa date d'effet. Montants indicatifs : la
 * facture de l'ERP fait foi (RG-P05).
 */
export function PricesPanel({ product, canManage }: { product: Product; canManage: boolean }) {
  const { t } = useTranslation()
  const prices = useProductPrices(product.id)
  const today = businessToday()
  const { price, next_price: next } = product

  return (
    <Card title={t('backoffice.products.prices.title')}>
      <div className="flex flex-col gap-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <section className="rounded-lg bg-slate-50 p-4" data-testid="current-price">
            <h3 className="mb-1 text-sm font-medium text-slate-600">{t('backoffice.products.prices.current')}</h3>
            {price ? (
              <div className="flex flex-col gap-1 text-sm text-slate-700">
                <p className="text-xl font-bold text-slate-900 tabular-nums">{t('backoffice.products.prices.ht', { price: formatAmount(price.price_ht) })}</p>
                <p className="tabular-nums">
                  {t('backoffice.products.prices.ttc', { price: formatAmount(price.price_ttc) })} ·{' '}
                  {t('backoffice.products.prices.vat', { rate: formatRate(product.vat_rate) })}
                </p>
                <p>{t('backoffice.products.prices.since', { date: formatDate(price.valid_from) })}</p>
                <p className="text-xs text-slate-500">
                  {t('backoffice.products.prices.unit', { price: formatAmount(price.unit_price_ht), units: product.units_per_sales_unit })}
                </p>
              </div>
            ) : (
              <p className="text-sm text-slate-500">{t('backoffice.products.prices.none')}</p>
            )}
          </section>
          <section className="rounded-lg bg-slate-50 p-4" data-testid="next-price">
            <h3 className="mb-1 text-sm font-medium text-slate-600">{t('backoffice.products.prices.next')}</h3>
            <p className="text-sm text-slate-700 tabular-nums">
              {next
                ? t('backoffice.products.prices.nextValue', { price: formatAmount(next.price_ht), date: formatDate(next.valid_from) })
                : t('backoffice.products.prices.noNext')}
            </p>
          </section>
        </div>
        <p className="text-xs text-slate-500">{t('backoffice.products.prices.indicative')}</p>

        {canManage && <PriceForm productId={product.id} />}

        <section>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">{t('backoffice.products.prices.history')}</h3>
          <ErrorAlert error={prices.error} />
          {prices.isPending && <Spinner />}
          {prices.data &&
            (prices.data.data.length === 0 ? (
              <p className="py-4 text-sm text-slate-500">{t('backoffice.products.prices.historyEmpty')}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[600px] text-start text-sm">
                  <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                    <tr>
                      <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.prices.columns.validFrom')}</th>
                      <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.prices.columns.validTo')}</th>
                      <th className="py-2 pe-4 text-end font-medium">{t('backoffice.products.prices.columns.priceHt')}</th>
                      <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.prices.columns.state')}</th>
                      <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.prices.columns.createdBy')}</th>
                      <th className="py-2 text-start font-medium">{t('backoffice.products.prices.columns.createdAt')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100" data-testid="price-history">
                    {prices.data.data.map((entry) => {
                      const state = priceState(entry, today)
                      return (
                        <tr key={entry.id}>
                          <td className="py-2 pe-4">{formatDate(entry.valid_from)}</td>
                          <td className="py-2 pe-4">{entry.valid_to ? formatDate(entry.valid_to) : t('backoffice.products.prices.openEnded')}</td>
                          <td className="py-2 pe-4 text-end tabular-nums whitespace-nowrap">{formatAmount(entry.price_ht)}</td>
                          <td className="py-2 pe-4">
                            <Badge tone={STATE_TONES[state]}>{t(`backoffice.products.prices.state.${state}`)}</Badge>
                          </td>
                          <td className="py-2 pe-4">{entry.created_by?.name ?? t('common.none')}</td>
                          <td className="py-2 text-slate-600">{formatDateTime(entry.created_at)}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ))}
        </section>
      </div>
    </Card>
  )
}

type PriceFormValues = { price_ht: string; valid_from: string }

const PRICE_FIELDS = ['price_ht', 'valid_from'] as const

/** Nouveau prix HT par UV avec sa date d'effet, aujourd'hui par défaut (jour métier). */
function PriceForm({ productId }: { productId: number }) {
  const { t } = useTranslation()
  const addPrice = useAddPrice(productId)

  const schema = z.object({
    price_ht: z
      .string()
      .transform(numericInput)
      .pipe(z.string().regex(/^\d{1,10}(\.\d{1,2})?$/, t('backoffice.products.prices.invalidAmount')))
      .transform(Number),
    valid_from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, t('backoffice.products.prices.invalidDate')),
  })

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<PriceFormValues, unknown, PriceInput>({
    resolver: zodResolver(schema),
    defaultValues: { price_ht: '', valid_from: businessToday() },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await addPrice.mutateAsync(values)
      reset({ price_ht: '', valid_from: businessToday() })
    } catch (error) {
      applyServerErrors(error, setError, PRICE_FIELDS)
    }
  })

  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4">
      <h3 className="text-sm font-semibold text-slate-900">{t('backoffice.products.prices.add')}</h3>
      {addPrice.isSuccess && <Alert tone="success">{t('backoffice.products.prices.added')}</Alert>}
      {!hasFieldErrors && <ErrorAlert error={addPrice.error} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('backoffice.products.prices.priceHt')} error={errors.price_ht?.message}>
          {(props) => <Input {...props} {...register('price_ht')} inputMode="decimal" autoComplete="off" />}
        </Field>
        <Field label={t('backoffice.products.prices.validFrom')} hint={t('backoffice.products.prices.validFromHint')} error={errors.valid_from?.message}>
          {(props) => <Input {...props} {...register('valid_from')} type="date" />}
        </Field>
      </div>
      <Button type="submit" className="self-start" disabled={addPrice.isPending}>
        {addPrice.isPending ? t('common.saving') : t('backoffice.products.prices.submit')}
      </Button>
    </form>
  )
}
