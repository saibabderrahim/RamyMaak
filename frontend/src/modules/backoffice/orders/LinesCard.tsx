import { useTranslation } from 'react-i18next'
import type { AdvOrder, AdvOrderLine } from '../../../shared/api/types'
import { formatAmount, formatQuantity } from '../../../shared/format'
import { ReasonSelect, reasonText } from '../../../shared/reasons'
import { Alert, Button, Card, ErrorAlert, Field, Input } from '../../../shared/ui'
import { DataTable, TableHead, Th } from '../shared/ui'
import { gapReasonValue, parseQuantity, shippedValue } from './model'
import type { ShippingDraft } from './useShippingDraft'

type Props = {
  order: AdvOrder
  draft: ShippingDraft
  /** Refus du serveur à l'enregistrement : champs en cause (422) ou message. */
  error: unknown
  fieldErrors: Record<string, string>
  saving: boolean
  online: boolean
  onSave: () => void
}

/**
 * Lignes de la commande : demandé, validé (avec le motif d'ajustement), expédié
 * et motif d'écart, prix figés. Les quantités expédiées sont saisissables en
 * préparation et une fois préparée, le bon de livraison jusqu'à l'expédition
 * (`can` du serveur) ; le reste est en lecture. Enregistrement d'un seul bloc
 * (PATCH partiel), tout contrôle étant rejoué par le serveur.
 */
export function LinesCard({ order, draft, error, fieldErrors, saving, online, onSave }: Props) {
  const { t } = useTranslation()
  const editLines = order.can.edit_shipping_lines
  const editNote = order.can.edit_delivery_note
  const editable = editLines || editNote
  const showShipping = order.lines.some((line) => line.shipped_qty !== null) || editLines
  const hasFieldErrors = Object.keys(fieldErrors).length > 0

  return (
    <Card title={editable ? t('backoffice.orders.shipping.title') : t('backoffice.orders.lines.title')}>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          if (draft.dirty && draft.valid) onSave()
        }}
      >
        {editable && <p className="text-sm text-slate-600">{editLines ? t('backoffice.orders.shipping.intro') : t('backoffice.orders.shipping.introNote')}</p>}
        {draft.stale && <Alert tone="warning">{t('backoffice.orders.shipping.stale')}</Alert>}
        {!hasFieldErrors && <ErrorAlert error={error} />}

        <DataTable label={t('backoffice.orders.lines.label')} className="min-w-[900px]">
          <TableHead>
            <Th>{t('backoffice.orders.lines.product')}</Th>
            <Th className="text-end">{t('backoffice.orders.lines.requested')}</Th>
            <Th className="text-end">{t('backoffice.orders.lines.validated')}</Th>
            {showShipping && <Th className="text-end">{t('backoffice.orders.lines.shipped')}</Th>}
            {showShipping && <Th>{t('backoffice.orders.lines.gap')}</Th>}
            <Th className="text-end">{t('backoffice.orders.lines.unitPrice')}</Th>
            <Th className="text-end">{t('backoffice.orders.lines.amount')}</Th>
          </TableHead>
          <tbody className="divide-y divide-slate-100">
            {order.lines.map((line) => (
              <LineRow
                key={line.product_id}
                line={line}
                draft={draft}
                editLines={editLines}
                showShipping={showShipping}
                serverError={fieldErrors[String(line.product_id)]}
              />
            ))}
          </tbody>
        </DataTable>

        {editNote && (
          <div className="max-w-sm">
            <Field label={t('backoffice.orders.shipping.deliveryNote')} hint={t('backoffice.orders.shipping.deliveryNoteHint')} error={fieldErrors.note}>
              {(props) => <Input {...props} value={draft.note} maxLength={50} onChange={(event) => draft.setNote(event.target.value)} />}
            </Field>
          </div>
        )}
        {!editNote && order.delivery_note_ref && (
          <p className="text-sm text-slate-700">
            <span className="font-medium">{t('backoffice.orders.shipping.deliveryNote')}</span> : {order.delivery_note_ref}
          </p>
        )}

        {editable && (
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={!draft.dirty || !draft.valid || saving || !online}>
              {saving ? t('backoffice.orders.shipping.saving') : t('backoffice.orders.shipping.save')}
            </Button>
            {draft.dirty && (
              <>
                <Button variant="ghost" onClick={draft.reset} disabled={saving}>
                  {t('backoffice.orders.shipping.discard')}
                </Button>
                <span className="text-sm text-amber-800">{draft.valid ? t('backoffice.orders.shipping.dirty') : t('backoffice.orders.shipping.fix')}</span>
              </>
            )}
          </div>
        )}
      </form>
    </Card>
  )
}

