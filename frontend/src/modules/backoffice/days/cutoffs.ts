import type { Day, RegionCutoffs, RegionDay } from '../../../shared/api/types'

/**
 * Heures limites prolongeables pour une région (RG-A05) : répartition et
 * commande, et depuis le lot 6 la validation du régional (I-71, I-98).
 */
export const EXTENDABLE_CUTOFFS = ['DISTRIBUTION', 'ORDERING', 'VALIDATION'] as const
export type ExtendableCutoff = (typeof EXTENDABLE_CUTOFFS)[number]

/** Région d'une journée avec son heure limite de validation et sa prolongation (`GET /adv/days/{day}`, I-98). */
export type DayRegion = Omit<RegionDay, 'cutoffs' | 'extended'> & {
  cutoffs: RegionCutoffs & { validation: string }
  extended: RegionDay['extended'] & { validation: boolean }
}

/** Journée ouverte, ses régions portant l'heure limite de validation. */
export type DayDetail = Omit<Day, 'regions'> & { regions: DayRegion[] }

/** Clé de l'heure applicable à une région, par heure limite prolongeable. */
export const CUTOFF_KEYS = { DISTRIBUTION: 'distribution', ORDERING: 'ordering', VALIDATION: 'validation' } as const satisfies Record<
  ExtendableCutoff,
  keyof DayRegion['cutoffs']
>
