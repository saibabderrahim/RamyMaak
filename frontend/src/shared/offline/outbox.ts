import type { Order } from '../api/types'
import texts from '../i18n/fr/offline'
import { db as defaultDb, type OutboxEntry, type OutboxStatus, type RamyMaakDb } from './db'

/**
 * Boîte d'envoi des commandes client (lot 8, contrat §6.3, I-102, I-112) :
 * partagée par l'application et le service worker (Background Sync), donc
 * sans React ni DOM. Une nouvelle commande y est déposée avec son
 * `client_request_id` (enregistré avant tout envoi, I-40), puis envoyée
 * aussitôt et à chaque nouvelle occasion ; le renvoi du même identifiant ne
 * crée jamais de seconde commande (RG-C07). Un seul envoi à la fois par
 * entrée (`sending`, pris dans une transaction), repris s'il reste bloqué
 * plus de 2 minutes.
 */

/** Étiquette Background Sync de la boîte d'envoi (§7). */
export const OUTBOX_SYNC_TAG = 'ramymaak-outbox'
/** Message du service worker aux fenêtres de l'application après chaque envoi. */
export const OUTBOX_MESSAGE = 'outbox-updated'
/** Envoi « en cours » repris au-delà de ce délai (onglet fermé ou service worker arrêté pendant l'envoi). */
export const SENDING_STALE_MS = 2 * 60_000
/** Point d'envoi des commandes (contrat du lot 5, §3). */
export const ORDERS_ENDPOINT = '/api/v1/client/orders'
/** Nouveau cookie anti-CSRF (XSRF-TOKEN) de Sanctum. */
export const CSRF_COOKIE_ENDPOINT = '/sanctum/csrf-cookie'
/** Clé `kv` du compte connecté sur l'appareil, vérifiée avant chaque envoi (application et service worker). */
export const SESSION_USER_KEY = 'session-user'

/** Une commande de la même journée attend déjà son envoi (I-112). */
export class OutboxBusyError extends Error {
  constructor() {
    super('outbox-busy')
    this.name = 'OutboxBusyError'
  }
}

/** Refus du serveur (« Problem Details ») tel que reçu. */
export type OutboxProblem = { status: number; code: string; detail?: string; [extra: string]: unknown }

/**
 * Résultat d'une tentative :
 * - `sent` : 201 (nouvelle commande) ou 200 (renvoi d'un identifiant déjà reçu, la commande existante) ;
 * - `pending` : sans réponse définitive (réseau, 5xx, 408, 419, 429), ou session expirée (401) ou
 *   changement du mot de passe exigé (403 `PASSWORD_CHANGE_REQUIRED`) : l'envoi reprendra après la reconnexion ;
 * - `refused` : autre 4xx, avec le motif du serveur.
 */
export type SendOutcome =
  | { status: 'sent'; order: Order; created: boolean }
  | { status: 'pending'; reason: 'network' | 'server' | 'csrf' | 'unauthenticated'; httpStatus: number | null }
  | { status: 'refused'; error: { code: string; detail: string }; problem: OutboxProblem }

export type FetchLike = (input: string, init: RequestInit) => Promise<Response>

export type OutboxDeps = {
  fetch: FetchLike
  /** Jeton anti-CSRF ; `renew` : un nouveau jeton après un 419. */
  xsrf: (renew: boolean) => Promise<string | null>
  database?: RamyMaakDb
  now?: () => Date
}

export type ProcessResult = { entry: OutboxEntry; outcome: SendOutcome }

/** En attente ou en cours d'envoi : bloque un autre envoi pour la même journée (I-112). */
export function isWaiting(entry: Pick<OutboxEntry, 'status'>): boolean {
  return entry.status === 'pending' || entry.status === 'sending'
}

function isStale(entry: OutboxEntry, now: number): boolean {
  const since = entry.sending_at ? Date.parse(entry.sending_at) : Number.NaN
  return Number.isNaN(since) || now - since > SENDING_STALE_MS
}

/** Envoi possible : en attente, ou « en cours » depuis trop longtemps. */
export function isClaimable(entry: OutboxEntry, now = Date.now()): boolean {
  return entry.status === 'pending' || (entry.status === 'sending' && isStale(entry, now))
}

