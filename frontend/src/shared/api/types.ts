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
  'ORDER_SUBMITTED',
  'ORDER_CHANGED',
  'ORDER_DECIDED',
  'ORDER_EXPIRED',
  // N-09 (lot 6) : commande expédiée.
  'ORDER_SHIPPED',
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

/* ---------- Commandes client (lot 5) — contrat : docs/lot-5-contrat-api.md ---------- */

export const ORDER_STATUSES = [
  'SUBMITTED',
  'VALIDATED',
  'REJECTED',
  'CANCELLED',
  'EXPIRED',
  'IN_PREPARATION',
  'PREPARED',
  'SHIPPED',
  'DELIVERED',
] as const
export type OrderStatus = (typeof ORDER_STATUSES)[number]

/** État de la prise de commande d'un client pour une journée (§11.3). */
export const CLIENT_DAY_STATUSES = ['WAITING_QUOTAS', 'ORDERING_OPEN', 'ORDERING_CLOSED', 'DAY_CLOSED'] as const
export type ClientDayStatus = (typeof CLIENT_DAY_STATUSES)[number]

/** Raison pour laquelle le client ne peut pas envoyer de commande ; null : envoi possible. */
export type SubmitBlocker = 'CLIENT_BLOCKED' | 'MAX_ORDERS_REACHED' | 'DAY_NOT_OPEN' | 'CUTOFF_PASSED'

/**
 * Produit du catalogue du jour. limit / available : borne publiée et disponible D(c,p), null sans borne
 * (rien n'est affiché, RG-C09) ; engaged : quantité déjà demandée ou validée par le client ce jour-là.
 */
export type ClientProduct = {
  id: number
  sku: string
  name: string
  short_name: string
  family_id: number
  format: string | null
  sales_unit_label: string
  units_per_sales_unit: number
  vat_rate: number
  price: PriceRef
  photos: ProductPhoto[]
  is_new: boolean
  is_featured: boolean
  limit: number | null
  engaged: number
  available: number | null
  sold_out: boolean
}

export type ClientFamily = { id: number; code: string; name: string; sort_order: number }

export type OrderSummary = {
  id: string
  number: string
  day: string
  status: OrderStatus
  status_label: string
  version: number
  submitted_at: string
  total_ht: number
  total_ttc: number
  lines_count: number
  is_adjusted: boolean
  can_modify: boolean
  can_cancel: boolean
}

export type ClientToday = {
  /** Heure du serveur (ISO 8601 UTC) : l'heure de l'appareil ne fait pas foi (D-10). */
  server_time: string
  day: string
  delivery_date: string | null
  status: ClientDayStatus
  /** "HH:MM" Africa/Algiers, prolongation de la région comprise. */
  ordering_cutoff: string | null
  ordering_cutoff_at: string | null
  ordering_cutoff_extended: boolean
  client: { id: number; code: string; name: string; status: ClientStatus }
  max_orders_per_day: number
  min_amount_ht: number | null
  can_submit: boolean
  submit_blocker: SubmitBlocker | null
  orders: OrderSummary[]
  families: ClientFamily[]
  products: ClientProduct[]
}

export type OrderLine = {
  product_id: number
  sku: string
  name: string
  short_name: string
  sales_unit_label: string
  photo: { thumb: string; medium: string } | null
  requested_qty: number
  validated_qty: number | null
  shipped_qty: number | null
  unit_price_ht: number
  vat_rate: number
  amount_ht: number
  adjustment_reason_code: string | null
  /** Motif d'ajustement et son libellé (lot 6, motif désactivé compris) ; null sur une ligne entière. */
  adjustment_reason: ReasonRef | null
  /** Commentaire d'ajustement de la ligne (≤ 200), visible du client (I-73). */
  adjustment_comment: string | null
  /**
   * Motif d'écart d'expédition (SHIPPING_GAP, RG-A03) ; null sans écart. Côté client, présent seulement
   * une fois la commande expédiée (I-79) : absent et null se lisent de la même façon.
   */
  shipping_gap_reason?: ReasonRef | null
}

