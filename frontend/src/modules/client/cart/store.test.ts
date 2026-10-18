import { afterEach, describe, expect, it } from 'vitest'
import { purgeSessionStorage } from '../../../shared/storage'
import { cartKey, cartStore, CartStore, EMPTY_CART } from './store'

afterEach(() => {
  purgeSessionStorage()
  localStorage.clear()
})

describe('cart store', () => {
  it('keeps the cart in the browser, per account and client, and reloads it (EF-CLT-03)', () => {
    const store = new CartStore(cartKey(7, 12))
    store.setQuantity('2026-10-08', 42, 40)
    store.setQuantity('2026-10-08', 57, 3)
    store.setQuantity('2026-10-09', 42, 25)
    store.setComment('Livraison avant 10 h')

    expect(new CartStore(cartKey(7, 12)).get()).toEqual({
      v: 1,
      day: '2026-10-08',
      lines: [
        { product_id: 42, quantity: 25 },
        { product_id: 57, quantity: 3 },
      ],
      comment: 'Livraison avant 10 h',
      editing: null,
      pending: null,
    })
    expect(new CartStore(cartKey(8, 12)).get()).toEqual(EMPTY_CART)

    store.setQuantity('2026-10-08', 42, 0)
    store.setQuantity('2026-10-08', 57, 0)
    store.setComment('')
    expect(localStorage.getItem(cartKey(7, 12))).toBeNull()
  })

  it('reuses the request id of an interrupted sending, and forgets it after a refusal (RG-C07)', () => {
    const store = new CartStore(cartKey(7, 12))
    store.setQuantity('2026-10-08', 42, 1)
    const id = store.beginSend()

    expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(new CartStore(cartKey(7, 12)).beginSend()).toBe(id)
    store.abandonSend()
    expect(store.beginSend()).not.toBe(id)
    store.clear()
    expect(store.get()).toEqual(EMPTY_CART)
  })

  it('loads a submitted order to modify it', () => {
    const store = new CartStore(cartKey(7, 12))
    store.setQuantity('2026-10-08', 99, 1)
    store.startEditing({
      id: '01JA2Z6K8QW3X9M4T7RBN5CDEF',
      number: 'RMK-261008-00042',
      version: 2,
      day: '2026-10-08',
      comment: null,
      lines: [{ product_id: 42, requested_qty: 40 } as never],
    })

    expect(store.get()).toEqual({
      v: 1,
      day: '2026-10-08',
      lines: [{ product_id: 42, quantity: 40 }],
      comment: '',
      editing: { orderId: '01JA2Z6K8QW3X9M4T7RBN5CDEF', number: 'RMK-261008-00042', version: 2 },
      pending: null,
    })
  })

  it('rebuilds a new cart for « Recommander », dropping the order being modified and the interrupted sending', () => {
    const store = new CartStore(cartKey(7, 12))
    store.startEditing({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEF', number: 'RMK-261008-00042', version: 2, day: '2026-10-08', comment: 'Livrer tôt', lines: [] })
    const previous = store.beginSend()

    store.rebuild('2026-10-11', [
      { product_id: 42, quantity: 30 },
      { product_id: 57, quantity: 0 },
    ])

    expect(store.get()).toEqual({ v: 1, day: '2026-10-11', lines: [{ product_id: 42, quantity: 30 }], comment: '', editing: null, pending: null })
    expect(store.beginSend()).not.toBe(previous)
  })

  it('ignores unreadable content and empties the shared stores at logout', () => {
    localStorage.setItem(cartKey(7, 12), '{"v":1,"lines":[{"product_id":"x","quantity":2},{"product_id":5,"quantity":0},{"product_id":6,"quantity":2}]}')
    expect(new CartStore(cartKey(7, 12)).get().lines).toEqual([{ product_id: 6, quantity: 2 }])
    localStorage.setItem(cartKey(7, 12), 'not json')
    expect(new CartStore(cartKey(7, 12)).get()).toEqual(EMPTY_CART)

    const shared = cartStore(7, 12)
    shared.setQuantity('2026-10-08', 42, 1)
    purgeSessionStorage()
    expect(shared.get()).toEqual(EMPTY_CART)
    expect(localStorage.getItem(cartKey(7, 12))).toBeNull()
  })
})
