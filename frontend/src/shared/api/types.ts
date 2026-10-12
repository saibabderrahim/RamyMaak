export const ROLES = ['admin', 'adv', 'regional', 'client'] as const
export type Role = (typeof ROLES)[number]

export type CurrentUser = {
  id: number
  username: string
  name: string
  email: string | null
  phone: string | null
  role: Role
  role_label: string
  permissions: string[]
  client_id: number | null
  must_change_password: boolean
  password_min_length: number
  perimeter: { all_regions: boolean; region_ids: number[] }
  last_login_at: string | null
  /** Clé publique Reverb ; null si le temps réel est désactivé (repli : rafraîchissement toutes les 30 s). */
  realtime: { key: string } | null
}

export type User = {
  id: number
  username: string
  name: string
  email: string | null
  phone: string | null
  role: Role
  client_id: number | null
  client: { id: number; code: string; name: string } | null
  is_active: boolean
  is_locked: boolean
  locked_until: string | null
  must_change_password: boolean
  last_login_at: string | null
  created_at: string
  updated_at: string
}

export type UserWithTemporaryPassword = {
  user: User
  temporary_password: string
}

export type Paginated<T> = {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export type AuditLog = {
  id: number
  log_name: string | null
  event: string | null
  description: string
  subject: { type: string; id: number } | null
  causer: { id: number; username: string; name: string } | null
  properties: Record<string, unknown>
  created_at: string
}

export type Settings = {
  cutoffs: {
    distribution: string
    ordering: string
    validation: string
    closing: string
    validation_blocking: boolean
  }
  calendar: { working_days: number[] }
  limits: { no_limit_policy: 'FREE' | 'REQUIRED' }
  orders: { max_per_day: number; min_amount: number | null }
  sessions: { remember_days: number; staff_absolute_hours: number; staff_idle_minutes: number }
  passwords: { min_length: number; min_length_staff: number }
  auth: { max_failed_logins: number; lockout_minutes: number }
  notifications: { email_enabled: boolean; quiet_start: string; quiet_end: string }
}

/* ---------- Référentiels (lot 2) — contrat : docs/lot-2-contrat-api.md ---------- */

export type ListResponse<T> = { data: T[] }

export type UserRef = { id: number; username: string; name: string }

export type Depot = {
  id: number
  code: string
  name: string
  address: string | null
  is_active: boolean
  regions_count: number
}

export const ASSIGNMENT_KINDS = ['TITULAR', 'DEPUTY', 'ADV'] as const
export type AssignmentKind = (typeof ASSIGNMENT_KINDS)[number]

export type Assignment = {
  id: number
  region_id: number
  kind: AssignmentKind
  kind_label: string
  user: UserRef
  starts_on: string
  ends_on: string | null
  is_active: boolean
  revoked_at: string | null
  revoked_by: UserRef | null
  created_at: string
}

export type RegionRef = { id: number; code: string; name: string }

export type Region = RegionRef & {
  is_active: boolean
  depot: { id: number; code: string; name: string } | null
  titular: Assignment | null
  deputies: Assignment[]
  clients_count: number
}

export type ClientCategory = { id: number; code: string; name: string; clients_count: number }

export const CLIENT_STATUSES = ['ACTIVE', 'BLOCKED', 'INACTIVE'] as const
export type ClientStatus = (typeof CLIENT_STATUSES)[number]

export type ClientAccount = { id: number; username: string; is_active: boolean; last_login_at: string | null }

export type Client = {
  id: number
  code: string
  name: string
  trade_name: string | null
  category: { id: number; code: string; name: string } | null
  region: RegionRef
  pending_region_change: { region: RegionRef; effective_on: string } | null
  address: string | null
  wilaya: string | null
  commune: string | null
  lat: number | null
  lng: number | null
  phone: string | null
  phone2: string | null
  email: string | null
  nif: string | null
  nis: string | null
  rc: string | null
  ai: string | null
  status: ClientStatus
  status_label: string
  status_reason: string | null
  status_changed_at: string | null
  /** Présent seulement pour les utilisateurs qui gèrent les comptes. */
  accounts?: ClientAccount[]
  created_at: string
  updated_at: string
}

export type ProductFamily = {
  id: number
  code: string
  name: string
  parent_id: number | null
  sort_order: number
  products_count: number
}

export type ProductPhoto = {
  id: number
  is_main: boolean
  urls: { thumb: string; medium: string; large: string }
}

export type PriceRef = {
  price_ht: number
  price_ttc: number
  unit_price_ht: number
  valid_from: string
}

export type Product = {
  id: number
  sku: string
  name: string
  short_name: string
  family: { id: number; code: string; name: string }
  format: string | null
  sales_unit_label: string
  units_per_sales_unit: number
  sales_units_per_pallet: number | null
  ean: string | null
  vat_rate: number
  is_active: boolean
  /** Désactivation demandée alors qu'un quota publié court aujourd'hui (RG-Q07) : effective ce jour-là (AAAA-MM-JJ). */
  pending_deactivation_on: string | null
  is_new: boolean
  is_featured: boolean
  sort_order: number
  price: PriceRef | null
  next_price: { price_ht: number; valid_from: string } | null
  photos: ProductPhoto[]
  created_at: string
  updated_at: string
}

export type ProductPrice = {
  id: number
  price_ht: number
  valid_from: string
  valid_to: string | null
  created_by: UserRef | null
  created_at: string
}

export const IMPORT_TYPES = ['CLIENTS', 'PRODUCTS', 'PRICES', 'QUOTAS'] as const
export type ImportType = (typeof IMPORT_TYPES)[number]

export const IMPORT_MODES = ['REPLACE', 'COMPLETE'] as const
export type ImportMode = (typeof IMPORT_MODES)[number]

export type ImportStatus = 'PENDING' | 'ANALYZING' | 'READY' | 'INVALID' | 'APPLIED' | 'FAILED'

export type ImportError = { row: number | null; column: string | null; message: string }

export type ImportPreviewRow = {
  /** null : produit absent du fichier, remis à 0 par un import de quotas en mode Remplacer. */
  row: number | null
  key: string
  action: 'create' | 'update'
  changes: Record<string, { old: unknown; new: unknown }>
}

export type Import = {
  id: number
  type: ImportType
  type_label: string
  status: ImportStatus
  /** Import des quotas : journée (AAAA-MM-JJ) et mode ; null pour les référentiels. */
  business_day: string | null
  mode: ImportMode | null
  original_name: string
  rows_total: number
  rows_processed: number
  rows_ok: number
  rows_error: number
  summary: { create: number; update: number; unchanged: number } | null
  errors: ImportError[]
  errors_truncated: boolean
  preview: ImportPreviewRow[]
  preview_truncated: boolean
  has_error_report: boolean
  user: UserRef
  created_at: string
  analyzed_at: string | null
  applied_at: string | null
  failure_message: string | null
}

/* ---------- Quotas ADV (lot 3) — contrat : docs/lot-3-contrat-api.md ---------- */

export const DAY_STATUSES = ['OPEN', 'CLOSED'] as const
export type DayStatus = (typeof DAY_STATUSES)[number]

export const REGION_DAY_STATUSES = [
  'QUOTA_DRAFT',
  'QUOTA_PUBLISHED',
  'DISTRIBUTION_DRAFT',
  'DISTRIBUTION_PUBLISHED',
  'CLOSED',
] as const
export type RegionDayStatus = (typeof REGION_DAY_STATUSES)[number]

export const CUTOFF_KINDS = ['DISTRIBUTION', 'ORDERING', 'VALIDATION', 'CLOSING'] as const
export type CutoffKind = (typeof CUTOFF_KINDS)[number]
/** Heures limites prolongeables pour une région (RG-A05). */
export const EXTENDABLE_CUTOFFS = ['DISTRIBUTION', 'ORDERING'] as const
export type ExtendableCutoff = (typeof EXTENDABLE_CUTOFFS)[number]

/** Heures "HH:MM" dans le fuseau métier (Africa/Algiers). */
export type DayCutoffs = { distribution: string; ordering: string; validation: string; closing: string }
export type RegionCutoffs = { distribution: string; ordering: string }

export type RegionDay = {
  region: RegionRef
  status: RegionDayStatus
  status_label: string
  quota_version: number
  quota_published_at: string | null
  quota_last_published_at: string | null
  /** 0 tant que la répartition n'a pas été envoyée aux clients (lot 4). */
  distribution_version: number
  distribution_published_at: string | null
  /** Brouillons de quotas de l'ADV non publiés. */
  pending_drafts: number
  cutoffs: RegionCutoffs
  extended: { distribution: boolean; ordering: boolean }
}

export type Day = {
  /** AAAA-MM-JJ */
  day: string
  /** 0 = dimanche … 6 = samedi */
  weekday: number
  is_working_day: boolean
  status: DayStatus
  status_label: string
  delivery_date: string
  cutoffs: DayCutoffs
  cutoffs_at: DayCutoffs
  closed_at: string | null
  regions: RegionDay[]
  created_by: UserRef | null
  created_at: string
}

export type QuotaMatrixRegion = RegionRef & {
  status: RegionDayStatus
  quota_version: number
  pending_drafts: number
}

export type QuotaMatrixProduct = {
  id: number
  sku: string
  name: string
  short_name: string
  family: { id: number; code: string; name: string }
  sales_unit_label: string
  is_active: boolean
}

/** quantity : quota publié Q ; draft : brouillon (null = aucun changement) ; validated : compteur V. */
export type QuotaCell = { quantity: number; draft: number | null; validated: number }

export type QuotaMatrix = {
  day: Day
  regions: QuotaMatrixRegion[]
  products: QuotaMatrixProduct[]
  /** cells[productId][regionId] ; seules les cellules existantes en base. */
  cells: Record<string, Record<string, QuotaCell>>
}

export type QuotaCellInput = { region_id: number; product_id: number; quantity: number | null }

export type SavedQuotaCell = QuotaCell & { region_id: number; product_id: number }

export type SaveQuotasResult = { changed: number; cells: SavedQuotaCell[] }

export const COPY_MODES = ['REPLACE', 'FILL'] as const
export type CopyMode = (typeof COPY_MODES)[number]

export type PublicationSkipReason = 'NO_CHANGES' | 'EMPTY' | 'CLOSED'

export type PublicationResult = {
  published: { region_id: number; code: string; version: number; first: boolean; changes: number }[]
  skipped: { region_id: number; code: string; reason: PublicationSkipReason }[]
}

export type QuotaViolation = {
  region_id: number
  region: string
  product_id: number
  sku: string
  validated: number
  requested: number
}

export type RegionalQuota = {
  product: {
    id: number
    sku: string
    name: string
    family: { id: number; code: string; name: string }
    sales_unit_label: string
  }
  quantity: number
  validated: number
}

export type RegionalQuotaRegion = {
  region: RegionRef
  status: RegionDayStatus
  quota_version: number
  quota_last_published_at: string | null
  distribution_version: number
  cutoffs: RegionCutoffs
  quotas: RegionalQuota[]
}

export type RegionalQuotas = {
  day: string
  opened: boolean
  status: DayStatus | null
  cutoffs: DayCutoffs | null
  regions: RegionalQuotaRegion[]
}

export const NOTIFICATION_TYPES = [
  'QUOTAS_PUBLISHED',
  'QUOTAS_UPDATED',
  'CUTOFF_EXTENDED',
  'DISTRIBUTION_PUBLISHED',
  'LIMITS_UPDATED',
] as const
export type NotificationType = (typeof NOTIFICATION_TYPES)[number]

export type AppNotification = {
  id: string
  type: NotificationType
  /** Code de la spécification (N-01, N-02…) ; null pour les notifications sans code. */
  code: string | null
  title: string
  body: string
  /** Chemin de la PWA à ouvrir, ou null. */
  url: string | null
  read_at: string | null
  created_at: string
}

export type NotificationsPage = Paginated<AppNotification> & {
  meta: Paginated<AppNotification>['meta'] & { unread_count: number }
}

/* ---------- Répartition (lot 4) — contrat : docs/lot-4-contrat-api.md ---------- */

/** RG-B04 : sans borne, le client commande dans la limite du reste région (FREE) ou pas du tout (REQUIRED). */
export const NO_LIMIT_POLICIES = ['FREE', 'REQUIRED'] as const
export type NoLimitPolicy = (typeof NO_LIMIT_POLICIES)[number]

export type DistributionGridRegion = RegionRef & {
  status: RegionDayStatus
  status_label: string
  quota_version: number
  distribution_version: number
  distribution_published_at: string | null
  cutoffs: RegionCutoffs
  extended: { distribution: boolean; ordering: boolean }
}

/** Colonne de la grille : quota Q, Σ bornes affichées, en attente S, validé V(r,p), reste R = Q − V. */
export type DistributionGridProduct = {
  id: number
  sku: string
  name: string
  short_name: string
  family: { id: number; code: string; name: string }
  sales_unit_label: string
  quota: number
  sum_limits: number
  pending: number
  validated: number
  remaining: number
}

export type DistributionGridClient = {
  id: number
  code: string
  name: string
  status: Extract<ClientStatus, 'ACTIVE' | 'BLOCKED'>
  category: { id: number; code: string; name: string } | null
}

/**
 * limit : borne publiée (null = pas de borne) ; draft : null = rien à envoyer,
 * { value } = brouillon (value null = suppression de la borne) ; pending / validated : S et V du client.
 */
export type LimitCell = {
  limit: number | null
  draft: { value: number | null } | null
  pending: number
  validated: number
}

export type Overallocation = { product_id: number; sku: string; quota: number; sum_limits: number }

export type PublicationPreview = {
  first: boolean
  drafts: number
  notified_clients: number
  overallocated: Overallocation[]
}

export type DistributionGrid = {
  day: { day: string; status: DayStatus; delivery_date: string; cutoffs: DayCutoffs }
  region: DistributionGridRegion
  policy: NoLimitPolicy
  can_edit: boolean
  can_publish: boolean
  products: DistributionGridProduct[]
  clients: DistributionGridClient[]
  /** cells[clientId][productId] ; seules les cellules renseignées. */
  cells: Record<string, Record<string, LimitCell>>
  publication: PublicationPreview
}

export type LimitCellInput = { client_id: number; product_id: number; limit: number | null }

export type SavedLimitCell = LimitCell & { client_id: number; product_id: number }

export type SaveLimitsResult = { changed: number; status: RegionDayStatus; cells: SavedLimitCell[] }

export type CopyPreviousResult = { changed: number; source_day: string }

export type DistributionResult = {
  version: number
  first: boolean
  changes: number
  notified_clients: number
  overallocated: Overallocation[]
}

/** Refus d'un envoi (VALIDATION_FAILED au-dessus du quota, QUOTA_BELOW_VALIDATED sous le validé). */
export type LimitViolation = {
  client_id: number
  client: string
  product_id: number
  sku: string
  requested: number | null
  quota?: number
  validated?: number
}
