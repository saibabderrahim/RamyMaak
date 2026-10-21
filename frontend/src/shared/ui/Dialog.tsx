import { type ReactNode, useEffect, useId, useLayoutEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'

/**
 * Boîte de dialogue modale des écrans terrain (régional, client), qui ne
 * chargent pas les composants du back-office : focus placé dans la boîte
 * (élément marqué `data-autofocus`, sinon premier élément), gardé à l'intérieur
 * (Tab), Échap pour fermer, focus rendu à l'élément d'origine à la
 * fermeture. Sur téléphone, la boîte s'ouvre en bas de l'écran.
 */

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(node: HTMLElement | null): HTMLElement[] {
  return node ? Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE)) : []
}

type Props = {
  title: string
  onClose: () => void
  children: ReactNode
  size?: 'md' | 'lg'
}

export function Dialog({ title, onClose, children, size = 'md' }: Props) {
  const { t } = useTranslation()
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)

  useLayoutEffect(() => {
    close.current = onClose
  })

  useEffect(() => {
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const node = panel.current
    const initial =
      node?.querySelector<HTMLElement>('[data-autofocus]') ?? focusables(node).find((element) => !element.hasAttribute('data-dialog-close'))
    ;(initial ?? node)?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close.current()
        return
      }
      if (event.key !== 'Tab' || !node) return
      const items = focusables(node)
      const first = items[0]
      const last = items[items.length - 1]
      if (!first || !last) {
        event.preventDefault()
      } else if (event.shiftKey && (document.activeElement === first || document.activeElement === node)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = overflow
      previous?.focus()
    }
  }, [])

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4">
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`max-h-[92dvh] w-full overflow-y-auto rounded-t-xl bg-white p-4 shadow-xl focus:outline-none sm:rounded-xl sm:p-6 ${
          size === 'lg' ? 'sm:max-w-2xl' : 'sm:max-w-lg'
        }`}
      >
        <div className="mb-4 flex items-start justify-between gap-4">
          <h2 id={titleId} className="text-lg font-semibold text-slate-900">
            {title}
          </h2>
          <button
            type="button"
            data-dialog-close
            onClick={() => close.current()}
            aria-label={t('common.close')}
            className="-me-2 -mt-2 inline-flex size-11 items-center justify-center rounded-lg text-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-600"
          >
            <span aria-hidden="true">×</span>
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  )
}
