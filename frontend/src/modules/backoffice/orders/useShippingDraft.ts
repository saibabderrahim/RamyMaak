import { useState } from 'react'
import type { AdvOrder, AdvOrderLine, ShippingBody } from '../../../shared/api/types'
import {
  hasShippingEdits,
  parseQuantity,
  type ShippingEdit,
  type ShippingEdits,
  type ShippingProblem,
  shippingLines,
  shippingProblems,
} from './model'

/**
 * Brouillon de l'expédition (RG-A03, I-77) : quantités expédiées, motifs
 * d'écart et bon de livraison saisis sur le détail. Seules les saisies sont
 * gardées : tant qu'il n'y en a pas, l'écran montre toujours l'état à jour
 * du serveur (relectures en temps réel comprises). La version de la commande
 * à la première saisie est celle envoyée : si un autre utilisateur la change
 * entre-temps, le serveur refuse (409) plutôt que d'écraser son travail.
 */
export function useShippingDraft(order: AdvOrder) {
  const [edits, setEdits] = useState<ShippingEdits>({})
  const [noteEdit, setNoteEdit] = useState<string | null>(null)
  const [base, setBase] = useState<number | null>(null)

  const noteChanged = noteEdit !== null && noteEdit.trim() !== (order.delivery_note_ref ?? '')
  const dirty = hasShippingEdits(order.lines, edits) || noteChanged
  const stale = dirty && base !== null && base !== order.version
  const problems: Record<number, ShippingProblem> = shippingProblems(order.lines, edits)

  // Première saisie d'un brouillon propre : la version lue à cet instant fait foi.
  const touch = () => setBase((current) => (dirty ? current : order.version))

  const patch = (line: AdvOrderLine, change: ShippingEdit) => {
    touch()
    setEdits((current) => ({ ...current, [line.product_id]: { ...current[line.product_id], ...change } }))
  }

  /** Une quantité qui atteint la validée n'a plus de motif d'écart (il doit être absent). */
  const setQty = (line: AdvOrderLine, text: string) => {
    const qty = parseQuantity(text)
    patch(line, qty !== null && qty >= (line.validated_qty ?? 0) ? { qty: text, reason: '' } : { qty: text })
  }

  const setReason = (line: AdvOrderLine, reason: string) => patch(line, { reason })

  const setNote = (text: string) => {
    touch()
    setNoteEdit(text)
  }

  const reset = () => {
    setEdits({})
    setNoteEdit(null)
    setBase(null)
  }

  const lines = shippingLines(order.lines, edits)
  const body: ShippingBody | null = dirty
    ? {
        version: base ?? order.version,
        ...(lines.length > 0 ? { lines } : {}),
        ...(noteChanged ? { delivery_note_ref: (noteEdit ?? '').trim() === '' ? null : (noteEdit ?? '').trim() } : {}),
      }
    : null

  return {
    edits,
    note: noteEdit ?? order.delivery_note_ref ?? '',
    dirty,
    stale,
    valid: Object.keys(problems).length === 0,
    problems,
    body,
    /** Lignes envoyées, dans l'ordre : l'indice d'une erreur 422 `lines.N` y renvoie. */
    submittedLines: lines,
    setQty,
    setReason,
    setNote,
    reset,
  }
}

export type ShippingDraft = ReturnType<typeof useShippingDraft>
