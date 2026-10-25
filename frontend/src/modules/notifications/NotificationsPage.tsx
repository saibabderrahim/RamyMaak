import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import type { AppNotification } from '../../shared/api/types'
import { formatDateTime } from '../../shared/format'
import {
  internalPath,
  type NotificationFilter,
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
  useNotificationsRefreshInterval,
  useUnreadNotificationsCount,
} from '../../shared/notifications'
import { Badge, Button, ErrorAlert, PageHeader, Spinner } from '../../shared/ui'

const FILTERS: readonly NotificationFilter[] = ['all', 'unread']

// Filtre dans l'adresse : le retour depuis l'écran ouvert le retrouve.
const UNREAD_PARAM = 'non-lues'

/**
 * Centre de notifications (EF-NOT-01), pour tous les rôles : plus récentes
 * d'abord, lues et non lues, « Tout marquer comme lu ». Un appui sur une
 * notification la marque comme lue et ouvre l'écran concerné (EF-NOT-03).
 * Écran pensé pour le téléphone : une ligne par notification, grandes zones
 * tactiles.
 */
export default function NotificationsPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const filter: NotificationFilter = searchParams.get('filtre') === UNREAD_PARAM ? 'unread' : 'all'
  const refetchInterval = useNotificationsRefreshInterval()
  const notifications = useNotifications(filter, refetchInterval)
  const unreadCount = useUnreadNotificationsCount(refetchInterval)
  const markRead = useMarkNotificationRead()
  const markAll = useMarkAllNotificationsRead()

  // Une notification arrivée entre deux pages décale la suivante : dédoublonnage.
  const items = useMemo(() => {
    const byId = new Map<string, AppNotification>()
    for (const page of notifications.data?.pages ?? []) for (const notification of page.data) byId.set(notification.id, notification)
    return [...byId.values()]
  }, [notifications.data])
  const unread = unreadCount.data ?? notifications.data?.pages[0]?.meta.unread_count ?? 0

  const selectFilter = (next: NotificationFilter) =>
    setSearchParams(
      (params) => {
        const updated = new URLSearchParams(params)
        if (next === 'unread') updated.set('filtre', UNREAD_PARAM)
        else updated.delete('filtre')
        return updated
      },
      { replace: true },
    )

  return (
    <>
      <PageHeader
        title={t('notifications.title')}
        actions={
          <Button variant="secondary" onClick={() => markAll.mutate()} disabled={unread === 0 || markAll.isPending}>
            {t('notifications.markAllRead')}
          </Button>
        }
      />

      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div role="group" aria-label={t('notifications.filter.label')} className="inline-flex rounded-lg bg-slate-100 p-1">
            {FILTERS.map((value) => (
              <button
                key={value}
                type="button"
                aria-pressed={filter === value}
                onClick={() => selectFilter(value)}
                className={`min-h-11 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                  filter === value ? 'bg-white text-brand-800' : 'text-slate-700 hover:bg-slate-200'
                }`}
              >
                {t(`notifications.filter.${value}`)}
              </button>
            ))}
          </div>
          <p className="text-sm text-slate-600" aria-live="polite">
            {unread > 0 ? t('notifications.unreadCount', { count: unread }) : t('notifications.allRead')}
          </p>
        </div>

        <ErrorAlert error={markAll.error ?? markRead.error} />

        {notifications.error && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={notifications.error} />
            <Button variant="secondary" className="self-start" onClick={() => void notifications.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {notifications.isPending && !notifications.error && <Spinner />}

        {notifications.data &&
          (items.length === 0 ? (
            <p className="py-6 text-center text-sm text-slate-600">
              {filter === 'unread' ? t('notifications.emptyUnread') : t('notifications.empty')}
            </p>
          ) : (
            <ul aria-label={t('notifications.list')} className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
              {items.map((notification) => (
                <NotificationItem
                  key={notification.id}
                  notification={notification}
                  onOpen={() => {
                    if (notification.read_at === null) markRead.mutate(notification)
                  }}
                />
              ))}
            </ul>
          ))}

        {notifications.hasNextPage && (
          <Button variant="secondary" onClick={() => void notifications.fetchNextPage()} disabled={notifications.isFetchingNextPage}>
            {notifications.isFetchingNextPage ? t('common.loading') : t('notifications.more')}
          </Button>
        )}
      </div>
    </>
  )
}

const ITEM_CLASSES =
  'flex w-full min-h-11 items-start gap-3 px-4 py-3 text-start focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus'

/**
 * Une notification : non lue en gras, avec une pastille. Avec un écran à
 * ouvrir, c'est un lien ; sinon, un appui la marque simplement comme lue.
 */
function NotificationItem({ notification, onOpen }: { notification: AppNotification; onOpen: () => void }) {
  const { t } = useTranslation()
  const unread = notification.read_at === null
  const path = internalPath(notification.url)

  const content = (
    <>
      <span aria-hidden="true" className={`mt-1.5 size-2.5 shrink-0 rounded-full ${unread ? 'bg-brand-600' : 'bg-transparent'}`} />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        {unread && <span className="sr-only">{t('notifications.unread')} : </span>}
        <span className="flex items-start justify-between gap-2">
          <span className={`min-w-0 break-words ${unread ? 'font-bold text-slate-900' : 'font-normal text-slate-800'}`}>{notification.title}</span>
          {notification.code && <Badge tone={unread ? 'brand' : 'neutral'}>{notification.code}</Badge>}
        </span>
        {notification.body && <span className="text-sm break-words text-slate-700">{notification.body}</span>}
        <time dateTime={notification.created_at} className="text-xs text-slate-500">
          {formatDateTime(notification.created_at)}
        </time>
      </span>
    </>
  )

  const background = unread ? 'bg-brand-50/60 hover:bg-brand-50' : 'hover:bg-slate-50'

  return (
    <li data-unread={unread || undefined}>
      {path !== null ? (
        <Link to={path} onClick={onOpen} className={`${ITEM_CLASSES} ${background}`}>
          {content}
        </Link>
      ) : unread ? (
        <button type="button" onClick={onOpen} className={`${ITEM_CLASSES} ${background}`}>
          {content}
        </button>
      ) : (
        <div className={ITEM_CLASSES}>{content}</div>
      )}
    </li>
  )
}
