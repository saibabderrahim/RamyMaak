import type { QueryClient } from '@tanstack/react-query'
import { liveQuery } from 'dexie'
import { useEffect, useState } from 'react'
import { csrfHeaders } from '../api/client'
import { meQueryKey, useAuthenticatedUser } from '../auth/session'
import type { OutboxEntry } from './db'
import {
  displayStatus,
  OUTBOX_MESSAGE,
  OUTBOX_SYNC_TAG,
  type OutboxDeps,
  type ProcessResult,
  listEntries,
  processEntry,
  processPending,
} from './outbox'

/**
 * Boîte d'envoi côté application (lot 8, contrat §6.3) : lecture suivie des
 * commandes du compte, envoi avec le cookie de session et le jeton
 * anti-CSRF du document, Background Sync.
 */

/** Préfixe des données du client : relues après un envoi ou un refus. */
const CLIENT_KEY = ['client'] as const

/** Envoi depuis l'application : `fetch` lu à chaque appel, jeton anti-CSRF renouvelé après un 419. */
export function appOutboxDeps(): OutboxDeps {
  return {
    fetch: (input, init) => fetch(input, init),
    xsrf: async (renew) => (await csrfHeaders(renew))['X-XSRF-TOKEN'] ?? null,
  }
}

const listeners = new Set<() => void>()

/** Boîte d'envoi changée hors de cet onglet (service worker) : les lectures suivies sont relancées. */
export function notifyOutboxChanged(): void {
  listeners.forEach((listener) => listener())
}

export function onOutboxChanged(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Messages du service worker (« outbox-updated ») ; null hors navigateur compatible. */
export function onServiceWorkerOutboxMessage(listener: () => void): () => void {
  const container = typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : null
  if (!container) return () => undefined
  const handler = (event: MessageEvent) => {
    if ((event.data as { type?: unknown } | null)?.type === OUTBOX_MESSAGE) listener()
  }
  container.addEventListener('message', handler)
  return () => container.removeEventListener('message', handler)
}

type SyncRegistration = ServiceWorkerRegistration & { sync?: { register: (tag: string) => Promise<void> } }

/**
 * Background Sync (Chrome, Android) : le service worker enverra la boîte
 * d'envoi au retour du réseau, application fermée. Sans prise en charge,
 * rien : les nouvelles tentatives de l'application suffisent.
 */
export async function registerBackgroundSync(): Promise<boolean> {
  try {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return false
    const registration = (await navigator.serviceWorker.ready) as SyncRegistration
    if (!registration.sync) return false
    await registration.sync.register(OUTBOX_SYNC_TAG)
    return true
  } catch {
    return false
  }
}

/**
 * Suite d'un résultat : données du client relues (commande créée ou refus),
 * synchronisation en arrière-plan sinon. Session expirée (401) ou mot de
 * passe à changer : l'utilisateur est relu, les gardes mènent à la connexion
 * (ou au changement du mot de passe) au lieu d'un nouvel envoi toutes les 30 s.
 */
function afterResult(queryClient: QueryClient, result: ProcessResult): void {
  if (result.outcome.status === 'pending') {
    if (result.outcome.reason === 'unauthenticated') void queryClient.invalidateQueries({ queryKey: meQueryKey })
    else void registerBackgroundSync()
    return
  }
  void queryClient.invalidateQueries({ queryKey: CLIENT_KEY })
}

/** Une tentative d'envoi de la commande `id` (null : déjà envoyée, ou envoyée ailleurs au même moment). */
export async function sendOutboxEntry(queryClient: QueryClient, id: string): Promise<ProcessResult | null> {
  const result = await processEntry(id, appOutboxDeps())
  if (result) afterResult(queryClient, result)
  return result
}

const running = new Map<number, Promise<ProcessResult[]>>()

/** Envoi des commandes en attente du compte ; un seul passage à la fois. */
export function sendPendingEntries(queryClient: QueryClient, userId: number): Promise<ProcessResult[]> {
  const current = running.get(userId)
  if (current) return current
  const pass = processPending(userId, appOutboxDeps(), (result) => afterResult(queryClient, result))
    .catch(() => [])
    .finally(() => running.delete(userId))
  running.set(userId, pass)
  return pass
}

/** Commandes de la boîte d'envoi du compte, tenues à jour ; undefined pendant la première lecture. */
export function useOutboxEntries(userId: number | null): OutboxEntry[] | undefined {
  const [state, setState] = useState<{ userId: number; entries: OutboxEntry[] } | null>(null)
  const [version, setVersion] = useState(0)

  useEffect(() => onOutboxChanged(() => setVersion((value) => value + 1)), [])

  useEffect(() => {
    if (userId === null) return
    const subscription = liveQuery(() => listEntries(userId)).subscribe({
      next: (entries) => setState({ userId, entries }),
      // Base indisponible (navigation privée ancienne, quota) : boîte vide.
      error: () => setState({ userId, entries: [] }),
    })
    return () => subscription.unsubscribe()
  }, [userId, version])

  return userId !== null && state?.userId === userId ? state.entries : undefined
}

/** Boîte d'envoi du compte client connecté ; undefined pour les autres rôles. */
export function useClientOutbox(): OutboxEntry[] | undefined {
  const user = useAuthenticatedUser()
  return useOutboxEntries(user.role === 'client' ? user.id : null)
}

/** Commandes qui demandent l'attention : à envoyer, en cours d'envoi ou refusées (pastille de l'onglet « Commandes »). */
export function attentionCount(entries: readonly OutboxEntry[] | undefined, now = Date.now()): number {
  return entries?.filter((entry) => displayStatus(entry, now) !== 'sent').length ?? 0
}

/** Commandes encore à envoyer (pending ou en cours) : confirmation de la déconnexion (§6.3, point 7). */
export function waitingCount(entries: readonly OutboxEntry[] | undefined, now = Date.now()): number {
  return entries?.filter((entry) => ['pending', 'sending'].includes(displayStatus(entry, now))).length ?? 0
}