/** État affiché : un envoi resté « en cours » plus de 2 minutes est de nouveau « en attente ». */
export function displayStatus(entry: OutboxEntry, now = Date.now()): OutboxStatus {
  return entry.status === 'sending' && isStale(entry, now) ? 'pending' : entry.status
}

export type NewOutboxEntry = Pick<OutboxEntry, 'user_id' | 'client_id' | 'body'>

/**
 * Dépose une commande : identifiant et journée tirés du corps envoyé. Refus
 * (OutboxBusyError) si une autre commande de la journée attend déjà (I-112).
 */
export async function enqueue(input: NewOutboxEntry, database: RamyMaakDb = defaultDb, now = new Date()): Promise<OutboxEntry> {
  const entry: OutboxEntry = {
    id: input.body.client_request_id,
    user_id: input.user_id,
    client_id: input.client_id,
    day: input.body.day,
    body: input.body,
    created_at: now.toISOString(),
    status: 'pending',
    attempts: 0,
    error: null,
    order: null,
    sending_at: null,
    sent_at: null,
  }
  await database.transaction('rw', database.outbox, async () => {
    const sameDay = await database.outbox.where('[user_id+day]').equals([entry.user_id, entry.day]).toArray()
    if (sameDay.some((other) => other.id !== entry.id && isWaiting(other))) throw new OutboxBusyError()
    await database.outbox.put(entry)
  })
  return entry
}

/** Commandes d'un compte, la plus ancienne d'abord. */
export function listEntries(userId: number, database: RamyMaakDb = defaultDb): Promise<OutboxEntry[]> {
  return database.outbox.where('user_id').equals(userId).sortBy('created_at')
}

export function getEntry(id: string, database: RamyMaakDb = defaultDb): Promise<OutboxEntry | undefined> {
  return database.outbox.get(id)
}

/** Une commande de la journée attend-elle déjà son envoi (I-112) ? */
export async function hasWaitingEntry(userId: number, day: string, database: RamyMaakDb = defaultDb): Promise<boolean> {
  const entries = await database.outbox.where('[user_id+day]').equals([userId, day]).toArray()
  return entries.some(isWaiting)
}

/** « Retirer », « Reprendre dans le panier » : jamais pendant un envoi. */
export async function removeEntry(id: string, database: RamyMaakDb = defaultDb): Promise<boolean> {
  return database.transaction('rw', database.outbox, async () => {
    const entry = await database.outbox.get(id)
    if (!entry || (entry.status === 'sending' && !isStale(entry, Date.now()))) return false
    await database.outbox.delete(id)
    return true
  })
}

/** Déconnexion : toute la boîte d'envoi du compte est effacée de l'appareil (§6.1). */
export async function removeUserEntries(userId: number, database: RamyMaakDb = defaultDb): Promise<void> {
  await database.outbox.where('user_id').equals(userId).delete()
}

/** Commandes envoyées que l'application a relues depuis (liste des commandes) : retirées de la boîte. */
export async function removeSent(userId: number, seen: (entry: OutboxEntry, sentAt: number) => boolean, database: RamyMaakDb = defaultDb): Promise<number> {
  return database.outbox
    .where('user_id')
    .equals(userId)
    .filter((entry) => entry.status === 'sent' && seen(entry, entry.sent_at ? Date.parse(entry.sent_at) : 0))
    .delete()
}

/** Prend l'entrée pour l'envoyer (une seule tentative à la fois, tous onglets et service worker confondus). */
export async function claim(id: string, database: RamyMaakDb = defaultDb, now = new Date()): Promise<OutboxEntry | null> {
  return database.transaction('rw', database.outbox, async () => {
    const entry = await database.outbox.get(id)
    if (!entry || !isClaimable(entry, now.getTime())) return null
    const claimed: OutboxEntry = { ...entry, status: 'sending', attempts: entry.attempts + 1, sending_at: now.toISOString() }
    await database.outbox.put(claimed)
    return claimed
  })
}

function isOrder(value: unknown): value is Order {
  if (typeof value !== 'object' || value === null) return false
  const order = value as Record<string, unknown>
  return typeof order.id === 'string' && typeof order.number === 'string'
}

function problemOf(status: number, payload: unknown): OutboxProblem {
  if (typeof payload === 'object' && payload !== null && typeof (payload as Record<string, unknown>).code === 'string') {
    return { ...(payload as OutboxProblem), status }
  }
  return { status, code: 'HTTP_ERROR' }
}

