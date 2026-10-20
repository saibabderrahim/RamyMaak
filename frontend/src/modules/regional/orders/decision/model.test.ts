import { describe, expect, it } from 'vitest'
import { ApiError } from '../../../../shared/api/client'
import type { DecisionViolation, RegionalOrder, RegionalOrderLine } from '../../../../shared/api/types'
import { makeOrder, REASONS, summary } from '../testing'
import {
  adjustedCount,
  blockerOf,
  buildValidateBody,
  type Draft,
  initialDraft,
  isAboveMax,
  isAllZero,
  isReduced,
  isStale,
  limitRemainingAfter,
  lineIssues,
  lineProblems,
  maxOf,
  oldestSubmitted,
  quantityOf,
  regionRemainingAfter,
  setComment,
  setQuantity,
  setReason,
  violationsOf,
  withMaximum,
} from './model'

// Jus orange : demandé 40, borne 50, déjà validé 0, reste région 1 500, maximum 40.
// Lben : demandé 1 250, pas de borne, déjà validé 30, reste région 720, maximum 720.
const order = makeOrder()
const [orange, lben] = order.lines as [RegionalOrderLine, RegionalOrderLine]
const adjustmentReasons = REASONS.filter((reason) => reason.type === 'ADJUSTMENT')

const problem = (status: number, code: string, extra: Record<string, unknown> = {}) => new ApiError({ status, code, ...extra })

describe('decision draft', () => {
  it('starts from the order: every line validated as requested, nothing to explain', () => {
    const draft = initialDraft(order)
    expect(draft.version).toBe(2)
    expect(order.lines.map((line) => quantityOf(draft, line))).toEqual([40, 1250])
    expect(adjustedCount(order, draft)).toBe(0)
    expect(isAllZero(order, draft)).toBe(false)
    expect(lineProblems(order, draft, adjustmentReasons).size).toBe(0)
  })

  it('is untouched until the regional enters something', () => {
    const draft = initialDraft(order)
    expect(draft.touched).toBe(false)
    expect(setQuantity(draft, orange, 10).touched).toBe(true)
    expect(setReason(draft, orange, 'AUTRE').touched).toBe(true)
    expect(setComment(draft, orange, 'x').touched).toBe(true)
    expect(withMaximum(order, draft).touched).toBe(true)
    expect(draft.touched).toBe(false)
  })

  it('is stale as soon as the version of the order is not the one of the draft (the client modified it)', () => {
    const draft = initialDraft(order)
    expect(isStale(draft, order)).toBe(false)
    expect(isStale(draft, { version: 3 })).toBe(true)
  })

  it('keeps a quantity between 0 and the requested quantity', () => {
    let draft = initialDraft(order)
    draft = setQuantity(draft, orange, 25)
    expect(quantityOf(draft, orange)).toBe(25)
    draft = setQuantity(draft, orange, 400)
    expect(quantityOf(draft, orange)).toBe(40)
    draft = setQuantity(draft, orange, -3)
    expect(quantityOf(draft, orange)).toBe(0)
    draft = setQuantity(draft, orange, 12.9)
    expect(quantityOf(draft, orange)).toBe(12)
    // La saisie est clampée aussi à la lecture : un brouillon plus ancien ne dépasse jamais le demandé.
    const loose: Draft = { version: 2, touched: true, lines: { 42: { quantity: 999, reason: '', comment: '' } } }
    expect(quantityOf(loose, orange)).toBe(40)
    expect(quantityOf({ version: 2, touched: false, lines: {} }, lben)).toBe(1250)
  })

  it('does not change the other lines nor the draft it was given', () => {
    const draft = initialDraft(order)
    const next = setQuantity(draft, orange, 10)
    expect(quantityOf(draft, orange)).toBe(40)
    expect(quantityOf(next, lben)).toBe(1250)
  })

  it('counts the reduced lines, whatever the reason', () => {
    let draft = setQuantity(initialDraft(order), orange, 39)
    expect(isReduced(orange, 39)).toBe(true)
    expect(isReduced(orange, 40)).toBe(false)
    expect(adjustedCount(order, draft)).toBe(1)
    draft = setQuantity(draft, lben, 0)
    expect(adjustedCount(order, draft)).toBe(2)
  })

  it('detects an order whose every line is at 0 (RG-V05: reject it)', () => {
    let draft = setQuantity(initialDraft(order), orange, 0)
    expect(isAllZero(order, draft)).toBe(false)
    draft = setQuantity(draft, lben, 0)
    expect(isAllZero(order, draft)).toBe(true)
    expect(isAllZero({ lines: [] }, draft)).toBe(false)
  })

  it('keeps the reason and the comment typed when the quantity goes back up', () => {
    let draft = setQuantity(initialDraft(order), orange, 30)
    draft = setReason(draft, orange, 'QUOTA_INSUFFISANT')
    draft = setComment(draft, orange, 'Reste limité')
    draft = setQuantity(draft, orange, 40)
    expect(buildValidateBody(order, draft).lines[0]).toEqual({ product_id: 42, validated_qty: 40 })
    draft = setQuantity(draft, orange, 20)
    expect(draft.lines[42]).toMatchObject({ quantity: 20, reason: 'QUOTA_INSUFFISANT', comment: 'Reste limité' })
  })

  it('cuts the comment at 200 characters', () => {
    const draft = setComment(initialDraft(order), orange, 'x'.repeat(250))
    expect(draft.lines[42]?.comment).toHaveLength(200)
  })
})

