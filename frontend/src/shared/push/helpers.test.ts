import { describe, expect, it } from 'vitest'
import { base64UrlToBytes, contentEncoding, sameKey, subscriptionInput } from './encoding'
import { clickedPath, notificationOptions, parsePushPayload, pickWindow } from './notification'
import { internalPath, notificationTarget } from './paths'
import { PUBLIC_KEY } from './testing'

describe('push — internal paths (EF-NOT-03)', () => {
  it.each([
    ['/client/commandes/01J', '/client/commandes/01J'],
    ['/regional/commandes?day=2026-10-08', '/regional/commandes?day=2026-10-08'],
    ['https://site.example/x', null],
    ['//site.example', null],
    ['/\\site.example', null],
    ['/\t/site.example', null],
    ['/\n/site.example', null],
    ['client', null],
    ['', null],
    [null, null],
    [42, null],
  ])('reads %j as %j', (url, expected) => {
    expect(internalPath(url)).toBe(expected)
  })

  it('builds the address to open on the origin of the application, the notification center otherwise', () => {
    expect(notificationTarget('/client/commandes/01J', 'https://ramymaak.dz')).toBe('https://ramymaak.dz/client/commandes/01J')
    expect(notificationTarget('https://site.example/x', 'https://ramymaak.dz')).toBe('https://ramymaak.dz/notifications')
    expect(notificationTarget(null, 'https://ramymaak.dz')).toBe('https://ramymaak.dz/notifications')
  })
})

describe('push — payload (§2.5)', () => {
  it('reads a push of the server', () => {
    const push = parsePushPayload(
      JSON.stringify({
        id: '6f1c',
        type: 'ORDER_DECIDED',
        code: 'N-07',
        title: 'Commande RMK-261008-00042 validée',
        body: 'Votre commande RMK-261008-00042 est validée.',
        url: '/client/commandes/01J',
        tag: 'ORDER_DECIDED',
        count: 1,
      }),
    )
    expect(push).toEqual({
      title: 'Commande RMK-261008-00042 validée',
      body: 'Votre commande RMK-261008-00042 est validée.',
      tag: 'ORDER_DECIDED',
      url: '/client/commandes/01J',
      count: 1,
    })
  })

  it('reads a grouped push', () => {
    const push = parsePushPayload(
      JSON.stringify({ id: null, type: 'ORDER_SUBMITTED', code: 'N-05', title: '3 nouvelles commandes à valider', body: 'Ouvrez les commandes reçues.', url: '/regional/commandes', tag: 'ORDER_SUBMITTED', count: 3 }),
    )
    expect(push.count).toBe(3)
    expect(push.url).toBe('/regional/commandes')
  })

  it.each([null, '', 'pas du json', '42', '[]', JSON.stringify({ body: 'Sans titre' }), JSON.stringify({ title: '  ' })])(
    'shows « RamyMaak — Nouvelle notification » for an unreadable payload (%j)',
    (text) => {
      expect(parsePushPayload(text)).toEqual({ title: 'RamyMaak', body: 'Nouvelle notification', tag: null, url: '/notifications', count: 1 })
    },
  )

  it('never keeps an external address nor an invalid count', () => {
    const push = parsePushPayload(JSON.stringify({ title: 'Titre', url: 'https://site.example', count: 'beaucoup', tag: '' }))
    expect(push).toEqual({ title: 'Titre', body: '', tag: null, url: '/notifications', count: 1 })
  })

  it('shows the notification with the icons and the address to open, alerting again for the same group', () => {
    const push = parsePushPayload(JSON.stringify({ title: 'Titre', body: 'Texte', url: '/client', tag: 'ORDERING_REMINDER', count: 1 }))
    expect(notificationOptions(push)).toEqual({
      body: 'Texte',
      icon: '/icons/icon-192.png',
      badge: '/icons/badge-96.png',
      data: { url: '/client' },
      tag: 'ORDERING_REMINDER',
      renotify: true,
    })
  })

  it('has no renotify without a tag (refused by browsers)', () => {
    const options = notificationOptions(parsePushPayload(null))
    expect(options).not.toHaveProperty('tag')
    expect(options).not.toHaveProperty('renotify')
    expect(options.data).toEqual({ url: '/notifications' })
  })
})

describe('push — click on a notification (§7)', () => {
  const window = (url: string, focused = false, visibilityState = 'hidden') => ({ url, focused, visibilityState })

  it('prefers the focused window, then a visible one, then the first', () => {
    expect(pickWindow([window('/a'), window('/b', false, 'visible'), window('/c', true, 'visible')])).toBe(2)
    expect(pickWindow([window('/a'), window('/b', false, 'visible')])).toBe(1)
    expect(pickWindow([window('/a'), window('/b')])).toBe(0)
    expect(pickWindow([])).toBe(-1)
  })

  it('opens the internal path of the notification data, the notification center otherwise', () => {
    expect(clickedPath({ url: '/regional/commandes' })).toBe('/regional/commandes')
    expect(clickedPath({ url: 'https://site.example' })).toBe('/notifications')
    expect(clickedPath(null)).toBe('/notifications')
    expect(clickedPath(undefined)).toBe('/notifications')
  })
})

describe('push — keys and subscription (§2.2)', () => {
  it('converts the VAPID public key from base64url to 65 bytes', () => {
    const bytes = base64UrlToBytes(PUBLIC_KEY)
    expect(bytes).toBeInstanceOf(Uint8Array)
    expect(bytes).toHaveLength(65)
    expect(bytes[0]).toBe(0x04)
    // « - » et « _ » de base64url, sans remplissage.
    expect(Array.from(base64UrlToBytes('-_8'))).toEqual([0xfb, 0xff])
  })

  it('compares the key of an existing subscription', () => {
    const key = base64UrlToBytes(PUBLIC_KEY)
    expect(sameKey(key.slice().buffer, key)).toBe(true)
    expect(sameKey(base64UrlToBytes('AAAA').buffer, key)).toBe(false)
    expect(sameKey(null, key)).toBe(false)
  })

  it.each([
    [undefined, 'aes128gcm'],
    [[], 'aes128gcm'],
    [['aes128gcm', 'aesgcm'], 'aes128gcm'],
    [['aesgcm'], 'aesgcm'],
  ] as const)('chooses the content encoding among %j', (supported, expected) => {
    expect(contentEncoding(supported)).toBe(expected)
  })

  it('builds the body of POST /push-subscriptions from toJSON()', () => {
    const json = { endpoint: 'https://fcm.googleapis.com/fcm/send/x', expirationTime: null, keys: { p256dh: 'BN', auth: 'k' } }
    expect(subscriptionInput(json, ['aes128gcm'])).toEqual({
      endpoint: 'https://fcm.googleapis.com/fcm/send/x',
      keys: { p256dh: 'BN', auth: 'k' },
      content_encoding: 'aes128gcm',
    })
    expect(subscriptionInput({ endpoint: 'https://fcm.googleapis.com/fcm/send/x' }, undefined)).toBeNull()
  })
})