function LineRow({
  line,
  draft,
  editLines,
  showShipping,
  serverError,
}: {
  line: AdvOrderLine
  draft: ShippingDraft
  editLines: boolean
  showShipping: boolean
  serverError: string | undefined
}) {
  const { t } = useTranslation()
  const validated = line.validated_qty ?? 0
  const reduced = line.validated_qty !== null && line.validated_qty < line.requested_qty
  const edit = draft.edits[line.product_id]
  const qtyText = shippedValue(line, edit)
  const qty = parseQuantity(qtyText)
  const reason = gapReasonValue(line, edit)
  const problem = draft.problems[line.product_id]
  const message = problem ? t(`backoffice.orders.shipping.problems.${problem}`) : serverError
  const adjustment = reasonText(line.adjustment_reason, line.adjustment_reason_code)
  const gap = reasonText(line.shipping_gap_reason)

  return (
    <tr data-sku={line.sku}>
      <th scope="row" className="py-2 pe-4 text-start align-top font-normal">
        <span className="block font-medium text-slate-900">{line.name}</span>
        <span className="block text-xs text-slate-600">
          {line.sku} · {line.sales_unit_label}
        </span>
      </th>
      <td className="py-2 pe-4 text-end align-top tabular-nums">{formatQuantity(line.requested_qty)}</td>
      <td className="py-2 pe-4 text-end align-top tabular-nums">
        <span className={reduced ? 'font-semibold text-amber-800' : undefined}>{line.validated_qty === null ? '—' : formatQuantity(line.validated_qty)}</span>
        {reduced && (
          <span className="block text-xs font-normal text-amber-800">
            {t('backoffice.orders.lines.reduced', { from: formatQuantity(line.requested_qty), to: formatQuantity(validated) })}
            {adjustment && <> · {t('backoffice.orders.lines.adjustmentReason', { reason: adjustment })}</>}
            {line.adjustment_comment && <q className="block italic">{line.adjustment_comment}</q>}
          </span>
        )}
      </td>
      {showShipping && (
        <td className="py-2 pe-4 text-end align-top">
          {editLines ? (
            <div className="flex flex-col items-end gap-1">
              <Input
                type="text"
                inputMode="numeric"
                className="w-28 text-end tabular-nums"
                aria-label={t('backoffice.orders.shipping.shippedQty', { name: line.name })}
                aria-invalid={Boolean(message)}
                value={qtyText}
                onChange={(event) => draft.setQty(line, event.target.value)}
              />
              {message && <span className="max-w-48 text-xs text-red-700">{message}</span>}
            </div>
          ) : (
            <span className="tabular-nums">{line.shipped_qty === null ? '—' : formatQuantity(line.shipped_qty)}</span>
          )}
        </td>
      )}
      {showShipping && (
        <td className="py-2 pe-4 align-top">
          {editLines ? (
            qty !== null && qty < validated ? (
              <ReasonSelect
                type="SHIPPING_GAP"
                current={line.shipping_gap_reason}
                aria-label={t('backoffice.orders.shipping.gapReason', { name: line.name })}
                value={reason}
                onChange={(event) => draft.setReason(line, event.target.value)}
              />
            ) : (
              <span className="text-slate-500">—</span>
            )
          ) : (
            (gap ?? <span className="text-slate-500">—</span>)
          )}
        </td>
      )}
      <td className="py-2 pe-4 text-end align-top whitespace-nowrap tabular-nums">{formatAmount(line.unit_price_ht)}</td>
      <td className="py-2 pe-4 text-end align-top whitespace-nowrap tabular-nums">{formatAmount(line.amount_ht)}</td>
    </tr>
  )
}