/** Codes sans réponse définitive : l'envoi a pu aboutir, il sera repris avec le même identifiant. */
const RETRY_STATUSES = [408, 429]

/**
 * POST /client/orders du corps enregistré : cookie de session, jeton
 * anti-CSRF fourni par l'appelant (cookie du document, ou Cookie Store dans
 * le service worker). Ne lève jamais : toute erreur devient un résultat.
 */
export async function sendEntry(entry: Pick<OutboxEntry, 'body'>, fetchImpl: FetchLike, xsrfToken: string | null): Promise<SendOutcome> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' }
  if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken

  let response: Response
  try {
    response = await fetchImpl(ORDERS_ENDPOINT, { method: 'POST', credentials: 'include', headers, body: JSON.stringify(entry.body) })
  } catch {
    return { status: 'pending', reason: 'network', httpStatus: null }
  }

  const payload: unknown = await response.json().catch(() => null)
  const status = response.status
  if (status === 200 || status === 201) {
    // Réponse illisible : l'envoi a peut-être abouti ; le renvoi du même identifiant tranchera.
    return isOrder(payload) ? { status: 'sent', order: payload, created: status === 201 } : { status: 'pending', reason: 'server', httpStatus: status }
  }
  if (status === 401) return { status: 'pending', reason: 'unauthenticated', httpStatus: status }
  if (status === 419) return { status: 'pending', reason: 'csrf', httpStatus: status }
  if (status >= 400 && status < 500 && !RETRY_STATUSES.includes(status)) {
    const problem = problemOf(status, payload)
    // Mot de passe à changer (EF-AUTH-02) : la commande n'est pas refusée, elle partira après le changement.
    if (status === 403 && problem.code === 'PASSWORD_CHANGE_REQUIRED') return { status: 'pending', reason: 'unauthenticated', httpStatus: status }
    return { status: 'refused', error: { code: problem.code, detail: typeof problem.detail === 'string' ? problem.detail : '' }, problem }
  }
  return { status: 'pending', reason: 'server', httpStatus: status }
}

/** Entrée après une tentative. */
export function settle(entry: OutboxEntry, outcome: SendOutcome, now: Date): OutboxEntry {
  switch (outcome.status) {
    case 'sent':
      return {
        ...entry,
        status: 'sent',
        order: { id: outcome.order.id, number: outcome.order.number },
        error: null,
        sending_at: null,
        sent_at: now.toISOString(),
      }
    case 'refused':
      return { ...entry, status: 'refused', error: outcome.error, sending_at: null }
    case 'pending':
      return {
        ...entry,
        status: 'pending',
        error: outcome.reason === 'unauthenticated' ? { code: 'UNAUTHENTICATED', detail: '' } : null,
        sending_at: null,
      }
  }
}

/** Enregistre l'entrée, sauf si elle a été retirée entre-temps (déconnexion) : elle n'est pas recréée. */
async function store(entry: OutboxEntry, database: RamyMaakDb): Promise<void> {
  await database.transaction('rw', database.outbox, async () => {
    if (await database.outbox.get(entry.id)) await database.outbox.put(entry)
  })
}

/**
 * Une tentative d'envoi de l'entrée `id` : null si elle n'est pas à envoyer
 * (déjà envoyée, refusée, retirée), si un autre contexte l'envoie déjà, ou
 * si le compte connecté sur l'appareil (`session-user`) n'est plus le sien.
 * Après un 419, un nouveau jeton est demandé et l'envoi rejoué une fois.
 * Une entrée retirée pendant l'envoi (déconnexion) n'est pas recréée.
 */
export async function processEntry(id: string, deps: OutboxDeps): Promise<ProcessResult | null> {
  const database = deps.database ?? defaultDb
  const now = deps.now ?? (() => new Date())
  const claimed = await claim(id, database, now())
  if (!claimed) return null

  // Appareil partagé : un autre compte a pu ouvrir sa session dans un autre onglet. Le cookie serait le sien et le
  // serveur prend le client de la session : la commande n'est envoyée que sous le compte qui l'a déposée, vérifié
  // juste avant chaque envoi. Sinon, null.
  const send = async (renew: boolean): Promise<SendOutcome | null> => {
    const token = await deps.xsrf(renew)
    if ((await readSessionUser(database).catch(() => null)) !== claimed.user_id) return null
    return sendEntry(claimed, deps.fetch, token)
  }

  let outcome: SendOutcome | null
  try {
    outcome = await send(false)
    if (outcome?.status === 'pending' && outcome.reason === 'csrf') outcome = await send(true)
  } catch {
    // Jeton introuvable (réseau coupé pendant sa lecture) : nouvelle tentative plus tard.
    outcome = { status: 'pending', reason: 'network', httpStatus: null }
  }

  if (outcome === null) {
    // Pas envoyée : l'entrée revient en attente telle quelle, sans tentative comptée.
    await store({ ...claimed, status: 'pending', attempts: claimed.attempts - 1, sending_at: null }, database)
    return null
  }

  const entry = settle(claimed, outcome, now())
  await store(entry, database)
  return { entry, outcome }
}

