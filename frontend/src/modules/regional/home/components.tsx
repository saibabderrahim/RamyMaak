import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { RegionalDashboard, RegionalDashboardRegion } from '../../../shared/api/types'
import { businessToday, formatQuantity } from '../../../shared/format'
import { Alert } from '../../../shared/ui'
import { minutesOf, useBusinessNowOn } from '../../../shared/ui/clock'
import { DayRail } from '../../../shared/ui/DayRail'
import { CallLink } from '../clients/components'
import { phonesOf } from '../clients/contact'
import { submissionTime } from '../orders/model'
import { RegionStatusBadge } from '../quotas/components'
import { distributionPath } from '../quotas/format'
import { clientPath, type CutoffPart, cutoffParts, distributionAction, ordersToValidatePath, type RegionWithoutOrder, withoutOrderGroups } from './model'

const LINK =
  'inline-flex min-h-12 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors duration-100 active:translate-y-px ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
const PRIMARY = 'border border-brand-600 bg-brand-600 text-white hover:border-brand-700 hover:bg-brand-700'
const SECONDARY = 'border border-slate-300 bg-white text-slate-900 hover:border-field hover:bg-slate-50'

/** Chiffres en colonnes séparées par un filet (charte Ramy, lot 7) ; la phrase équivalente est lue par les lecteurs d'écran. */
function Figures({ items }: { items: { label: string; value: number }[] }) {
  return (
    <dl aria-hidden="true" className="grid grid-cols-3">
      {items.map((item, index) => (
        <div key={item.label} className={`flex flex-col gap-0.5 ${index === 0 ? 'pe-3' : 'border-s border-slate-200 px-3'} ${index === items.length - 1 ? 'pe-0' : ''}`}>
          <dt className="text-xs leading-snug text-slate-600">{item.label}</dt>
          <dd className="text-xl leading-tight font-semibold tabular-nums text-slate-900">{formatQuantity(item.value)}</dd>
        </div>
      ))}
    </dl>
  )
}

/**
 * Carte d'une région (EF-REG-01) : statut, fil de la journée avec les heures
 * limites applicables (prolongation comprise ; la validation « bloquante » quand
 * le paramètre de la journée refuse la validation après l'heure), alerte de
 * répartition en retard, commandes à valider avec le lien vers leur liste, bilan
 * des décisions, clients et accès à la grille de répartition.
 */
