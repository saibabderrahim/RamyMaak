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
  perimeter: { region_ids: number[] }
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