describe('maximum of a line and remaining after validation', () => {
  it('takes the maximum of the server, never above the requested quantity', () => {
    expect(maxOf(orange)).toBe(40)
    expect(maxOf(lben)).toBe(720)
    expect(maxOf({ ...orange, context: { ...orange.context, max_validatable: 900 } })).toBe(40)
    expect(maxOf({ ...orange, context: { ...orange.context, max_validatable: 0 } })).toBe(0)
    expect(maxOf({ ...orange, context: { ...orange.context, max_validatable: -4 } })).toBe(0)
  })

  it('flags a quantity above the maximum validable', () => {
    expect(isAboveMax(lben, 720)).toBe(false)
    expect(isAboveMax(lben, 721)).toBe(true)
    expect(isAboveMax(orange, 40)).toBe(false)
  })

  it('computes the remaining of the region and of the limit once the quantity is validated', () => {
    expect(regionRemainingAfter(orange, 40)).toBe(1460)
    expect(regionRemainingAfter(lben, 1250)).toBe(-530)
    expect(regionRemainingAfter(lben, 720)).toBe(0)
    // B − V(c,p) − q ; sans borne, rien à calculer.
    expect(limitRemainingAfter(orange, 40)).toBe(10)
    expect(limitRemainingAfter(orange, 0)).toBe(50)
    expect(limitRemainingAfter(lben, 100)).toBeNull()
  })

  it('applies the maximum validable to every line (« Ajuster au maximum possible »), reasons still to choose', () => {
    let draft = setReason(setQuantity(initialDraft(order), orange, 10), orange, 'BORNE_ATTEINTE')
    draft = withMaximum(order, draft)
    expect(order.lines.map((line) => quantityOf(draft, line))).toEqual([40, 720])
    // Le motif déjà choisi reste, la ligne de Lben est réduite : son motif manque.
    expect(draft.lines[42]?.reason).toBe('BORNE_ATTEINTE')
    expect([...lineProblems(order, draft, adjustmentReasons).keys()]).toEqual([57])
  })

  it('sets every line to its maximum, a line already reduced by hand included, and never above the requested quantity', () => {
    const constrained: RegionalOrder = {
      ...order,
      lines: [
        { ...orange, context: { ...orange.context, max_validatable: 15 } },
        { ...lben, context: { ...lben.context, max_validatable: 900 } },
      ],
    }
    const draft = withMaximum(constrained, setQuantity(initialDraft(constrained), lben, 100))
    expect(constrained.lines.map((line) => quantityOf(draft, line))).toEqual([15, 900])
    const roomy = withMaximum(constrained, initialDraft(constrained))
    expect(constrained.lines.map((line) => quantityOf(roomy, line))).toEqual([15, 900])
  })
})

describe('what is missing on a reduced line', () => {
  it('asks for a reason on every reduced line, none on the others', () => {
    const draft = setQuantity(setQuantity(initialDraft(order), orange, 30), lben, 0)
    expect(lineProblems(order, draft, adjustmentReasons)).toEqual(
      new Map([
        [42, { reason: true, comment: false }],
        [57, { reason: true, comment: false }],
      ]),
    )
    expect(lineProblems(order, setReason(draft, orange, 'BORNE_ATTEINTE'), adjustmentReasons).has(42)).toBe(false)
  })

  it('asks for a comment when the reason requires one (AUTRE), blank text included', () => {
    let draft = setReason(setQuantity(initialDraft(order), orange, 30), orange, 'AUTRE')
    expect(lineProblems(order, draft, adjustmentReasons).get(42)).toEqual({ reason: false, comment: true })
    draft = setComment(draft, orange, '   ')
    expect(lineProblems(order, draft, adjustmentReasons).get(42)).toEqual({ reason: false, comment: true })
    draft = setComment(draft, orange, 'Produit abîmé')
    expect(lineProblems(order, draft, adjustmentReasons).size).toBe(0)
  })

  it('only detects the missing reason while the reasons are not loaded (the server stays judge)', () => {
    const draft = setReason(setQuantity(initialDraft(order), orange, 30), orange, 'AUTRE')
    expect(lineProblems(order, draft, undefined).size).toBe(0)
  })
})

