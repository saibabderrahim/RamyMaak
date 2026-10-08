import { useSyncExternalStore } from 'react'

/**
 * Installation de la PWA (§9.5) : invite native sur Android et PC
 * (`beforeinstallprompt`), guide manuel sur iPhone et iPad.
 *
 * L'événement peut survenir avant le premier rendu : il est capturé dès le
 * chargement de ce module.
 */
type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

type InstallState = {
  promptEvent: BeforeInstallPromptEvent | null
  installed: boolean
}

const listeners = new Set<() => void>()

function isStandalone(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches === true ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

let state: InstallState = { promptEvent: null, installed: typeof window !== 'undefined' && isStandalone() }

function setState(next: Partial<InstallState>): void {
  state = { ...state, ...next }
  listeners.forEach((listener) => listener())
}

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    setState({ promptEvent: event as BeforeInstallPromptEvent })
  })
  window.addEventListener('appinstalled', () => setState({ promptEvent: null, installed: true }))
}

export function isIos(userAgent = navigator.userAgent): boolean {
  return /iphone|ipad|ipod/i.test(userAgent) || (/macintosh/i.test(userAgent) && navigator.maxTouchPoints > 1)
}

export function useInstallPrompt() {
  const current = useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    () => state,
  )

  return {
    installed: current.installed,
    canPrompt: current.promptEvent !== null,
    needsIosGuide: !current.installed && current.promptEvent === null && isIos(),
    async prompt(): Promise<void> {
      const event = current.promptEvent
      if (!event) return
      await event.prompt()
      const { outcome } = await event.userChoice
      setState({ promptEvent: null, installed: outcome === 'accepted' || state.installed })
    },
  }
}
