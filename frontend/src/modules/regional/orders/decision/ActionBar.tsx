import type { ReactNode } from 'react'

/**
 * Barre d'actions fixe de la décision (contrat du lot 6, §8) : en bas de
 * l'écran, au-dessus de la barre d'onglets du téléphone (3,5 rem, zone de
 * sécurité comprise) ; contre le bas de l'écran dès qu'elle disparaît (grand
 * écran). Les actions tiennent sur une ligne, la plus longue à la place restante,
 * et passent à la ligne si le libellé l'exige. Un espace vide en fin de page évite
 * qu'elle masque le dernier bloc.
 */
export function ActionBar({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <div aria-hidden="true" className="h-32 sm:h-24" />
      <div
        role="group"
        aria-label={label}
        className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 border-t border-slate-200 bg-white/95 shadow-[0_-2px_8px_rgb(0_0_0/0.06)] backdrop-blur lg:bottom-0"
      >
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-end gap-2 px-4 py-3">{children}</div>
      </div>
    </>
  )
}
