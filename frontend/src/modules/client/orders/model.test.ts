import { describe, expect, it } from 'vitest'
import type { OrderHistoryEntry } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { reasonLabel, reasonText } from '../../../shared/reasons/labels'
import { MAX_LINE_QUANTITY } from '../../../shared/ui/QuantityStepper'
import { hasChanges, planReorder, reorderBlock } from './reorder'
import { closedOrder, fraise, lbenLine, makeLine, makeOrder, makeProduct, makeToday, orange, orangeLine, pomme, pommeLine } from './testing'
import { timelineSteps } from './timeline'
import {
  cancelledByAdv,
  decisionInstant,
  decisionKind,
  formatTime,
  hasShippingGap,
  historyLabelKey,
  isAccepted,
  isReduced,
  isShipped,
  locationNotice,
  pageParam,
  reducedCount,
} from './view'

const entry = (from: OrderHistoryEntry['from'], to: OrderHistoryEntry['to'], at: string, version = 1): OrderHistoryEntry => ({
  from,
  to,
  version,
  at,
  actor: 'SYSTEM',
  by_name: null,
  comment: null,
})

const SENT = entry(null, 'SUBMITTED', '2026-10-08T08:40:00+00:00')
const steps = (order: Parameters<typeof timelineSteps>[0]) => timelineSteps(order).map((step) => `${step.status}:${step.state}${step.terminal ? '!' : ''}`)

describe('status timeline (§6.2)', () => {
  it('marks Envoyée as the current step of a submitted order, the rest of the path ahead', () => {
    const order = makeOrder({ history: [SENT, entry('SUBMITTED', 'SUBMITTED', '2026-10-08T09:10:00+00:00', 2)] })
    expect(steps(order)).toEqual(['SUBMITTED:current', 'VALIDATED:upcoming', 'IN_PREPARATION:upcoming', 'PREPARED:upcoming', 'SHIPPED:upcoming'])
    // Une modification ne change pas la date de l'envoi.
    expect(timelineSteps(order)[0]!.at).toBe('2026-10-08T08:40:00+00:00')
  })

  it('dates each step reached from the history', () => {
    const order = closedOrder({
      status: 'IN_PREPARATION',
      history: [SENT, entry('SUBMITTED', 'VALIDATED', '2026-10-08T10:00:00+00:00', 2), entry('VALIDATED', 'IN_PREPARATION', '2026-10-08T15:00:00+00:00', 3)],
    })
    expect(steps(order)).toEqual(['SUBMITTED:done', 'VALIDATED:done', 'IN_PREPARATION:current', 'PREPARED:upcoming', 'SHIPPED:upcoming'])
    expect(timelineSteps(order).map((step) => step.at)).toEqual([
      '2026-10-08T08:40:00+00:00',
      '2026-10-08T10:00:00+00:00',
      '2026-10-08T15:00:00+00:00',
      null,
      null,
    ])
  })

  it('ends at Expédiée, and adds Livrée once delivered (V2)', () => {
    expect(steps(closedOrder({ status: 'SHIPPED' })).at(-1)).toBe('SHIPPED:current')
    expect(steps(closedOrder({ status: 'DELIVERED' }))).toEqual([
      'SUBMITTED:done',
      'VALIDATED:done',
      'IN_PREPARATION:done',
      'PREPARED:done',
      'SHIPPED:done',
      'DELIVERED:current',
    ])
  })

  it('branches to Rejetée or Expirée right after Envoyée', () => {
    const rejected = closedOrder({ status: 'REJECTED', history: [SENT, entry('SUBMITTED', 'REJECTED', '2026-10-08T10:00:00+00:00', 2)] })
    expect(steps(rejected)).toEqual(['SUBMITTED:done', 'REJECTED:current!'])
    expect(timelineSteps(rejected)[1]!.at).toBe('2026-10-08T10:00:00+00:00')
    expect(steps(closedOrder({ status: 'EXPIRED', history: [SENT, entry('SUBMITTED', 'EXPIRED', '2026-10-08T23:00:00+00:00', 2)] }))).toEqual([
      'SUBMITTED:done',
      'EXPIRED:current!',
    ])
  })

  it('cancels from the step the order had reached (client before the decision, ADV after the validation)', () => {
    expect(steps(closedOrder({ status: 'CANCELLED', history: [SENT, entry('SUBMITTED', 'CANCELLED', '2026-10-08T09:00:00+00:00', 2)] }))).toEqual([
      'SUBMITTED:done',
      'CANCELLED:current!',
    ])
    const byAdv = closedOrder({
      status: 'CANCELLED',
      history: [SENT, entry('SUBMITTED', 'VALIDATED', '2026-10-08T10:00:00+00:00', 2), entry('VALIDATED', 'CANCELLED', '2026-10-08T14:00:00+00:00', 3)],
    })
    expect(steps(byAdv)).toEqual(['SUBMITTED:done', 'VALIDATED:done', 'CANCELLED:current!'])
    // Historique absent : la fin part de l'envoi.
    expect(steps(closedOrder({ status: 'CANCELLED', history: [] }))).toEqual(['SUBMITTED:done', 'CANCELLED:current!'])
  })
})

