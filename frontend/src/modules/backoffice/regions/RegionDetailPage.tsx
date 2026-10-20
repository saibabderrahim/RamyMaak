import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router'
import type { Assignment } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { Alert, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { ConfirmDialog } from '../../../shared/ui/ConfirmDialog'
import { useBackoffice } from '../shared/access'
import { DetailList } from '../shared/ui'
import { useAssignments, useDepots, useEndAssignment, useRegion, useUpdateRegion } from './api'
import { AssignmentForm } from './AssignmentForm'
import { AssignmentHistory, CurrentAssignments } from './AssignmentLists'
import { RegionStatusBadge } from './components'
import { RegionForm } from './RegionForm'

/**
 * Fiche d'une région (EF-ADM-03) : informations, titulaire, suppléants avec
 * leur période, ADV, historique complet (RG-S03). Avec region.manage :
 * modification, ajout et fin d'affectation. L'ADV consulte sans action.
 */
export default function RegionDetailPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const location = useLocation()
  const { base, can } = useBackoffice()
  const manage = can('region.manage')
  const today = businessToday()

  const region = useRegion(id)
  const assignments = useAssignments(id)
  const depots = useDepots(manage)
  const updateRegion = useUpdateRegion(id)
  const endAssignment = useEndAssignment(id)

  const [ending, setEnding] = useState<Assignment | null>(null)
  const [notice, setNotice] = useState<string | null>((location.state as { created?: boolean } | null)?.created ? t('backoffice.regions.created') : null)

  if (region.isPending) return <Spinner />
  if (region.error) return <ErrorAlert error={region.error} />

  const current = region.data

  const closeEnd = () => {
    setEnding(null)
    endAssignment.reset()
  }

  const confirmEnd = () => {
    if (!ending) return
    endAssignment.mutate(ending.id, {
      onSuccess: () => {
        setEnding(null)
        setNotice(t('backoffice.regions.assignments.ended'))
      },
    })
  }

  return (
    <>
      <PageHeader
        title={`${current.code} — ${current.name}`}
        actions={
          <Link to={`${base}/regions`} className="text-sm text-brand-700 hover:underline">
            {t('backoffice.regions.detail.back')}
          </Link>
        }
      />

      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <div className="flex min-w-0 flex-col gap-6">
          <Card title={t('backoffice.regions.detail.current')}>
            <ErrorAlert error={assignments.error} className="mb-4" />
            <CurrentAssignments region={current} history={assignments.data} today={today} onEnd={manage ? setEnding : undefined} />
          </Card>

          {manage && (
            <Card title={t('backoffice.regions.assignments.add')}>
              <AssignmentForm
                regionId={id}
                history={assignments.data ?? [...(current.titular ? [current.titular] : []), ...current.deputies]}
                onCreated={() => setNotice(t('backoffice.regions.assignments.created'))}
              />
            </Card>
          )}

          <Card title={t('backoffice.regions.detail.history')}>
            {assignments.isPending ? <Spinner /> : <AssignmentHistory history={assignments.data ?? []} today={today} />}
          </Card>
        </div>

        <div className="flex min-w-0 flex-col gap-6">
          <Card title={t('backoffice.regions.detail.information')}>
            <DetailList
              items={[
                { label: t('backoffice.regions.fields.code'), value: current.code },
                { label: t('backoffice.regions.fields.name'), value: current.name },
                { label: t('backoffice.regions.fields.depot'), value: current.depot ? `${current.depot.code} — ${current.depot.name}` : null },
                { label: t('backoffice.regions.columns.status'), value: <RegionStatusBadge active={current.is_active} /> },
                {
                  label: t('backoffice.regions.detail.clients'),
                  value: (
                    <span className="flex flex-wrap items-baseline gap-3">
                      <span className="tabular-nums">{current.clients_count}</span>
                      <Link to={`${base}/clients?region_id=${current.id}`} className="text-brand-700 hover:underline">
                        {t('backoffice.regions.detail.viewClients')}
                      </Link>
                    </span>
                  ),
                  wide: true,
                },
              ]}
            />
          </Card>

          {manage && (
            <Card title={t('backoffice.regions.detail.edit')}>
              {updateRegion.isSuccess && (
                <Alert tone="success" className="mb-4">
                  {t('backoffice.regions.detail.updated')}
                </Alert>
              )}
              {/* Liste des dépôts attendue : une liste déroulante non contrôlée
                  perdrait sinon le dépôt actuel à l'arrivée des options. */}
              {depots.isPending ? (
                <Spinner />
              ) : (
                <RegionForm
                  key={`${current.name}|${current.depot?.id ?? ''}|${current.is_active}`}
                  region={current}
                  depots={depots.data}
                  submitting={updateRegion.isPending}
                  error={updateRegion.error}
                  onSubmit={({ name, depot_id, is_active }) => updateRegion.mutateAsync({ name, depot_id, is_active })}
                />
              )}
            </Card>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={ending !== null}
        title={t('backoffice.regions.assignments.endTitle')}
        confirmLabel={t('backoffice.regions.assignments.endConfirmButton')}
        tone="danger"
        pending={endAssignment.isPending}
        error={endAssignment.error}
        onCancel={closeEnd}
        onConfirm={confirmEnd}
      >
        {ending && <p>{t('backoffice.regions.assignments.endConfirm', { name: ending.user.name, kind: ending.kind_label })}</p>}
      </ConfirmDialog>
    </>
  )
}
