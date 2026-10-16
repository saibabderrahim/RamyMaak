import { useState } from 'react'
import { useTranslation } from 'react-i18next'

type Props = {
  /** Nom du produit, pour les libellés annoncés. */
  name: string
  value: number
  onChange: (value: number) => void
  /** Maximum (disponible du client) ; null : pas de maximum affiché (RG-C09). */
  max?: number | null
  disabled?: boolean
}

/**
 * Sélecteur de quantité entière ≥ 0 (catalogue, panier) : boutons − / +
 * de 44 px et saisie au clavier numérique (EF-CLT-02, ENF-11), bloqué au
 * maximum quand il est connu. 0 retire le produit.
 */
export function QuantityStepper({ name, value, onChange, max = null, disabled = false }: Props) {
  const { t } = useTranslation()
  // Saisie en cours ; sinon la valeur reçue (aucune copie à synchroniser).
  const [typed, setTyped] = useState<string | null>(null)
  const text = typed ?? String(value)

  const clamp = (next: number) => Math.max(0, max === null ? next : Math.min(next, max))
  const commit = (raw: string) => {
    const parsed = Number.parseInt(raw.replace(/\D/g, ''), 10)
    const next = clamp(Number.isNaN(parsed) ? 0 : parsed)
    setTyped(null)
    if (next !== value) onChange(next)
  }

  const button =
    'flex size-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg font-semibold text-slate-800 ' +
    'hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

  return (
    <div className="flex items-center gap-1">
      <button
        type="button"
        className={button}
        aria-label={t('common.quantity.decrease', { name })}
        disabled={disabled || value <= 0}
        onClick={() => onChange(clamp(value - 1))}
      >
        −
      </button>
      <input
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        aria-label={t('common.quantity.label', { name })}
        className="h-11 w-16 rounded-lg border border-slate-300 text-center text-base tabular-nums focus-visible:outline-2 focus-visible:outline-brand-600 disabled:bg-slate-100"
        value={text}
        disabled={disabled}
        onChange={(event) => setTyped(event.target.value)}
        onBlur={(event) => commit(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Enter') commit(event.currentTarget.value)
        }}
      />
      <button
        type="button"
        className={button}
        aria-label={t('common.quantity.increase', { name })}
        disabled={disabled || (max !== null && value >= max)}
        onClick={() => onChange(clamp(value + 1))}
      >
        +
      </button>
    </div>
  )
}
