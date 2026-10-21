import { describe, expect, it } from 'vitest'
import type { DistributionGridClient, DistributionGridProduct, LimitCell } from '../../shared/api/types'
import {
  available,
  belowEngaged,
  buildChange,
  cellAvailable,
  checkLimit,
  displayedLimit,
  type EditsByClient,
  filterClients,
  formatDayMonth,
  groupByFamily,
  hasDraft,
  highlightsOf,
  isBlock,
  isDayKey,
  type LimitMessages,
  type LocalEdit,
  locateSelection,
  nextDay,
  parseClipboard,
  parseLimit,
  revertChanges,
  serverLimit,
  summarize,
  withoutCells,
} from './model'

const JUS = { id: 2, code: 'JUS', name: 'Jus' }
const LAIT = { id: 1, code: 'LAIT', name: 'Laitiers' }

const product = (id: number, quota: number, extra: Partial<DistributionGridProduct> = {}): DistributionGridProduct => ({
  id,
  sku: `P00${id}`,
  name: `Produit ${id}`,
  short_name: `Produit ${id}`,
  family: JUS,
  sales_unit_label: 'Fardeau',
  quota,
  sum_limits: 0,
  pending: 0,
  validated: 0,
  remaining: quota,
  ...extra,
})

const client = (id: number, name: string, category: DistributionGridClient['category'] = null): DistributionGridClient => ({
  id,
  code: `C00${id}`,
  name,
  status: 'ACTIVE',
  category,
})

const cell = (limit: number | null, draft: { value: number | null } | null = null, pending = 0, validated = 0): LimitCell => ({ limit, draft, pending, validated })

const edit = (value: number | null, error: string | null = null): LocalEdit => ({ value, text: value === null ? '' : String(value), error })

const messages: LimitMessages = {
  format: 'format',
  aboveQuota: (quota) => `> ${quota}`,
  belowValidated: (validated) => `< ${validated}`,
}

describe('distribution model — displayed value', () => {
  it('shows the local edit, else the draft (null = no limit), else the published limit', () => {
    expect(serverLimit(undefined)).toBeNull()
    expect(serverLimit(cell(50))).toBe(50)
    expect(serverLimit(cell(50, { value: 60 }))).toBe(60)
    expect(serverLimit(cell(50, { value: null }))).toBeNull()

    expect(displayedLimit(cell(50, { value: 60 }), edit(70))).toBe(70)
    // Saisie locale « pas de borne » : prioritaire sur le brouillon.
    expect(displayedLimit(cell(50, { value: 60 }), edit(null))).toBeNull()
    // Saisie refusée : la valeur du serveur reste affichée.
    expect(displayedLimit(cell(50, { value: 60 }), { value: null, text: 'x', error: 'format' })).toBe(60)
    expect(displayedLimit(undefined, undefined)).toBeNull()
  })

  it('marks a cell as a draft when its displayed value differs from the published one', () => {
    expect(hasDraft(cell(50, { value: 60 }), undefined)).toBe(true)
    expect(hasDraft(cell(50), undefined)).toBe(false)
    expect(hasDraft(cell(50), edit(55))).toBe(true)
    // Retour à la borne publiée : plus de brouillon.
    expect(hasDraft(cell(50, { value: 60 }), edit(50))).toBe(false)
    expect(hasDraft(undefined, edit(null))).toBe(false)
  })
})