export type OrderActor = 'CLIENT' | 'REGIONAL' | 'ADV' | 'SYSTEM'

export type OrderHistoryEntry = {
  from: OrderStatus | null
  to: OrderStatus
  version: number
  at: string
  actor: OrderActor
  /** Nom de l'auteur : comptes du client seulement côté client ; tous côté régional. */
  by_name: string | null
  comment: string | null
}

export type Order = OrderSummary & {
  submitted_by: { id: number; name: string } | null
  comment: string | null
  total_vat: number
  decided_at: string | null
  decision_reason_code: string | null
  /** Motif de rejet, ou d'annulation par l'ADV (I-75), avec son libellé (lot 6). */
  decision_reason: ReasonRef | null
  decision_comment: string | null
  /** Heure limite de modification et d'annulation (ISO 8601), null hors statut Envoyée. */
  modifiable_until: string | null
  /** Expédition (lot 6) : côté client, présents seulement une fois la commande expédiée (I-79). */
  shipped_at?: string | null
  delivery_note_ref?: string | null
  lines: OrderLine[]
  history: OrderHistoryEntry[]
}

export type OrderLineInput = { product_id: number; quantity: number }

export type SubmitOrderInput = {
  client_request_id: string
  day: string
  comment: string | null
  lines: OrderLineInput[]
}

export type UpdateOrderInput = { version: number; comment: string | null; lines: OrderLineInput[] }

/** Refus d'un envoi : PRODUCT_NOT_AVAILABLE (sans requested/max_allowed) ou CLIENT_LIMIT_EXCEEDED. */
export type OrderViolation = {
  line: number
  product_id: number
  requested?: number
  /** Maximum possible ; null sans borne (le reste de la région n'est jamais montré, RG-C09). */
  max_allowed?: number | null
}

/** Commande vue par le régional (lecture au lot 5). */
export type RegionalOrderSummary = {
  id: string
  number: string
  day: string
  status: OrderStatus
  status_label: string
  version: number
  submitted_at: string
  updated_at: string
  client: { id: number; code: string; name: string; status: ClientStatus }
  region: RegionRef
  lines_count: number
  total_ht: number
  is_adjusted: boolean
  /** Décision (lot 6) : instant et auteur réel (titulaire ou suppléant, RG-S03) ; null tant que la commande est Envoyée. */
  decided_at: string | null
  decided_by: ActorRef | null
}

/**
 * Contexte de quotas d'une ligne pour le régional : borne, validé du client, quota, validé et reste de la région ;
 * `max_validatable` (lot 6) = max(0, min(demandé, R, B − V(c,p))), « Ajuster au maximum possible » (RG-V04).
 */
export type RegionalOrderLineContext = {
  limit: number | null
  client_validated: number
  region_quota: number
  region_validated: number
  region_remaining: number
  max_validatable: number
}

export type RegionalOrderLine = OrderLine & { context: RegionalOrderLineContext }

export type RegionalOrder = Omit<Order, 'lines' | 'can_modify' | 'can_cancel' | 'modifiable_until'> & {
  client: { id: number; code: string; name: string; status: ClientStatus; phone: string | null; phone2: string | null }
  region: RegionRef
  lines: RegionalOrderLine[]
  /** Auteur réel de la décision (RG-S03) ; null tant que la commande est Envoyée. */
  decided_by: ActorRef | null
  /** Heure limite de validation de la région (prolongation comprise, RG-V08). */
  validation: ValidationWindow
  /** Décision possible (lot 6, contrat §3) ; le serveur reste seul juge. */
  can_validate: boolean
  can_reject: boolean
  /** Pourquoi la validation est impossible alors que la commande est Envoyée ; null sinon. */
  blocked_reason: DecisionBlocker | null
}

export type RegionalOrdersPage = Paginated<RegionalOrderSummary> & {
  meta: Paginated<RegionalOrderSummary>['meta'] & { counts: { SUBMITTED: number; VALIDATED: number; REJECTED: number; CLOSED: number } }
}

/* ---------- Validation et ADV (lot 6) — contrat : docs/lot-6-contrat-api.md ---------- */