describe('Recommander (EF-CLT-05)', () => {
  it('keeps the requested quantities, caps them at the available quantity of a bounded product, skips the others', () => {
    const lines = [orangeLine, pommeLine, lbenLine, makeLine({ product_id: 58, name: 'Jus fraise (ancien nom)', requested_qty: 5 })]
    const plan = planReorder(lines, [orange, pomme, fraise])
    expect(plan.lines).toEqual([
      { product_id: 42, quantity: 30 },
      { product_id: 57, quantity: 25 },
    ])
    expect(plan.capped).toEqual([{ product_id: 42, name: 'Jus orange 1 L', from: 40, to: 30 }])
    expect(plan.skipped).toEqual([
      { product_id: 61, name: 'Lben 1 L', reason: 'unavailable' },
      { product_id: 58, name: 'Jus fraise 1 L', reason: 'soldOut' },
    ])
    expect(hasChanges(plan)).toBe(true)
  })

  it('keeps a quantity within the available one, and caps an unbounded one at the input limit only', () => {
    const plan = planReorder(
      [makeLine({ product_id: 42, name: 'Jus orange 1 L', requested_qty: 30 }), makeLine({ product_id: 57, name: 'Jus pomme 1 L', requested_qty: 5_000 })],
      [orange, makeProduct({ id: 57, name: 'Jus pomme 1 L' })],
    )
    expect(plan.lines).toEqual([
      { product_id: 42, quantity: 30 },
      { product_id: 57, quantity: 5_000 },
    ])
    expect(hasChanges(plan)).toBe(false)
    expect(planReorder([makeLine({ product_id: 57, name: 'Pomme', requested_qty: MAX_LINE_QUANTITY + 5 })], [pomme]).lines).toEqual([
      { product_id: 57, quantity: MAX_LINE_QUANTITY },
    ])
  })

  it('reorders only on an open day where a new order can be sent, and says why otherwise', () => {
    expect(reorderBlock(makeToday())).toBeNull()
    expect(reorderBlock(makeToday({ can_submit: false, submit_blocker: 'CLIENT_BLOCKED' }))).toBe('CLIENT_BLOCKED')
    expect(reorderBlock(makeToday({ status: 'WAITING_QUOTAS', can_submit: false, submit_blocker: 'DAY_NOT_OPEN' }))).toBe('WAITING_QUOTAS')
    expect(reorderBlock(makeToday({ status: 'DAY_CLOSED', can_submit: false, submit_blocker: 'DAY_NOT_OPEN' }))).toBe('DAY_CLOSED')
    expect(reorderBlock(makeToday({ status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED' }))).toBe('CUTOFF_PASSED')
    expect(reorderBlock(makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED' }))).toBe('MAX_ORDERS_REACHED')
  })
})

describe('formatting', () => {
  it('reads a reason code, a transition, an hour in the business time zone, a page and a notice', () => {
    expect(reasonLabel('QUOTA_INSUFFISANT')).toBe('Quota insuffisant')
    expect(historyLabelKey({ from: null, to: 'SUBMITTED' })).toBe('orders.clientOrders.history.created')
    expect(historyLabelKey({ from: 'SUBMITTED', to: 'SUBMITTED' })).toBe('orders.clientOrders.history.modified')
    expect(historyLabelKey({ from: 'SUBMITTED', to: 'REJECTED' })).toBe('orders.clientOrders.history.to.REJECTED')
    expect(formatTime('2026-10-08T12:00:00+00:00')).toBe('13:00')
    expect(formatQuantity(12500)).toBe('12 500')
    expect(pageParam('3')).toBe(3)
    expect(pageParam('0')).toBe(1)
    expect(pageParam('abc')).toBe(1)
    expect(pageParam(null)).toBe(1)
    expect(locationNotice({ notice: 'Commande envoyée.' })).toBe('Commande envoyée.')
    expect(locationNotice({ sheet: true })).toBeNull()
    expect(locationNotice(null)).toBeNull()
  })
})

describe('decision and shipping display (lot 6)', () => {
  const rejection = { code: 'QUOTA_EPUISE', label: 'Quota épuisé' }

  it('picks the decision to show: rejection, ADV cancellation, adjusted validation, a commented decision, or nothing', () => {
    const kind = (overrides: Parameters<typeof closedOrder>[0]) => decisionKind(closedOrder(overrides))

    expect(kind({ status: 'REJECTED', decision_reason: rejection, decision_reason_code: 'QUOTA_EPUISE' })).toBe('REJECTED')
    expect(kind({ status: 'REJECTED' })).toBeNull()
    expect(kind({ status: 'CANCELLED', decision_reason: { code: 'RUPTURE', label: 'Rupture de stock' } })).toBe('CANCELLED')
    // Annulée par le client lui-même : pas de motif, rien à dire.
    expect(kind({ status: 'CANCELLED' })).toBeNull()
    expect(kind({ status: 'VALIDATED', is_adjusted: true })).toBe('ADJUSTED')
    expect(kind({ status: 'SHIPPED', is_adjusted: true, decision_comment: 'Reste limité' })).toBe('ADJUSTED')
    expect(kind({ status: 'VALIDATED', decision_comment: 'Livraison lundi' })).toBe('other')
    expect(kind({ status: 'VALIDATED' })).toBeNull()
    expect(kind({ status: 'SUBMITTED', is_adjusted: false })).toBeNull()
    // Un code de motif sans libellé (donnée antérieure au lot 6) suffit à montrer le motif.
    expect(kind({ status: 'REJECTED', decision_reason_code: 'DOUBLON' })).toBe('REJECTED')
  })

  it('dates an ADV cancellation by its history entry, not by the validation kept in decided_at (I-75)', () => {
    const cancelled = closedOrder({
      status: 'CANCELLED',
      decided_at: '2026-10-08T10:15:00+00:00',
      history: [
        SENT,
        { ...entry('SUBMITTED', 'VALIDATED', '2026-10-08T10:15:00+00:00', 2), actor: 'REGIONAL' },
        { ...entry('VALIDATED', 'CANCELLED', '2026-10-09T08:00:00+00:00', 3), actor: 'ADV' },
      ],
    })
    expect(decisionInstant(cancelled)).toBe('2026-10-09T08:00:00+00:00')
    expect(cancelledByAdv(cancelled)).toBe(true)

    const byClient = closedOrder({ status: 'CANCELLED', history: [SENT, { ...entry('SUBMITTED', 'CANCELLED', '2026-10-08T09:30:00+00:00', 2), actor: 'CLIENT' }] })
    expect(cancelledByAdv(byClient)).toBe(false)
    expect(decisionInstant(closedOrder({ status: 'REJECTED', decided_at: '2026-10-08T10:15:00+00:00' }))).toBe('2026-10-08T10:15:00+00:00')
    expect(decisionInstant(makeOrder())).toBeNull()
  })

  it('knows when the order is accepted, shipped, and which lines are reduced or short-shipped', () => {
    expect((['VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'DELIVERED'] as const).every(isAccepted)).toBe(true)
    expect((['SUBMITTED', 'REJECTED', 'CANCELLED', 'EXPIRED'] as const).some(isAccepted)).toBe(false)
    expect(['SHIPPED', 'DELIVERED'].map((status) => isShipped(status as 'SHIPPED'))).toEqual([true, true])
    expect((['VALIDATED', 'PREPARED'] as const).some(isShipped)).toBe(false)

    const full = makeLine({ product_id: 1, name: 'Plein', requested_qty: 10, validated_qty: 10, shipped_qty: 10 })
    const cut = makeLine({ product_id: 2, name: 'Réduit', requested_qty: 10, validated_qty: 6, shipped_qty: 4 })
    const unseen = makeLine({ product_id: 3, name: 'Non décidé', requested_qty: 10 })
    expect([full, cut, unseen].map(isReduced)).toEqual([false, true, false])
    expect([full, cut, unseen].map(hasShippingGap)).toEqual([false, true, false])
    expect(reducedCount([full, cut, unseen])).toBe(1)
  })

  it('reads the label of a reason, the humanised code when the server gives none, nothing without a reason', () => {
    expect(reasonText(rejection)).toBe('Quota épuisé')
    expect(reasonText(null, 'QUOTA_INSUFFISANT')).toBe('Quota insuffisant')
    expect(reasonText(null, null)).toBeNull()
    expect(reasonText(undefined)).toBeNull()
  })
})