describe('distribution model — D(c,p) and RG-B07', () => {
  it('computes the available quantity with a limit, or by the no-limit policy', () => {
    // Borne 50, S 10, V 30, R 200 : min(50 − 40, 200) = 10.
    expect(available(50, 10, 30, 200, 'FREE')).toBe(10)
    // Reste région plus petit que la borne.
    expect(available(500, 0, 0, 120, 'FREE')).toBe(120)
    // Jamais négatif.
    expect(available(20, 10, 30, 200, 'FREE')).toBe(0)
    expect(available(50, 0, 0, -5, 'FREE')).toBe(0)
    // Borne 0 : produit interdit (RG-B01).
    expect(available(0, 0, 0, 200, 'REQUIRED')).toBe(0)
    // Sans borne : R en politique LIBRE, 0 en politique OBLIGATOIRE (RG-B04).
    expect(available(null, 10, 30, 200, 'FREE')).toBe(200)
    expect(available(null, 0, 0, -3, 'FREE')).toBe(0)
    expect(available(null, 0, 0, 200, 'REQUIRED')).toBe(0)
  })

  it('computes D of a cell on the published limit, never on a draft (§5.1)', () => {
    // Publiée 50, brouillon 60, S 10, V 30 : min(50 − 40, 200) = 10.
    expect(cellAvailable(cell(50, { value: 60 }, 10, 30), 200, 'FREE')).toBe(10)
    // Pas de borne publiée, brouillon 80 : la politique s'applique.
    expect(cellAvailable(cell(null, { value: 80 }), 200, 'FREE')).toBe(200)
    expect(cellAvailable(cell(null, { value: 80 }), 200, 'REQUIRED')).toBe(0)
    // Borne publiée retirée en brouillon : toujours la borne publiée.
    expect(cellAvailable(cell(30, { value: null }), 200, 'FREE')).toBe(30)
    expect(cellAvailable(undefined, 200, 'FREE')).toBe(200)
  })

  it('refuses a limit above the quota, and below the validated quantity after the first send', () => {
    expect(checkLimit(null, 500, 30, true)).toBeNull()
    expect(checkLimit(500, 500, 0, false)).toBeNull()
    expect(checkLimit(501, 500, 0, false)).toEqual({ kind: 'aboveQuota', quota: 500 })
    expect(checkLimit(20, 500, 30, false)).toBeNull()
    expect(checkLimit(20, 500, 30, true)).toEqual({ kind: 'belowValidated', validated: 30 })
  })

  it('warns when the limit is below the engaged quantity E = S + V', () => {
    expect(belowEngaged(39, cell(50, null, 10, 30))).toBe(true)
    expect(belowEngaged(40, cell(50, null, 10, 30))).toBe(false)
    expect(belowEngaged(null, cell(50, null, 10, 30))).toBe(false)
    expect(belowEngaged(0, undefined)).toBe(false)
  })
})

describe('distribution model — summary', () => {
  const clients = [client(12, 'Client A'), client(47, 'Client B'), client(51, 'Client C')]
  const products = [product(42, 500, { pending: 120, validated: 300, remaining: 200 }), product(43, 300, { remaining: 0, validated: 300 }), product(57, 800)]
  const cells = {
    '12': { '42': cell(50, { value: 60 }, 10, 30), '43': cell(20) },
    '47': { '42': cell(100), '43': cell(null, { value: 280 }) },
    // Client absent de la grille : ignoré.
    '99': { '42': cell(1000) },
  }

  it('sums the displayed limits of every listed client, flags overallocation and exhaustion', () => {
    const [orange, pomme, lben] = summarize({ products, clients, cells }, new Map())
    expect(orange).toMatchObject({ productId: 42, quota: 500, sumLimits: 160, limited: 2, pending: 120, validated: 300, remaining: 200, overallocated: false, exhausted: false })
    expect(pomme).toMatchObject({ sumLimits: 300, overallocated: false, exhausted: true })
    expect(lben).toMatchObject({ sumLimits: 0, limited: 0, overallocated: false })
  })

  it('follows every local edit, including « no limit » and refused entries', () => {
    const edits: EditsByClient = new Map<number, Record<number, LocalEdit>>([
      [12, { 42: edit(null), 57: edit(900) }],
      [47, { 42: { value: null, text: 'abc', error: 'format' } }],
      [51, { 42: edit(450) }],
    ])
    const [orange, , lben] = summarize({ products, clients, cells }, edits)
    // 12 : pas de borne (0) ; 47 : saisie refusée, borne du serveur 100 ; 51 : 450.
    expect(orange).toMatchObject({ sumLimits: 550, limited: 2, overallocated: true })
    expect(lben).toMatchObject({ sumLimits: 900, overallocated: true })
  })
})

