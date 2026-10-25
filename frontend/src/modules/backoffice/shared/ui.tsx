import { type KeyboardEvent as ReactKeyboardEvent, type ReactNode, useEffect, useId, useLayoutEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Button } from '../../../shared/ui'

/**
 * Composants communs des écrans back-office (référentiels, lot 2) : boîtes
 * de dialogue, onglets, tableaux, fiches. Accessibles au clavier et aux
 * lecteurs d'écran (WCAG 2.1 AA, ENF-11). `Textarea` et `ConfirmDialog`
 * sont dans shared/ui (lot 6) : les écrans terrain s'en servent aussi.
 */

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(node: HTMLElement | null): HTMLElement[] {
  return node ? Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE)) : []
}

type DialogProps = {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  size?: 'md' | 'lg'
}

/**
 * Boîte de dialogue modale : focus placé dans la boîte (premier champ, ou
 * l'élément marqué `data-autofocus`), gardé à l'intérieur (Tab), Échap pour
 * fermer, focus rendu à l'élément d'origine à la fermeture.
 */
export function Dialog({ open, title, onClose, children, size = 'md' }: DialogProps) {
  const { t } = useTranslation()
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)

  useLayoutEffect(() => {
    close.current = onClose
  })

  useEffect(() => {
    if (!open) return
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
  }, [open])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4">
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={cx(
          'max-h-[92dvh] w-full overflow-y-auto rounded-t-xl bg-white p-4 shadow-xl focus:outline-none sm:rounded-lg sm:p-6',
          size === 'lg' ? 'sm:max-w-3xl' : 'sm:max-w-lg',
        )}
      >
        <div className="mb-4 flex items-start justify-between gap-4">
          <h2 id={titleId} className="text-lg font-semibold text-slate-900">
            {title}
          </h2>
          <button
            type="button"
            data-dialog-close
            onClick={() => close.current()}
            aria-label={t('backoffice.common.close')}
            className="-me-2 -mt-2 inline-flex size-11 items-center justify-center rounded-lg text-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-focus"
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

/** Pied de formulaire en boîte de dialogue : Annuler, Enregistrer. */
export function DialogActions({ submitting, onCancel, submitLabel }: { submitting: boolean; onCancel: () => void; submitLabel?: string }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap justify-end gap-2">
      <Button variant="secondary" onClick={onCancel}>
        {t('common.cancel')}
      </Button>
      <Button type="submit" disabled={submitting}>
        {submitting ? t('common.saving') : (submitLabel ?? t('common.save'))}
      </Button>
    </div>
  )
}

type TabItem<T extends string> = { id: T; label: string }

type TabsProps<T extends string> = {
  /** Préfixe des identifiants des onglets et de leurs panneaux. */
  id: string
  label: string
  tabs: ReadonlyArray<TabItem<T>>
  value: T
  onChange: (tab: T) => void
}

/** Onglets (motif ARIA « tabs ») : flèches, Début et Fin pour naviguer. */
export function Tabs<T extends string>({ id, label, tabs, value, onChange }: TabsProps<T>) {
  const buttons = useRef(new Map<T, HTMLButtonElement>())

  const focusTab = (index: number) => {
    const tab = tabs[(index + tabs.length) % tabs.length]
    if (!tab) return
    onChange(tab.id)
    buttons.current.get(tab.id)?.focus()
  }

  const onKeyDown = (event: ReactKeyboardEvent, index: number) => {
    const moves: Record<string, number> = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }
    const target = moves[event.key]
    if (target === undefined) return
    event.preventDefault()
    focusTab(target)
  }

  return (
    <div role="tablist" aria-label={label} className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
      {tabs.map((tab, index) => {
        const selected = tab.id === value
        return (
          <button
            key={tab.id}
            ref={(element) => {
              if (element) buttons.current.set(tab.id, element)
              else buttons.current.delete(tab.id)
            }}
            type="button"
            role="tab"
            id={`${id}-tab-${tab.id}`}
            aria-controls={`${id}-panel-${tab.id}`}
            aria-selected={selected}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(tab.id)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={cx(
              '-mb-px min-h-11 shrink-0 border-b-[3px] px-4 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus',
              selected ? 'border-brand-600 font-semibold text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900',
            )}
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}

export function TabPanel({ id, tab, children }: { id: string; tab: string; children: ReactNode }) {
  return (
    <div role="tabpanel" id={`${id}-panel-${tab}`} aria-labelledby={`${id}-tab-${tab}`}>
      {children}
    </div>
  )
}

/** Tableau défilant horizontalement sur petit écran (tablette). */
export function DataTable({ label, children, className }: { label?: string; children: ReactNode; className?: string }) {
  return (
    <div className="overflow-x-auto">
      <table aria-label={label} className={cx('w-full text-start text-sm', className ?? 'min-w-[720px]')}>
        {children}
      </table>
    </div>
  )
}

export function TableHead({ children }: { children: ReactNode }) {
  return (
    <thead className="border-b border-slate-300 text-xs text-slate-600">
      <tr>{children}</tr>
    </thead>
  )
}

export function Th({ children, className }: { children?: ReactNode; className?: string }) {
  // Alignement par défaut au début, sauf si l'appelant en impose un (quantités et montants à droite) :
  // deux classes text-* sur le même élément, c'est l'ordre de la feuille de style qui gagne.
  const aligned = /(^|\s)text-(end|center|right|left)(\s|$)/.test(className ?? '')
  return (
    <th scope="col" className={cx('py-2 pe-4 font-semibold whitespace-nowrap', !aligned && 'text-start', className)}>
      {children}
    </th>
  )
}

export function EmptyState({ children }: { children: ReactNode }) {
  return <p className="py-6 text-center text-sm text-slate-500">{children}</p>
}

export type DetailItem = { label: string; value: ReactNode; wide?: boolean }

/** Fiche en lecture : libellé et valeur, « — » pour une valeur absente. */
export function DetailList({ items }: { items: DetailItem[] }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
      {items.map((item) => (
        <div key={item.label} className={item.wide ? 'sm:col-span-2' : undefined}>
          <dt className="text-xs font-medium uppercase text-slate-500">{item.label}</dt>
          <dd className="mt-0.5 break-words text-sm text-slate-900">{item.value === null || item.value === undefined || item.value === '' ? '—' : item.value}</dd>
        </div>
      ))}
    </dl>
  )
}

/** Statut actif / inactif d'un référentiel (libellés accordés par l'appelant). */
export function ActiveBadge({ active, labels }: { active: boolean; labels: { active: string; inactive: string } }) {
  return active ? <Badge tone="success">{labels.active}</Badge> : <Badge>{labels.inactive}</Badge>
}

/** Titre de section dans un formulaire long. */
export function FormSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <fieldset className="flex flex-col gap-4">
      <legend className="mb-1 text-sm font-semibold uppercase tracking-wide text-slate-600">{title}</legend>
      {children}
    </fieldset>
  )
}
