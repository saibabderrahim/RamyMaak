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

export const IMPORT_TYPES = ['CLIENTS', 'PRODUCTS', 'PRICES'] as const
export type ImportType = (typeof IMPORT_TYPES)[number]

export type ImportStatus = 'PENDING' | 'ANALYZING' | 'READY' | 'INVALID' | 'APPLIED' | 'FAILED'

export type ImportError = { row: number | null; column: string | null; message: string }

export type ImportPreviewRow = {
  row: number
  key: string
  action: 'create' | 'update'
  changes: Record<string, { old: unknown; new: unknown }>
}

export type Import = {
  id: number
  type: ImportType
  type_label: string
  status: ImportStatus
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
