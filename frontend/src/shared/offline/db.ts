import Dexie, { type EntityTable } from 'dexie'
import type { SubmitOrderInput } from '../api/types'

/**
 * Base IndexedDB de l'appareil (lot 8, contrat §6) : partagée par
 * l'application et le service worker, donc sans React ni DOM.
 *
 * - `kv` : cache de requêtes persistant, une entrée par compte
 *   (`query-cache:{userId}`, §6.1), et compte connecté sur l'appareil
 *   (`session-user`), lu par le service worker pour n'envoyer que ses commandes.
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
  /**
   * Début de l'envoi en cours (`sending`) : passé 2 minutes (onglet fermé,
   * service worker arrêté), l'envoi est repris. Champ non indexé, hors du
   * contrat : pas de nouvelle version du schéma.
   */
  sending_at?: string | null
  /** Instant du passage à `sent` : l'entrée disparaît dès que la liste des commandes a été relue après. */
  sent_at?: string | null
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
