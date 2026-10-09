import { describe, expect, it } from 'vitest'
import { addressLines, mapHref, phonesOf, telHref } from './contact'

describe('field contact helpers', () => {
  it('builds a dialable tel: link from a formatted number (EF-CLI-03)', () => {
    expect(telHref('0550 12 34 56')).toBe('tel:0550123456')
    expect(telHref('+213 (0) 550-12.34.56')).toBe('tel:+2130550123456')
    expect(telHref('   ')).toBeNull()
    expect(telHref(null)).toBeNull()
    expect(telHref(undefined)).toBeNull()
  })

  it('links to the map only when both coordinates are known', () => {
    expect(mapHref(36.7212345, 3.1812345)).toBe('https://www.google.com/maps/search/?api=1&query=36.7212345,3.1812345')
    expect(mapHref(0, 0)).toBe('https://www.google.com/maps/search/?api=1&query=0,0')
    expect(mapHref(36.7, null)).toBeNull()
    expect(mapHref(null, 3.18)).toBeNull()
  })

  it('keeps the filled phones in the fiche order', () => {
    expect(phonesOf({ phone: '0550123456', phone2: '0770112233' })).toEqual(['0550123456', '0770112233'])
    expect(phonesOf({ phone: ' ', phone2: '0770112233' })).toEqual(['0770112233'])
    expect(phonesOf({ phone: null, phone2: null })).toEqual([])
  })

  it('writes the address as street, then « commune, wilaya »', () => {
    expect(addressLines({ address: ' 12 rue des Oliviers ', commune: 'Bab Ezzouar', wilaya: 'Alger' })).toEqual([
      '12 rue des Oliviers',
      'Bab Ezzouar, Alger',
    ])
    expect(addressLines({ address: null, commune: 'Blida', wilaya: null })).toEqual(['Blida'])
    expect(addressLines({ address: '', commune: null, wilaya: '' })).toEqual([])
  })
})
