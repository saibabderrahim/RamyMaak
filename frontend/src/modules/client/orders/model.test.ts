import { describe, expect, it } from 'vitest'
import type { OrderHistoryEntry } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { reasonLabel } from '../../../shared/reasons/labels'
import { MAX_LINE_QUANTITY } from '../../../shared/ui/QuantityStepper'
import { hasChanges, planReorder, reorderBlock } from './reorder'
import { closedOrder, fraise, lbenLine, makeLine, makeOrder, makeProduct, makeToday, orange, orangeLine, pomme, pommeLine } from './testing'
import { timelineSteps } from './timeline'
import { formatTime, historyLabelKey, locationNotice, pageParam } from './view'

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