/** Envoie, l'une après l'autre, les commandes à envoyer du compte ; `onResult` après chacune. */
export async function processPending(userId: number, deps: OutboxDeps, onResult?: (result: ProcessResult) => unknown): Promise<ProcessResult[]> {
  const database = deps.database ?? defaultDb
  const now = deps.now ?? (() => new Date())
  const results: ProcessResult[] = []
  for (const entry of await listEntries(userId, database)) {
    if (!isClaimable(entry, now().getTime())) continue
    const result = await processEntry(entry.id, deps)
    if (!result) continue
    results.push(result)
    await onResult?.(result)
  }
  return results
}

/** Background Sync : une commande reste à envoyer faute de réseau ou de serveur, le navigateur réessaiera. */
export function needsRetry(results: readonly ProcessResult[]): boolean {
  return results.some(({ outcome }) => outcome.status === 'pending' && outcome.reason !== 'unauthenticated')
}

/** Compte connecté sur l'appareil (dernier lu par un onglet, GET /auth/me) ; null : personne. */
export async function readSessionUser(database: RamyMaakDb = defaultDb): Promise<number | null> {
  const record = await database.kv.get(SESSION_USER_KEY)
  const id = Number(record?.value)
  return Number.isInteger(id) && id > 0 ? id : null
}

export async function writeSessionUser(userId: number | null, database: RamyMaakDb = defaultDb): Promise<void> {
  if (userId === null) await database.kv.delete(SESSION_USER_KEY)
  else await database.kv.put({ key: SESSION_USER_KEY, value: String(userId) })
}

/** « {{name}} » remplacé par sa valeur (textes du bundle `offline`, sans i18next dans le service worker). */
export function fill(template: string, values: Record<string, string>): string {
  return template.replace(/\{\{(\w+)\}\}/g, (match, name: string) => values[name] ?? match)
}

export type OutboxNotice = { title: string; body: string; url: string; tag: string }

/** Notification locale après un envoi en arrière-plan : commande envoyée ou refusée ; rien sinon. */
export function outboxNotice({ entry, outcome }: ProcessResult): OutboxNotice | null {
  const tag = `outbox-${entry.id}`
  if (outcome.status === 'sent') {
    return {
      title: fill(texts.sync.sentTitle, { number: outcome.order.number }),
      body: texts.sync.sentBody,
      url: `/client/commandes/${encodeURIComponent(outcome.order.id)}`,
      tag,
    }
  }
  if (outcome.status === 'refused') {
    return {
      title: fill(texts.sync.refusedTitle, { reason: outcome.error.detail || texts.outbox.refusedFallback }),
      body: texts.sync.refusedBody,
      url: '/client/commandes',
      tag,
    }
  }
  return null
}

/** Jeton du cookie XSRF-TOKEN (encodé par Laravel) ; null sans cookie. */
export function decodeXsrf(value: string | null | undefined): string | null {
  if (!value) return null
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}

/**
 * Jeton anti-CSRF du service worker, lu par `readCookie` (Cookie Store).
 * Cookie absent (expiré avec la session, après 120 min d'inactivité) ou
 * refusé (419, `renew`) : un nouveau cookie est demandé au serveur, puis relu.
 */
export async function serviceWorkerXsrf(renew: boolean, readCookie: () => Promise<string | null | undefined>, fetchImpl: FetchLike): Promise<string | null> {
  const current = renew ? null : decodeXsrf(await readCookie())
  if (current !== null) return current
  await fetchImpl(CSRF_COOKIE_ENDPOINT, { credentials: 'include', headers: { Accept: 'application/json' } })
  return decodeXsrf(await readCookie())
}