/** Réponse enveloppée `{ "data": … }` (« Ma journée », suivi de la journée). */
export type DataResponse<T> = { data: T }

/** Auteur nommé (décision, envoi). */
export type ActorRef = { id: number; name: string }

/** Types de motifs (EF-ADM-05, §10.3). */
export const REASON_TYPES = ['REJECTION', 'ADJUSTMENT', 'SHIPPING_GAP', 'CANCELLATION'] as const
export type ReasonType = (typeof REASON_TYPES)[number]

/** Motif cité par une commande : code et libellé (un motif désactivé garde son libellé, I-82). */
export type ReasonRef = { code: string; label: string }

/** Motif actif proposé à la saisie (`GET /reason-codes?type=…`). */
export type ReasonOption = {
  type: ReasonType
  code: string
  label: string
  /** Commentaire obligatoire avec ce motif (AUTRE). */
  requires_comment: boolean
  sort_order: number
}

/** Motif vu par l'Admin (`GET /admin/reason-codes`), désactivés compris. */
export type ReasonCode = ReasonOption & {
  id: number
  is_active: boolean
  created_at: string
  updated_at: string
}

/** Création (`POST /admin/reason-codes`) : code `^[A-Z][A-Z0-9_]{1,29}$`, unique par type, immuable ensuite. */
export type ReasonCodeInput = { type: ReasonType; code: string; label: string; requires_comment?: boolean; sort_order?: number }

/** Modification (`PATCH /admin/reason-codes/{id}`) : ni code ni type (422). */
export type ReasonCodeUpdate = { label?: string; is_active?: boolean; requires_comment?: boolean; sort_order?: number }

/** Heure limite de validation d'une région pour la journée de la commande (RG-V08, I-71). */
export type ValidationWindow = {
  /** "HH:MM" Africa/Algiers, prolongation comprise. */
  cutoff: string
  cutoff_at: string
  extended: boolean
  /** `cutoffs.validation_blocking` : l'heure limite refuse la validation (jamais le rejet). */
  blocking: boolean
  passed: boolean
}

/** Raison pour laquelle une commande Envoyée ne peut pas être validée (le rejet reste possible sauf DAY_NOT_OPEN). */
export type DecisionBlocker = 'CLIENT_BLOCKED' | 'CUTOFF_PASSED' | 'DAY_NOT_OPEN'

/**
 * Validation (`POST /regional/orders/{id}/validate`) : toutes les lignes de la commande ; motif ADJUSTMENT
 * (et commentaire ≤ 200, visible du client) sur les lignes réduites seulement, absents ou null sinon (I-72).
 */
export type ValidateOrderBody = {
  version: number
  lines: { product_id: number; validated_qty: number; reason_code?: string | null; comment?: string | null }[]
}

/** Rejet (`POST /regional/orders/{id}/reject`) : motif REJECTION ; commentaire ≤ 500, obligatoire si le motif l'exige. */
export type RejectOrderBody = { version: number; reason_code: string; comment?: string | null }

/**
 * Ligne en cause d'un refus REGION_QUOTA_EXCEEDED ou CLIENT_LIMIT_EXCEEDED (I-69) : `line` est l'indice (à partir
 * de 0) de la ligne dans `lines` de la requête ; `requested` la quantité à valider ; `limit` null sans borne.
 */
export type DecisionViolation = {
  line: number
  product_id: number
  sku: string
  requested: number
  quota: number
  validated: number
  limit: number | null
  client_validated: number
  max_allowed: number
  cause: 'REGION_QUOTA' | 'CLIENT_LIMIT'
}

/* « Ma journée » du régional (EF-REG-01) : `GET /regional/dashboard?day=` → RegionalDashboard (réponse sans enveloppe `data`, I-96). */

/** Compteurs par onglet des commandes reçues (CLOSED : annulées et expirées). */
export type RegionalOrderCounts = RegionalOrdersPage['meta']['counts']