describe('distribution model — entry', () => {
  const orange = product(42, 500)

  it('parses integers with thousands separators, empty = no limit', () => {
    expect(parseLimit('')).toEqual({ value: null })
    expect(parseLimit('  ')).toEqual({ value: null })
    expect(parseLimit('1 200')).toEqual({ value: 1200 })
    expect(parseLimit('1 000 000')).toEqual({ value: 1_000_000 })
    expect(parseLimit('0')).toEqual({ value: 0 })
    expect(parseLimit('1000001')).toBeNull()
    expect(parseLimit('12,5')).toBeNull()
    expect(parseLimit('-3')).toBeNull()
    expect(parseLimit('abc')).toBeNull()
  })

  it('builds a change, refuses invalid values on the spot and ignores unchanged ones', () => {
    const base = { clientId: 12, product: orange, cell: cell(50, null, 0, 30), edit: undefined, published: true }
    expect(buildChange(base, '60', messages)).toEqual({ clientId: 12, productId: 42, edit: { value: 60, text: '60', error: null } })
    expect(buildChange(base, '', messages)).toEqual({ clientId: 12, productId: 42, edit: { value: null, text: '', error: null } })
    expect(buildChange(base, '50', messages)).toBeNull()
    expect(buildChange(base, '600', messages)?.edit).toEqual({ value: null, text: '600', error: '> 500' })
    expect(buildChange(base, '20', messages)?.edit).toEqual({ value: null, text: '20', error: '< 30' })
    // Avant le premier envoi, RG-B07 ne s'applique pas.
    expect(buildChange({ ...base, published: false }, '20', messages)?.edit.error).toBeNull()
    expect(buildChange(base, 'x', messages)?.edit).toEqual({ value: null, text: 'x', error: 'format' })
    // Saisie locale identique : rien ; saisie en erreur ressaisie : nouvelle valeur.
    expect(buildChange({ ...base, edit: edit(70) }, '70', messages)).toBeNull()
    expect(buildChange({ ...base, edit: { value: null, text: 'x', error: 'format' } }, '50', messages)?.edit.value).toBe(50)
  })

  it('accepts the published limit again, even above a quota decreased since (RG-Q06)', () => {
    // Quota diminué à 350 sous la borne publiée 400, brouillon 200 en attente.
    const lowered = product(42, 350)
    const base = { clientId: 51, product: lowered, cell: cell(400, { value: 200 }), edit: undefined, published: true }
    expect(buildChange(base, '400', messages)).toEqual({ clientId: 51, productId: 42, edit: { value: 400, text: '400', error: null } })
    // Saisie locale en cours : retour à la borne publiée aussi.
    expect(buildChange({ ...base, edit: edit(300) }, '400', messages)?.edit).toEqual({ value: 400, text: '400', error: null })
    // Toute autre valeur au-dessus du quota reste refusée.
    expect(buildChange(base, '380', messages)?.edit.error).toBe('> 350')
    // Sans brouillon, la borne publiée est déjà affichée : rien à changer.
    expect(buildChange({ ...base, cell: cell(400) }, '400', messages)).toBeNull()
    // Sans ligne publiée, rien n'est exempté.
    expect(buildChange({ ...base, cell: undefined }, '400', messages)?.edit.error).toBe('> 350')
  })

  it('reads a block copied from Excel', () => {
    expect(parseClipboard('1 200\t300\r\n40\t\r\n')).toEqual([
      ['1 200', '300'],
      ['40', ''],
    ])
    expect(isBlock('12\n')).toBe(false)
    expect(isBlock('12\t13')).toBe(true)
  })
})

