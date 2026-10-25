import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { RegionalDashboard, RegionalDashboardRegion } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { CallLink } from '../clients/components'
import { phonesOf } from '../clients/contact'
import { submissionTime } from '../orders/model'
import { RegionStatusBadge } from '../quotas/components'
import { distributionPath } from '../quotas/format'
import { clientPath, cutoffParts, distributionAction, ordersToValidatePath, type RegionWithoutOrder, withoutOrderGroups } from './model'

const LINK =
  'inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
const PRIMARY = 'bg-brand-600 text-white hover:bg-brand-700'
const SECONDARY = 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'

/**
 * Carte d'une région (EF-REG-01) : statut, heures limites applicables
 * (prolongation comprise ; la validation « bloquante » quand le paramètre de la
 * journée refuse la validation après l'heure), commandes à valider avec le lien
 * vers leur liste, bilan des décisions, clients et accès à la grille de
 * répartition.
 */
export function RegionDayCard({ region, day, validationBlocking }: { region: RegionalDashboardRegion; day: string; validationBlocking: boolean }) {
  const { t } = useTranslation()
  const headingId = useId()
  const { SUBMITTED: toValidate, VALIDATED: validated, REJECTED: rejected, CLOSED: closed } = region.orders
  const parts = cutoffParts(region, validationBlocking)
  const action = distributionAction(region)
  const actionLabel = action === null ? '' : t(`orders.myDay.region.${action === 'view' ? 'seeDistribution' : 'distribute'}`)

  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 id={headingId} className="text-lg font-semibold text-slate-900">
            {region.region.name}
          </h2>
          <p className="text-sm text-slate-600">{region.region.code}</p>
        </div>
        <RegionStatusBadge status={region.status} />
      </header>

      <p className="text-sm text-slate-800" data-testid="region-cutoffs">
        <span className="sr-only">{t('orders.myDay.region.cutoffs')} : </span>
        {parts.map(({ kind, time, notes }, index) => (
          <span key={kind}>
            {index > 0 && ' · '}
            {t(`orders.myDay.region.${kind}`, { time })}
            {notes.length > 0 && <span className="font-semibold text-amber-800"> ({notes.map((note) => t(`orders.myDay.region.${note}`)).join(', ')})</span>}
          </span>
        ))}
      </p>
      {validationBlocking && <p className="text-xs text-slate-600">{t('orders.myDay.region.blockingNote', { time: region.cutoffs.validation })}</p>}

      <div className="flex flex-col gap-2 rounded-lg bg-slate-50 p-3">
        {toValidate > 0 ? (
          <Link
            to={ordersToValidatePath(day, region.region.id)}
            aria-label={t('orders.myDay.region.toValidateLabel', { count: toValidate, name: region.region.name })}
            className={`${LINK} ${PRIMARY} w-full sm:w-auto sm:self-start`}
          >
            {t('orders.myDay.region.toValidate', { count: toValidate })}
          </Link>
        ) : (
          <p className="text-sm font-medium text-slate-800">{t('orders.myDay.region.nothingToValidate')}</p>
        )}
        {toValidate > 0 && region.oldest_submitted_at !== null && (
          <p className="text-xs text-slate-600">{t('orders.myDay.region.oldest', { time: submissionTime(region.oldest_submitted_at, day) })}</p>
        )}
        <p className="text-sm text-slate-700">
          {t('orders.myDay.region.counts', { validated: formatQuantity(validated), rejected: formatQuantity(rejected), closed: formatQuantity(closed) })}
        </p>
      </div>

      <p className="text-sm text-slate-700" data-testid="region-clients">
        {region.distribution_published_at === null
          ? t('orders.myDay.region.clientsWaiting')
          : t('orders.myDay.region.clients', {
              notified: formatQuantity(region.clients.notified),
              ordered: formatQuantity(region.clients.ordered),
              without: formatQuantity(region.clients.without_order),
            })}
      </p>

      {action !== null && (
        <Link
          to={distributionPath(day, region.region.id)}
          aria-label={t('orders.myDay.region.distributionLabel', { action: actionLabel, name: region.region.name })}
          className={`${LINK} w-full sm:w-auto sm:self-start ${action === 'distribute' ? PRIMARY : SECONDARY}`}
        >
          {actionLabel}
        </Link>
      )}
    </section>
  )
}

/**
 * « Clients sans commande » (EF-REG-01, I-88) : par région, les clients notifiés
 * de la répartition qui n'ont pas de commande comptée, avec l'appel en un geste
 * et le lien vers leur fiche.
 */
export function ClientsWithoutOrder({ dashboard }: { dashboard: RegionalDashboard }) {
  const { t } = useTranslation()
  const headingId = useId()
  const groups = withoutOrderGroups(dashboard)

  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-4">
      <h2 id={headingId} className="text-lg font-semibold text-slate-900">
        {t('orders.myDay.withoutOrder.title')}
      </h2>
      {groups.map((group) => (
        <RegionClients key={group.region.region.id} group={group} />
      ))}
    </section>
  )
}

function RegionClients({ group }: { group: RegionWithoutOrder }) {
  const { t } = useTranslation()
  const headingId = useId()
  const { region, clients, sent } = group

  return (
    <div role="group" aria-labelledby={headingId} className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 id={headingId} className="font-semibold text-slate-900">
          {region.region.name}
        </h3>
        {sent && clients.length > 0 && <p className="text-sm text-slate-600">{t('orders.myDay.withoutOrder.count', { count: clients.length })}</p>}
      </div>

      {!sent ? (
        <p className="text-sm text-slate-600">{t('orders.myDay.withoutOrder.waiting')}</p>
      ) : clients.length === 0 ? (
        <p className="text-sm text-slate-600">{t('orders.myDay.withoutOrder.none')}</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {clients.map((client) => (
            <ClientRow key={client.id} client={client} />
          ))}
        </ul>
      )}
    </div>
  )
}

function ClientRow({ client }: { client: RegionalDashboard['clients_without_order'][number] }) {
  const { t } = useTranslation()
  const phones = phonesOf(client)

  return (
    <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-x-3">
        <p className="min-w-0 break-words">
          <span className="font-medium text-slate-900">{client.name}</span> <span className="text-sm text-slate-600">{client.code}</span>
        </p>
        <Link
          to={clientPath(client.id)}
          aria-label={t('orders.myDay.withoutOrder.sheetLabel', { name: client.name })}
          className="inline-flex min-h-11 items-center rounded-lg font-medium text-brand-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
          {t('orders.myDay.withoutOrder.sheet')}
        </Link>
      </div>
      {phones.length === 0 ? (
        <CallLink phone={null} name={client.name} />
      ) : (
        <ul aria-label={t('orders.myDay.withoutOrder.phones', { name: client.name })} className="flex flex-col gap-2">
          {phones.map((phone) => (
            <li key={phone} className="flex items-center justify-between gap-3">
              <span dir="ltr" className="text-base font-medium text-slate-900">
                {phone}
              </span>
              <CallLink phone={phone} name={client.name} />
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}
