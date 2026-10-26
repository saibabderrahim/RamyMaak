import Dexie, { type EntityTable } from 'dexie'
import type { SubmitOrderInput } from '../api/types'

/**
 * Base IndexedDB de l'appareil (lot 8, contrat §6) : partagée par
 * l'application et le service worker, donc sans React ni DOM.
 *
 * - `kv` : cache de requêtes persistant, une entrée par compte (§6.1).
 * - `outbox` : boîte d'envoi des commandes client (§6.3).
 */

export type KvRecord = { key: string; value: string }

export type OutboxStatus = 'pending' | 'sending' | 'sent' | 'refused'

export type OutboxEntry = {
  /** `client_request_id` de l'envoi : la clé d'idempotence du serveur (RG-C07). */
  id: string
  user_id: number
  client_id: number
  day: string
  body: SubmitOrderInput
  created_at: string
  status: OutboxStatus
  attempts: number
  error: { code: string; detail: string } | null
  order: { id: string; number: string } | null
}

export class RamyMaakDb extends Dexie {
  kv!: EntityTable<KvRecord, 'key'>
  outbox!: EntityTable<OutboxEntry, 'id'>

  constructor(name = 'ramymaak') {
    super(name)
    this.version(1).stores({
      kv: 'key',
      outbox: 'id, user_id, [user_id+day], status',
    })
  }
}

/** Base de l'appareil, ouverte à la première utilisation. */
export const db = new RamyMaakDb()