describe('distribution model — grid helpers', () => {
  it('groups columns by family in the received order', () => {
    const groups = groupByFamily([product(42, 1), product(43, 1), product(57, 1, { family: LAIT })])
    expect(groups.map((group) => [group.family.code, group.columns])).toEqual([
      ['JUS', [0, 1]],
      ['LAIT', [2]],
    ])
  })

  it('filters clients on code or name (accents and case ignored) and category', () => {
    const gros = { id: 1, code: 'GROS', name: 'Grossiste' }
    const clients = [client(12, 'Épicerie Amine', gros), client(47, 'Superette Nour'), client(51, 'Dépôt Hamid', gros)]
    expect(filterClients(clients, 'epicerie', null).map((item) => item.id)).toEqual([12])
    expect(filterClients(clients, 'c0047', null).map((item) => item.id)).toEqual([47])
    expect(filterClients(clients, '', 1).map((item) => item.id)).toEqual([12, 51])
    expect(filterClients(clients, 'nour', 1)).toEqual([])
  })

  it('locates the selection by identity, falling back to the last rank', () => {
    const clients = [{ id: 12 }, { id: 47 }, { id: 51 }]
    const products = [{ id: 42 }, { id: 43 }]
    expect(locateSelection(null, clients, products)).toEqual({ active: { row: 0, col: 0 }, range: { top: 0, bottom: 0, left: 0, right: 0 } })
    const selection = { active: { clientId: 51, productId: 43, row: 2, col: 1 }, anchor: { clientId: 12, productId: 42 } }
    expect(locateSelection(selection, clients, products)).toEqual({ active: { row: 2, col: 1 }, range: { top: 0, bottom: 2, left: 0, right: 1 } })
    // Client filtré : même rang, ramené dans la grille ; ancre disparue : sélection réduite.
    expect(locateSelection(selection, [{ id: 12 }], products)).toEqual({ active: { row: 0, col: 1 }, range: { top: 0, bottom: 0, left: 0, right: 1 } })
    expect(locateSelection(selection, [{ id: 47 }, { id: 51 }], products)).toEqual({ active: { row: 1, col: 1 }, range: { top: 1, bottom: 1, left: 1, right: 1 } })
  })

  it('reverts drafts and local edits to the published limit', () => {
    const grid = {
      products: [product(42, 500), product(43, 300)],
      clients: [client(12, 'A'), client(47, 'B')],
      cells: { '12': { '42': cell(50, { value: 60 }), '43': cell(20) }, '47': { '42': cell(null, { value: 80 }) } },
    }
    const edits: EditsByClient = new Map<number, Record<number, LocalEdit>>([
      [12, { 43: edit(25), 42: edit(60) }],
      [47, { 43: edit(null) }],
    ])
    expect(revertChanges(grid, edits)).toEqual([
      { clientId: 12, productId: 42, edit: { value: 50, text: '50', error: null } },
      { clientId: 12, productId: 43, edit: { value: 20, text: '20', error: null } },
      { clientId: 47, productId: 42, edit: { value: null, text: '', error: null } },
    ])
  })

  it('tracks the cells refused by a send until they are edited again', () => {
    const highlights = highlightsOf([
      { client_id: 12, product_id: 42 },
      { client_id: 12, product_id: 43 },
    ])
    expect(highlights.get(12)).toEqual(new Set([42, 43]))
    const after = withoutCells(highlights, [{ clientId: 12, productId: 42, edit: edit(1) }])
    expect(after.get(12)).toEqual(new Set([43]))
    expect(withoutCells(after, [{ clientId: 47, productId: 42, edit: edit(1) }])).toBe(after)
    expect(withoutCells(after, [{ clientId: 12, productId: 43, edit: edit(1) }]).size).toBe(0)
  })

  it('handles day keys', () => {
    expect(isDayKey('2026-10-08')).toBe(true)
    expect(isDayKey('2026-02-31')).toBe(false)
    expect(isDayKey(null)).toBe(false)
    expect(nextDay('2026-12-31')).toBe('2027-01-01')
    expect(formatDayMonth('2026-10-07')).toBe('07/10')
  })
})
