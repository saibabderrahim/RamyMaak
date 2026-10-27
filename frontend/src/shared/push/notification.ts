import type { PushPayload } from '../api/types'
import push from '../i18n/fr/push'
import { NOTIFICATIONS_PATH, internalPath } from './paths'

/**
 * Affichage d'un push par le service worker (lot 8, contrat §2.5 et §7).
 * Fonctions pures, sans DOM ni React : compilées avec le service worker
 * (lib WebWorker) et testées dans Vitest.
 */

export const NOTIFICATION_ICON = '/icons/icon-192.png'
/** Pictogramme monochrome de la barre d'état (Android) : le logo Ramy en blanc sur fond transparent. */
export const NOTIFICATION_BADGE = '/icons/badge-96.png'

/** Push lu et complété : jamais vide, jamais d'adresse hors de l'application. */
export type DisplayedPush = {
  title: string
  body: string
  /** Groupe d'anti-saturation (`ORDER_DECIDED`…) : remplace la notification précédente du même groupe. */
  tag: string | null
  /** Chemin interne ouvert par l'appui ; le centre de notifications à défaut. */
  url: string
  count: number
}

/** Options passées à `showNotification` (`renotify` n'est plus décrit par les types du DOM). */
export type PushNotificationOptions = {
  body: string
  icon: string
  badge: string
  data: { url: string }
  tag?: string
  renotify?: boolean
}

const FALLBACK: DisplayedPush = {
  title: push.sw.fallbackTitle,
  body: push.sw.fallbackBody,
  tag: null,
  url: NOTIFICATIONS_PATH,
  count: 1,
}

function nonEmpty(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * Charge utile d'un push (texte JSON, §2.5). Illisible ou sans titre :
 * « RamyMaak — Nouvelle notification », qui mène au centre de notifications.
 */
export function parsePushPayload(text: string | null | undefined): DisplayedPush {
  if (!text) return FALLBACK
  let data: unknown
  try {
    data = JSON.parse(text)
  } catch {
    return FALLBACK
  }
  if (typeof data !== 'object' || data === null) return FALLBACK

  const payload = data as Partial<Record<keyof PushPayload, unknown>>
  const title = nonEmpty(payload.title)
  if (title === null) return FALLBACK
  const count = typeof payload.count === 'number' && Number.isInteger(payload.count) && payload.count > 1 ? payload.count : 1

  return {
    title,
    body: typeof payload.body === 'string' ? payload.body : '',
    tag: nonEmpty(payload.tag),
    url: internalPath(payload.url) ?? NOTIFICATIONS_PATH,
    count,
  }
}

/**
 * Options de la notification. Un push du même groupe remplace le précédent
 * (`tag`) en prévenant de nouveau (`renotify`) : l'anti-saturation du
 * serveur en limite déjà le rythme (I-109), un remplacement silencieux
 * ferait manquer la nouvelle.
 */
export function notificationOptions(displayed: DisplayedPush): PushNotificationOptions {
  const options: PushNotificationOptions = {
    body: displayed.body,
    icon: NOTIFICATION_ICON,
    badge: NOTIFICATION_BADGE,
    data: { url: displayed.url },
  }
  // `renotify` sans `tag` est refusé par le navigateur (TypeError).
  if (displayed.tag !== null) {
    options.tag = displayed.tag
    options.renotify = true
  }
  return options
}

/** Fenêtre de l'application telle que la voit le service worker. */
export type AppWindow = { url: string; focused: boolean; visibilityState: string }

/**
 * Fenêtre à mettre au premier plan à l'appui sur une notification : celle
 * qui a le focus, sinon une fenêtre visible, sinon la première ; -1 sans fenêtre.
 */
export function pickWindow(windows: readonly AppWindow[]): number {
  const focused = windows.findIndex((window) => window.focused)
  if (focused >= 0) return focused
  const visible = windows.findIndex((window) => window.visibilityState === 'visible')
  if (visible >= 0) return visible
  return windows.length > 0 ? 0 : -1
}

/** Chemin à ouvrir, lu dans les données d'une notification affichée (`data.url`). */
export function clickedPath(data: unknown): string {
  const url = typeof data === 'object' && data !== null ? (data as { url?: unknown }).url : null
  return internalPath(url) ?? NOTIFICATIONS_PATH
}
