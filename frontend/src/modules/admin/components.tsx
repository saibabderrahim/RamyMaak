import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Paginated, User } from '../../shared/api/types'
import { Alert, Badge, Button } from '../../shared/ui'

export function Pagination({ meta, onPage }: { meta: Paginated<unknown>['meta']; onPage: (page: number) => void }) {
  const { t } = useTranslation()
  if (meta.total === 0) return null
  return (
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
      <span>{t('common.pagination', { page: meta.current_page, pages: meta.last_page, total: meta.total })}</span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          {t('common.previous')}
        </Button>
        <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          {t('common.next')}
        </Button>
      </div>
    </div>
  )
}

export function UserStatusBadges({ user }: { user: User }) {
  const { t } = useTranslation()
  return (
    <span className="inline-flex flex-wrap gap-1">
      {user.is_active ? <Badge tone="success">{t('admin.users.status.active')}</Badge> : <Badge>{t('admin.users.status.inactive')}</Badge>}
      {user.is_locked && <Badge tone="danger">{t('admin.users.status.locked')}</Badge>}
      {user.must_change_password && <Badge tone="warning">{t('admin.users.status.mustChange')}</Badge>}
    </span>
  )
}

/**
 * Mot de passe temporaire affiché une seule fois, à remettre en main propre
 * (EF-AUTH-05, EF-ADM-02).
 */
export function TemporaryPasswordNotice({ user, password }: { user: User; password: string }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    await navigator.clipboard?.writeText(password)
    setCopied(true)
  }

  return (
    <Alert tone="warning">
      <p className="mb-2 font-semibold">{t('admin.users.temporaryTitle')}</p>
      <p className="mb-3">{t('admin.users.temporaryIntro', { name: user.name, username: user.username })}</p>
      <div className="flex flex-wrap items-center gap-3">
        <code className="rounded-md bg-white px-3 py-2 font-mono text-lg tracking-wider text-slate-900" data-testid="temporary-password">
          {password}
        </code>
        <Button variant="secondary" onClick={() => void copy()}>
          {copied ? t('common.copied') : t('common.copy')}
        </Button>
      </div>
    </Alert>
  )
}
