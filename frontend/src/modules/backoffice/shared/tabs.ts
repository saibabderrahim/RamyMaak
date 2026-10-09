import { useSearchParams } from 'react-router'

const TAB_PARAM = 'onglet'

/**
 * Onglet courant conservé dans l'adresse (`?onglet=depots`) : un retour
 * arrière ou un lien partagé rouvre le même onglet.
 */
export function useTabParam<T extends string>(tabs: readonly T[], fallback: T): [T, (tab: T) => void] {
  const [params, setParams] = useSearchParams()
  const requested = params.get(TAB_PARAM)
  const current = tabs.find((tab) => tab === requested) ?? fallback

  const select = (tab: T) =>
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous)
        if (tab === fallback) next.delete(TAB_PARAM)
        else next.set(TAB_PARAM, tab)
        return next
      },
      { replace: true },
    )

  return [current, select]
}
