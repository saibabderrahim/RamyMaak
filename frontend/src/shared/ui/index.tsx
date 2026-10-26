import {
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
  forwardRef,
  useId,
} from 'react'
import { useTranslation } from 'react-i18next'
import { Icon, type IconName } from './Icon'
import { useErrorMessage } from './useErrorMessage'

export { Icon } from './Icon'
export type { IconName } from './Icon'

function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost'

// Charte Ramy (lot 7) : l'action principale est rouge ; une action destructrice est
// encadrée en rouge foncé, pour ne jamais se confondre avec l'action principale.
const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
  primary: 'border border-brand-600 bg-brand-600 text-white hover:border-brand-700 hover:bg-brand-700 disabled:hover:bg-brand-600',
  secondary: 'border border-slate-300 bg-white text-slate-900 hover:border-field hover:bg-slate-50 disabled:hover:bg-white',
  danger: 'border border-danger bg-white text-danger hover:bg-danger-soft disabled:hover:bg-white',
  ghost: 'border border-transparent text-slate-800 hover:bg-slate-100 disabled:hover:bg-transparent',
}

// Zones tactiles d'au moins 44 px (§7.3).
export const Button = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }>(
  function Button({ variant = 'primary', className, type = 'button', ...props }, ref) {
    return (
      <button
        ref={ref}
        type={type}
        className={cx(
          'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold whitespace-nowrap',
          'transition-[background-color,border-color,transform] duration-100 active:translate-y-px',
          'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
          'disabled:cursor-not-allowed disabled:opacity-55 disabled:active:translate-y-0',
          BUTTON_VARIANTS[variant],
          className,
        )}
        {...props}
      />
    )
  },
)

type FieldProps = {
  label: string
  hint?: string
  error?: string
  children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby'?: string }) => ReactNode
}

export function Field({ label, hint, error, children }: FieldProps) {
  const id = useId()
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <label htmlFor={id} className="text-sm font-semibold text-slate-900">
        {label}
      </label>
      {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': describedBy })}
      {hint && (
        <p id={`${id}-hint`} className="text-sm text-slate-600">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  )
}

// Bord constant, anneau de focus en contour (jamais un changement de bordure), désactivé sur trois canaux.
const FIELD_CLASSES =
  'w-full rounded-lg border border-field bg-white text-base text-slate-900 placeholder:text-slate-500 ' +
  'focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-focus aria-[invalid=true]:border-danger ' +
  'disabled:cursor-not-allowed disabled:opacity-55'

const INPUT_CLASSES = `min-h-11 px-3 ${FIELD_CLASSES}`

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input(
  { className, ...props },
  ref,
) {
  return <input ref={ref} className={cx(INPUT_CLASSES, className)} {...props} />
})

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select(
  { className, ...props },
  ref,
) {
  return <select ref={ref} className={cx(INPUT_CLASSES, 'pe-2', className)} {...props} />
})

const TEXTAREA_CLASSES = `min-h-24 px-3 py-2 ${FIELD_CLASSES}`

/** Zone de texte (motif, commentaire) : mêmes repères que `Input`. */
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea(
  { className, ...props },
  ref,
) {
  return <textarea ref={ref} className={cx(TEXTAREA_CLASSES, className)} {...props} />
})

export const Checkbox = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { label: string }>(
  function Checkbox({ label, className, ...props }, ref) {
    return (
      <label className={cx('flex min-h-11 cursor-pointer items-center gap-3 text-sm text-slate-900', className)}>
        <input
          ref={ref}
          type="checkbox"
          className="size-5 rounded border-field accent-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
          {...props}
        />
        {label}
      </label>
    )
  },
)

type AlertTone = 'info' | 'success' | 'warning' | 'error'

// Fond pâle et icône de la couleur d'état ; le texte reste à l'encre pour la lisibilité.
const ALERT_TONES: Record<AlertTone, { box: string; icon: string; name: IconName }> = {
  info: { box: 'bg-info-soft', icon: 'text-info', name: 'info' },
  success: { box: 'bg-success-soft', icon: 'text-success', name: 'check' },
  warning: { box: 'bg-warning-soft', icon: 'text-warning', name: 'alert' },
  error: { box: 'bg-danger-soft', icon: 'text-danger', name: 'alert' },
}

export function Alert({ tone = 'info', children, className }: { tone?: AlertTone; children: ReactNode; className?: string }) {
  const style = ALERT_TONES[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={cx('flex gap-3 rounded-lg px-4 py-3 text-sm text-slate-900', style.box, className)}>
      <Icon name={style.name} className={cx('mt-0.5 size-5', style.icon)} />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  )
}

export function ErrorAlert({ error, className }: { error: unknown; className?: string }) {
  const message = useErrorMessage()
  if (!error) return null
  return (
    <Alert tone="error" className={className}>
      {message(error)}
    </Alert>
  )
}

export function Card({ title, actions, children, className }: { title?: string; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={cx('rounded-lg border border-slate-200 bg-white p-4 sm:p-5', className)}>
      {(title || actions) && (
        <header className="mb-4 flex flex-wrap items-center justify-between gap-2">
          {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  )
}

export function PageHeader({ title, actions }: { title: string; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <h1 className="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'brand'

// Pastilles d'état : toujours un libellé, la couleur n'est jamais seule (DESIGN.md §2).
const BADGE_TONES: Record<BadgeTone, string> = {
  neutral: 'bg-slate-100 text-slate-800',
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
  brand: 'bg-brand-50 text-brand-800',
}

export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span className={cx('inline-flex min-h-6 items-center rounded-full px-2.5 text-xs font-semibold whitespace-nowrap', BADGE_TONES[tone])}>{children}</span>
  )
}

export function Spinner({ label }: { label?: string }) {
  const { t } = useTranslation()
  return (
    <div role="status" className="flex items-center justify-center gap-3 p-8 text-slate-600">
      <span className="size-5 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600" aria-hidden="true" />
      <span>{label ?? t('common.loading')}</span>
    </div>
  )
}
