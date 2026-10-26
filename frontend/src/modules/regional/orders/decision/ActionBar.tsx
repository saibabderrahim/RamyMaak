import type { ReactNode } from 'react'

/**
 * Barre d'actions fixe de la décision (contrat du lot 6, §8) : en bas de
 * l'écran, au-dessus de la barre d'onglets du téléphone (3,5 rem, zone de
 * sécurité comprise) ; contre le bas de l'écran dès qu'elle disparaît (grand
 * écran). Les actions tiennent sur une ligne, la plus longue à la place restante,
 * et passent à la ligne si le libellé l'exige. Un espace vide, placé en fin de
 * page (`order-last` dans la colonne de l'écran), évite qu'elle masque le dernier
 * bloc (l'historique).
 */
export function ActionBar({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      {/* Placé en fin de page même quand la barre est rendue au milieu (la page est une colonne flex). */}
      <div aria-hidden="true" data-testid="action-bar-spacer" className="order-last h-32 sm:h-24" />
      <div
        role="group"
        aria-label={label}
        className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 border-t border-slate-200 bg-white lg:bottom-0 lg:shadow-[0_-8px_24px_rgb(20_16_13/0.08)]"
      >
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-end gap-2 px-4 py-3">{children}</div>
      </div>
    </>
  )
}
