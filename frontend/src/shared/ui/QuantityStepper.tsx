import { useState } from 'react'
import { useTranslation } from 'react-i18next'

/** Quantité maximale d'une ligne de commande (contrat du lot 5, I-60). */
export const MAX_LINE_QUANTITY = 1_000_000

type Props = {
  /** Nom du produit, pour les libellés annoncés. */
  name: string
  value: number
  onChange: (value: number) => void
  /** Maximum (disponible du client) ; null : pas de maximum affiché (RG-C09), seulement MAX_LINE_QUANTITY. */
  max?: number | null
  disabled?: boolean
}

/**
 * Sélecteur de quantité entière ≥ 0 (catalogue, panier) : boutons − / +
 * de 44 px et saisie au clavier numérique (EF-CLT-02, ENF-11), bloqué au
 * maximum quand il est connu, sinon à MAX_LINE_QUANTITY. 0 retire le produit.
 */
export function QuantityStepper({ name, value, onChange, max = null, disabled = false }: Props) {
  const { t } = useTranslation()
  // Saisie en cours ; sinon la valeur reçue (aucune copie à synchroniser).
  const [typed, setTyped] = useState<string | null>(null)
  const text = typed ?? String(value)

  const limit = Math.min(max ?? MAX_LINE_QUANTITY, MAX_LINE_QUANTITY)
  const clamp = (next: number) => Math.max(0, Math.min(next, limit))
  const commit = (raw: string) => {
    const parsed = Number.parseInt(raw.replace(/\D/g, ''), 10)
    const next = clamp(Number.isNaN(parsed) ? 0 : parsed)
    setTyped(null)
    if (next !== value) onChange(next)
  }

  // Commande groupée en un seul contrôle (charte Ramy, lot 7) : − | quantité | +, bordure foncée dès qu'une quantité est choisie.
  const button =
    'flex w-11 shrink-0 items-center justify-center text-lg font-semibold text-slate-900 transition-colors duration-100 ' +
    'hover:bg-slate-100 active:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent ' +
    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus'

  return (
    <div
      className={`flex h-11 items-stretch overflow-hidden rounded-lg border bg-white ${value > 0 && !disabled ? 'border-slate-900' : 'border-field'} ${
        disabled ? 'opacity-55' : ''
      }`}
    >
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
        className="w-14 border-x border-slate-200 bg-transparent text-center text-base font-semibold tabular-nums text-slate-900 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed"
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
        disabled={disabled || value >= limit}
        onClick={() => onChange(clamp(value + 1))}
      >
        +
      </button>
    </div>
  )
}