export function RegionDayCard({
  region,
  day,
  validationBlocking,
  closing = null,
}: {
  region: RegionalDashboardRegion
  day: string
  validationBlocking: boolean
  /** Heure de clôture de la journée, dernier arrêt du fil. */
  closing?: string | null
}) {
  const { t } = useTranslation()
  const headingId = useId()
  const { SUBMITTED: toValidate, VALIDATED: validated, REJECTED: rejected, CLOSED: closed } = region.orders
  const parts = cutoffParts(region, validationBlocking)
  const action = distributionAction(region)
  const actionLabel = action === null ? '' : t(`orders.myDay.region.${action === 'view' ? 'seeDistribution' : 'distribute'}`)
  // Heure de l'appareil, journée en cours seulement : repère du fil et alerte indicative de retard.
  const now = useBusinessNowOn(day, businessToday())
  const distributionCutoff = minutesOf(region.cutoffs.distribution)
  // Quotas publiés, répartition non envoyée et heure limite de répartition passée (comme l'alerte du suivi ADV).
  const late =
    action === 'distribute' && region.distribution_published_at === null && now !== null && distributionCutoff !== null && now >= distributionCutoff
  const noteText = (notes: CutoffPart['notes']) => (notes.length > 0 ? notes.map((note) => t(`orders.myDay.region.${note}`)).join(', ') : undefined)

  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 id={headingId} className="text-lg font-semibold text-slate-900">
            {region.region.name}
          </h2>
          <p className="text-sm text-slate-600">{region.region.code}</p>
        </div>
        <RegionStatusBadge status={region.status} />
      </header>

      {late && (
        <Alert tone="warning">
          <p className="font-semibold">{t('orders.myDay.region.lateTitle')}</p>
          <p>{t('orders.myDay.region.lateBody', { time: region.cutoffs.distribution })}</p>
        </Alert>
      )}

      <div aria-hidden="true">
        <DayRail
          label={t('orders.myDay.region.cutoffs')}
          now={now}
          stops={[
            ...parts.map(({ kind, time, notes }) => ({ label: t(`orders.myDay.region.rail.${kind}`), time, minutes: minutesOf(time), note: noteText(notes) })),
            ...(closing ? [{ label: t('orders.myDay.region.rail.closing'), time: closing, minutes: minutesOf(closing) }] : []),
          ]}
        />
      </div>
      <p className="sr-only" data-testid="region-cutoffs">
        <span>{t('orders.myDay.region.cutoffs')} : </span>
        {parts.map(({ kind, time, notes }, index) => (
          <span key={kind}>
            {index > 0 && ' · '}
            {t(`orders.myDay.region.${kind}`, { time })}
            {notes.length > 0 && <span> ({noteText(notes)})</span>}
          </span>
        ))}
      </p>
      {validationBlocking && <p className="text-xs text-slate-600">{t('orders.myDay.region.blockingNote', { time: region.cutoffs.validation })}</p>}

      <div className="flex flex-col gap-1">
        {toValidate > 0 ? (
          <Link
            to={ordersToValidatePath(day, region.region.id)}
            aria-label={t('orders.myDay.region.toValidateLabel', { count: toValidate, name: region.region.name })}
            className={`${LINK} ${PRIMARY} w-full text-base sm:w-auto sm:self-start`}
          >
            {t('orders.myDay.region.toValidate', { count: toValidate })}
          </Link>
        ) : (
          <p className="font-semibold text-slate-900">{t('orders.myDay.region.nothingToValidate')}</p>
        )}
        {toValidate > 0 && region.oldest_submitted_at !== null && (
          <p className="text-sm text-slate-600">{t('orders.myDay.region.oldest', { time: submissionTime(region.oldest_submitted_at, day) })}</p>
        )}
      </div>

      <Figures
        items={[
          { label: t('orders.myDay.region.figures.validated'), value: validated },
          { label: t('orders.myDay.region.figures.rejected'), value: rejected },
          { label: t('orders.myDay.region.figures.closed'), value: closed },
        ]}
      />
      <p className="sr-only">
        {t('orders.myDay.region.counts', { validated: formatQuantity(validated), rejected: formatQuantity(rejected), closed: formatQuantity(closed) })}
      </p>

      {region.distribution_published_at === null ? (
        <p className="text-sm text-slate-600" data-testid="region-clients">
          {t('orders.myDay.region.clientsWaiting')}
        </p>
      ) : (
        <>
          <Figures
            items={[
              { label: t('orders.myDay.region.figures.notified'), value: region.clients.notified },
              { label: t('orders.myDay.region.figures.ordered'), value: region.clients.ordered },
              { label: t('orders.myDay.region.figures.without'), value: region.clients.without_order },
            ]}
          />
          <p className="sr-only" data-testid="region-clients">
            {t('orders.myDay.region.clients', {
              notified: formatQuantity(region.clients.notified),
              ordered: formatQuantity(region.clients.ordered),
              without: formatQuantity(region.clients.without_order),
            })}
          </p>
        </>
      )}

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
        <ul className="divide-y divide-slate-200">
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
          className="inline-flex min-h-11 items-center rounded-lg text-sm font-semibold text-slate-900 underline decoration-1 underline-offset-4 hover:decoration-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
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
              <span dir="ltr" className="text-lg font-semibold tracking-wide tabular-nums text-slate-900">
                {groupedPhone(phone)}
              </span>
              <CallLink phone={phone} name={client.name} />
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

/** « 0770112233 » → « 0770 11 22 33 » pour la lecture à voix haute ; un autre format est laissé tel quel. */
function groupedPhone(phone: string): string {
  const digits = phone.replace(/\s/g, '')
  return /^0\d{9}$/.test(digits) ? digits.replace(/^(\d{4})(\d{2})(\d{2})(\d{2})$/, '$1 $2 $3 $4') : phone
}