describe('body of the validation', () => {
  it('sends every line in the order of the order, the reason and the comment of the reduced lines only', () => {
    let draft = setQuantity(initialDraft(order), lben, 700)
    draft = setReason(draft, lben, 'QUOTA_INSUFFISANT')
    draft = setComment(draft, lben, '  Reste région limité  ')
    expect(buildValidateBody(order, draft)).toEqual({
      version: 2,
      lines: [
        { product_id: 42, validated_qty: 40 },
        { product_id: 57, validated_qty: 700, reason_code: 'QUOTA_INSUFFISANT', comment: 'Reste région limité' },
      ],
    })
  })

  it('sends a null comment when it is blank, and the version the regional had in front of them', () => {
    let draft = setQuantity(initialDraft({ ...order, version: 5 }), orange, 0)
    draft = setReason(draft, orange, 'BORNE_ATTEINTE')
    draft = setComment(draft, orange, '   ')
    expect(buildValidateBody(order, draft)).toEqual({
      version: 5,
      lines: [
        { product_id: 42, validated_qty: 0, reason_code: 'BORNE_ATTEINTE', comment: null },
        { product_id: 57, validated_qty: 1250 },
      ],
    })
  })
})

describe('refusals of the server', () => {
  const sent = [
    { product_id: 42, validated_qty: 40 },
    { product_id: 57, validated_qty: 1250 },
  ]
  const violation = (extra: Partial<DecisionViolation> = {}): DecisionViolation => ({
    line: 1,
    product_id: 57,
    sku: 'P0057',
    requested: 1250,
    quota: 800,
    validated: 80,
    limit: null,
    client_validated: 30,
    max_allowed: 720,
    cause: 'REGION_QUOTA',
    ...extra,
  })

  it('reads the offending lines of a quota or limit refusal, by product', () => {
    const error = problem(409, 'REGION_QUOTA_EXCEEDED', { violations: [violation(), violation({ line: 0, product_id: 42, sku: 'P0042', cause: 'CLIENT_LIMIT', max_allowed: 10 })] })
    expect(violationsOf(error).map((entry) => entry.product_id)).toEqual([57, 42])

    const { lines, general } = lineIssues(error, sent)
    expect(general).toEqual([])
    expect([...lines.keys()].sort()).toEqual([42, 57])
    expect(lines.get(57)).toEqual({ kind: 'violation', violation: violation(), sent: 1250 })
    expect(violationsOf(new Error('boom'))).toEqual([])
    expect(violationsOf(problem(409, 'VERSION_CONFLICT'))).toEqual([])
  })

  it('maps the errors lines.N.* of a 422 to the line sent at that index, the others to the general messages', () => {
    const error = problem(422, 'VALIDATION_FAILED', {
      errors: {
        'lines.1.reason_code': ['Motif obligatoire.'],
        'lines.1.comment': ['Commentaire obligatoire.'],
        'lines.9.validated_qty': ['Hors commande.'],
        lines: ['Rejetez la commande.'],
      },
    })
    const { lines, general } = lineIssues(error, sent)
    expect(lines.get(57)).toEqual({ kind: 'field', sent: 1250, messages: ['Motif obligatoire.', 'Commentaire obligatoire.'] })
    expect(lines.has(42)).toBe(false)
    expect(general).toEqual(['Hors commande.', 'Rejetez la commande.'])
  })

  it('reports nothing for an error which is not a refusal of the server', () => {
    expect(lineIssues(new Error('network'), sent)).toEqual({ lines: new Map(), general: [] })
  })

  it('knows why the validation is impossible: the server first, else the last refusal', () => {
    expect(blockerOf({ blocked_reason: 'CLIENT_BLOCKED' }, null)).toBe('CLIENT_BLOCKED')
    expect(blockerOf({ blocked_reason: 'DAY_NOT_OPEN' }, problem(409, 'CUTOFF_PASSED'))).toBe('DAY_NOT_OPEN')
    expect(blockerOf({ blocked_reason: null }, problem(409, 'CUTOFF_PASSED'))).toBe('CUTOFF_PASSED')
    expect(blockerOf({ blocked_reason: null }, problem(403, 'CLIENT_BLOCKED'))).toBe('CLIENT_BLOCKED')
    expect(blockerOf({ blocked_reason: null }, problem(409, 'VERSION_CONFLICT'))).toBeNull()
    expect(blockerOf({ blocked_reason: null }, new Error('x'))).toBeNull()
    expect(blockerOf({ blocked_reason: null }, undefined)).toBeNull()
  })
})

describe('next order', () => {
  const at = (id: string, submittedAt: string, status: 'SUBMITTED' | 'VALIDATED' = 'SUBMITTED') =>
    summary({ id, submitted_at: submittedAt, status, status_label: status })

  it('is the oldest order to validate, the current one apart', () => {
    const orders = [at('b', '2026-10-08T09:05:00+00:00'), at('a', '2026-10-08T08:40:00+00:00'), at('c', '2026-10-08T07:00:00+00:00', 'VALIDATED')]
    expect(oldestSubmitted(orders, 'zzz')?.id).toBe('a')
    expect(oldestSubmitted(orders, 'a')?.id).toBe('b')
    expect(oldestSubmitted([at('a', '2026-10-08T08:40:00+00:00')], 'a')).toBeNull()
    expect(oldestSubmitted([], 'a')).toBeNull()
    expect(oldestSubmitted([at('c', '2026-10-08T07:00:00+00:00', 'VALIDATED')], 'a')).toBeNull()
  })
})