export type RegionalDashboardRegion = {
  region: RegionRef
  status: RegionDayStatus
  status_label: string
  quota_published_at: string | null
  distribution_published_at: string | null
  cutoffs: { distribution: string; ordering: string; validation: string }
  extended: { distribution: boolean; ordering: boolean; validation: boolean }
  orders: RegionalOrderCounts
  oldest_submitted_at: string | null
  /** notified : destinataires de N-03 ; ordered : clients ayant une commande comptée ; without_order : notifiés actifs sans commande (I-88). */
  clients: { notified: number; ordered: number; without_order: number }
}

export type ClientWithoutOrder = {
  id: number
  code: string
  name: string
  status: ClientStatus
  region_id: number
  phone: string | null
  phone2: string | null
}

export type RegionalDashboard = {
  day: string
  /** false : journée non ouverte, listes vides. */
  opened: boolean
  status: DayStatus | null
  cutoffs: DayCutoffs | null
  validation_blocking: boolean
  regions: RegionalDashboardRegion[]
  clients_without_order: ClientWithoutOrder[]
}

/* Traitement ADV (EF-ADV-04 à EF-ADV-06). */

/** Onglets et compteurs de la file ADV : Validée et suivantes (Livrée exclue, V2), Annulée après validation. */
export const ADV_ORDER_STATUSES = ['VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'CANCELLED'] as const
export type AdvOrderStatus = (typeof ADV_ORDER_STATUSES)[number]

/** Étapes du traitement en masse (I-78), une à la fois : VALIDATED → IN_PREPARATION → PREPARED → SHIPPED. */
export const PROCESSING_STATUSES = ['IN_PREPARATION', 'PREPARED', 'SHIPPED'] as const
export type ProcessingStatus = (typeof PROCESSING_STATUSES)[number]

export type DepotRef = { id: number; code: string; name: string }

export type AdvOrderSummary = {
  id: string
  number: string
  day: string
  status: OrderStatus
  status_label: string
  version: number
  submitted_at: string
  decided_at: string | null
  decided_by: ActorRef | null
  prep_started_at: string | null
  prepared_at: string | null
  shipped_at: string | null
  delivery_note_ref: string | null
  client: { id: number; code: string; name: string; status: ClientStatus }
  region: RegionRef
  /** Dépôt actuel de la région (non figé) ; null sans dépôt. */
  depot: DepotRef | null
  lines_count: number
  total_ht: number
  total_ttc: number
  is_adjusted: boolean
  has_shipping_gap: boolean
  /** Étapes permises à l'appelant (vide pour l'Admin, lecture seule). */
  next_statuses: ProcessingStatus[]
  can_cancel: boolean
}

export type AdvOrderCounts = Record<AdvOrderStatus, number>

/** `GET /adv/orders` : `counts` suit les mêmes filtres, sauf le statut. */
export type AdvOrdersPage = Paginated<AdvOrderSummary> & {
  meta: Paginated<AdvOrderSummary>['meta'] & { counts: AdvOrderCounts }
}

export type AdvOrderLine = OrderLine & { shipping_gap_reason: ReasonRef | null }

/** `GET /adv/orders/{id}` (et réponse de l'expédition et de l'annulation). */
export type AdvOrder = Omit<AdvOrderSummary, 'client'> & {
  client: AdvOrderSummary['client'] & {
    address: string | null
    wilaya: string | null
    commune: string | null
    phone: string | null
    phone2: string | null
    nif: string | null
    rc: string | null
  }
  comment: string | null
  total_vat: number
  /** Motif de rejet ou d'annulation par l'ADV (I-75). */
  decision_reason: ReasonRef | null
  decision_comment: string | null
  lines: AdvOrderLine[]
  /** Auteurs nommés. */
  history: OrderHistoryEntry[]
  can: {
    next_statuses: ProcessingStatus[]
    /** Quantités expédiées et motifs d'écart : En préparation et Préparée (I-77). */
    edit_shipping_lines: boolean
    /** Bon de livraison : jusqu'à Expédiée. */
    edit_delivery_note: boolean
    cancel: boolean
    /** Bon de commande PDF (order.process, I-86). */
    print: boolean
  }
}

/** `POST /adv/orders/bulk-status` : 1 à 100 commandes distinctes, version de chacune. */
export type BulkStatusBody = { status: ProcessingStatus; orders: { id: string; version: number }[] }

export type BulkRefusalCode = 'NOT_FOUND' | 'INVALID_TRANSITION' | 'VERSION_CONFLICT'

/** Résultat partiel (I-78) : un refus n'annule pas les autres. */
export type BulkStatusResult = {
  applied: { id: string; number: string; status: OrderStatus; version: number }[]
  /** `number` null pour une commande inconnue ou hors périmètre ; `version` actuelle sur VERSION_CONFLICT. */
  refused: { id: string; number: string | null; code: BulkRefusalCode; detail: string; version?: number }[]
}

/**
 * Expédition (`PATCH /adv/orders/{id}/shipping`) : lignes facultatives et partielles ; motif SHIPPING_GAP
 * si et seulement si `shipped_qty` < validé ; `delivery_note_ref` ≤ 50, null pour l'effacer, absent pour le garder.
 */
export type ShippingBody = {
  version: number
  delivery_note_ref?: string | null
  lines?: { product_id: number; shipped_qty: number; reason_code?: string | null }[]
}

/** Annulation exceptionnelle (`POST /adv/orders/{id}/cancel`) : motif CANCELLATION, depuis Validée ou En préparation. */
export type AdvCancelBody = { version: number; reason_code: string; comment?: string | null }

/* Suivi de la journée (EF-ADV-03) : `GET /adv/days/{day}/monitoring` → DayMonitoring (réponse sans enveloppe `data`, I-96). */

export const MONITORING_ORDER_STATUSES = [
  'SUBMITTED',
  'VALIDATED',
  'IN_PREPARATION',
  'PREPARED',
  'SHIPPED',
  'REJECTED',
  'CANCELLED',
  'EXPIRED',
] as const
export type MonitoringOrderCounts = Record<(typeof MONITORING_ORDER_STATUSES)[number], number>

/** Alertes visuelles (I-89) ; N-13 au lot 7. */
export type MonitoringAlert = 'DISTRIBUTION_LATE' | 'VALIDATION_LATE'

export type DayMonitoringRegion = {
  region: RegionRef & { depot: DepotRef | null }
  status: RegionDayStatus
  status_label: string
  quota_published_at: string | null
  distribution_published_at: string | null
  cutoffs: { distribution: string; ordering: string; validation: string }
  clients: { notified: number; ordered: number }
  orders: MonitoringOrderCounts
  last_validated_at: string | null
  alerts: MonitoringAlert[]
}

/** Totaux d'un produit sur les régions visibles ; épuisé dans une région : Q > 0 et R = 0. */
export type DayMonitoringProduct = {
  product_id: number
  sku: string
  short_name: string
  family: { id: number; name: string }
  quota: number
  validated: number
  pending: number
  remaining: number
  sold_out_regions: number[]
}

export type MonitoringCell = { quota: number; validated: number; pending: number }

export type DayMonitoring = {
  day: string
  status: DayStatus
  cutoffs: DayCutoffs
  generated_at: string
  totals: { orders: MonitoringOrderCounts; clients: { notified: number; ordered: number } }
  regions: DayMonitoringRegion[]
  products: DayMonitoringProduct[]
  /** cells[regionId][productId] (ordre inverse de la matrice des quotas) ; seules les cellules existantes. */
  cells: Record<string, Record<string, MonitoringCell>>
}

/* Documents et exports (I-83) : téléchargés par fetchFile, jamais par un lien direct. */

export const PICKING_LIST_FORMATS = ['pdf', 'xlsx'] as const
export type PickingListFormat = (typeof PICKING_LIST_FORMATS)[number]

export const PICKING_LIST_GROUPS = ['region', 'depot'] as const
export type PickingListGroup = (typeof PICKING_LIST_GROUPS)[number]

/** `erp` : format provisoire `erp-v0` (Q-14, I-85). */
export const EXPORT_FORMATS = ['xlsx', 'csv', 'erp'] as const
export type ExportFormat = (typeof EXPORT_FORMATS)[number]
