import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { Client } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { Alert, Button, ErrorAlert, Field, Input, PageHeader, Spinner } from '../../../shared/ui'
import { useMyClients } from './api'
import { CallLink, ClientStatusBadge, PendingRegionBadge } from './components'
import { phonesOf } from './contact'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'

/**
 * « Mes clients » (EF-CLI-03) : clients actifs ou bloqués des régions du
 * régional (RG-S01), recherche, statut et appel en un geste. Écran pensé
 * pour le téléphone : une carte par client, gros bouton « Appeler ».
 */
export default function MyClientsPage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const { all_regions: allRegions, region_ids: regionIds } = user.perimeter
  const hasPerimeter = allRegions || regionIds.length > 0
  const [search, setSearch] = useState('')
  const searchInput = useRef<HTMLInputElement>(null)
  const debouncedSearch = useDebouncedValue(search.trim())
  const clients = useMyClients(debouncedSearch, hasPerimeter)

  // Une page chargée après une modification peut répéter un client : dédoublonnage.
  const items = useMemo(() => {
    const byId = new Map<number, Client>()
    for (const page of clients.data?.pages ?? []) for (const client of page.data) byId.set(client.id, client)
    return [...byId.values()]
  }, [clients.data])
  const total = clients.data?.pages[0]?.meta.total ?? 0

  return (
    <>
      <PageHeader title={t('field.clients.title')} />

      {!hasPerimeter ? (
        <Alert>{t('field.clients.noRegion')}</Alert>
      ) : (
        <div className="flex flex-col gap-4">
          {/* Touche « Rechercher » du clavier : on le ferme pour dégager la liste. */}
          <form
            role="search"
            onSubmit={(event) => {
              event.preventDefault()
              searchInput.current?.blur()
            }}
          >
            <Field label={t('field.clients.search')}>
              {(props) => (
                <Input
                  {...props}
                  ref={searchInput}
                  type="search"
                  enterKeyHint="search"
                  autoComplete="off"
                  value={search}
                  placeholder={t('field.clients.searchPlaceholder')}
                  onChange={(event) => setSearch(event.target.value)}
                />
              )}
            </Field>
          </form>

          {clients.error && (
            <div className="flex flex-col gap-3">
              <ErrorAlert error={clients.error} />
              <Button variant="secondary" className="self-start" onClick={() => void clients.refetch()}>
                {t('common.retry')}
              </Button>
            </div>
          )}
          {clients.isPending && !clients.error && <Spinner />}

          {clients.data && (
            <>
              <p className="text-sm text-slate-600" aria-live="polite">
                {items.length < total ? t('field.clients.shown', { shown: items.length, total }) : t('field.clients.count', { count: total })}
              </p>

              {items.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-600">
                  {debouncedSearch ? t('field.clients.noMatch', { search: debouncedSearch }) : t('field.clients.empty')}
                </p>
              ) : (
                <ul aria-label={t('field.clients.title')} aria-busy={clients.isPlaceholderData} className="flex flex-col gap-3">
                  {items.map((client) => (
                    <ClientItem key={client.id} client={client} showRegion={allRegions || regionIds.length > 1} />
                  ))}
                </ul>
              )}

              {clients.hasNextPage && (
                <Button variant="secondary" onClick={() => void clients.fetchNextPage()} disabled={clients.isFetchingNextPage}>
                  {clients.isFetchingNextPage ? t('common.loading') : t('field.clients.more')}
                </Button>
              )}
            </>
          )}
        </div>
      )}
    </>
  )
}

/** Carte d'un client : la zone de texte ouvre la fiche, le bouton appelle. */
function ClientItem({ client, showRegion }: { client: Client; showRegion: boolean }) {
  const blocked = client.status === 'BLOCKED'
  const details = [client.code, client.commune, showRegion ? client.region.name : null].filter(Boolean).join(' · ')

  return (
    <li
      className={`flex items-center gap-3 rounded-xl border bg-white p-3 shadow-xs ${
        blocked ? 'border-red-300 border-s-4 border-s-red-600' : 'border-slate-200'
      }`}
    >
      <Link
        to={String(client.id)}
        className="flex min-h-11 min-w-0 flex-1 flex-col gap-0.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
      >
        <span className="truncate font-semibold text-slate-900">{client.name}</span>
        {client.trade_name && <span className="truncate text-sm text-slate-700">{client.trade_name}</span>}
        <span className="truncate text-sm text-slate-600">{details}</span>
        <span className="mt-1 flex flex-wrap gap-1">
          <ClientStatusBadge status={client.status} />
          {client.pending_region_change && <PendingRegionBadge change={client.pending_region_change} />}
        </span>
      </Link>
      <CallLink phone={phonesOf(client)[0] ?? null} name={client.name} />
    </li>
  )
}
