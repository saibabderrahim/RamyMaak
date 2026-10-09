import type { ProductFamily } from '../../../shared/api/types'

export type FamilyNode = { family: ProductFamily; depth: number }

/**
 * Familles dans l'ordre de l'arbre (parent puis ses sous-familles, chaque
 * niveau selon `sort_order`), avec leur profondeur pour l'indentation. Une
 * famille dont le parent est absent de la liste est traitée comme racine.
 */
export function orderFamilies(families: readonly ProductFamily[]): FamilyNode[] {
  const ids = new Set(families.map((family) => family.id))
  const children = new Map<number | null, ProductFamily[]>()
  for (const family of families) {
    const parent = family.parent_id !== null && ids.has(family.parent_id) ? family.parent_id : null
    children.set(parent, [...(children.get(parent) ?? []), family])
  }

  const ordered: FamilyNode[] = []
  const seen = new Set<number>()
  const visit = (parent: number | null, depth: number) => {
    const level = [...(children.get(parent) ?? [])].sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name, 'fr'))
    for (const family of level) {
      if (seen.has(family.id)) continue
      seen.add(family.id)
      ordered.push({ family, depth })
      visit(family.id, depth + 1)
    }
  }
  visit(null, 0)

  // Cycle éventuel (données incohérentes) : les familles restantes en fin de liste.
  for (const family of families) if (!seen.has(family.id)) ordered.push({ family, depth: 0 })
  return ordered
}

/** La famille et toutes ses descendantes : parents interdits pour elle. */
export function selfAndDescendants(families: readonly ProductFamily[], id: number): Set<number> {
  const excluded = new Set([id])
  let grew = true
  while (grew) {
    grew = false
    for (const family of families) {
      if (family.parent_id !== null && excluded.has(family.parent_id) && !excluded.has(family.id)) {
        excluded.add(family.id)
        grew = true
      }
    }
  }
  return excluded
}

/** Libellé d'option indenté selon la profondeur. */
export function familyOptionLabel({ family, depth }: FamilyNode): string {
  return `${'\u00a0\u00a0'.repeat(depth)}${depth > 0 ? '└ ' : ''}${family.name} (${family.code})`
}
