import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { ReasonCode, ReasonType } from '../../../shared/api/types'
import { Alert, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { ConfirmDialog } from '../../../shared/ui/ConfirmDialog'
import { useBackoffice } from '../shared/access'
import { useTabParam } from '../shared/tabs'
import { ActiveBadge, DataTable, Dialog, EmptyState, TabPanel, Tabs, TableHead, Th } from '../shared/ui'
import { useAdminReasons, useCreateReason, useUpdateReason } from './api'
import { canRequireComment, changesOf, createBody, isLastActive, type ReasonTab, TAB_OF_TYPE, TABS, typeOfTab } from './model'
import { ReasonForm } from './ReasonForm'

/**
 * Motifs (EF-ADM-05) : `/admin/motifs`, un onglet par type (rejet,
 * ajustement, écart d'expédition, annulation) ; liste désactivés compris,
 * création, modification (libellé, commentaire obligatoire, ordre — le code
 * et le type ne changent plus), activation et désactivation. Un motif ne se
 * supprime pas : les commandes déjà traitées gardent son libellé (I-82).
 * Réservé à l'Admin (`settings.manage`) ; contrat : docs/lot-6-contrat-api.md §4 et §8.
 */
export default function ReasonCodesPage() {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const manage = can('settings.manage')
  const [tab, setTab] = useTabParam<ReasonTab>(TABS, TAB_OF_TYPE.REJECTION)
  const type = typeOfTab(tab)

  return (
    <>
      <PageHeader title={t('backoffice.reasons.title')} />
      {!manage && (
        <Alert tone="info" className="mb-4">
          {t('backoffice.common.readOnly')}
        </Alert>
      )}
      <Tabs
        id="reasons"
        label={t('backoffice.reasons.sections')}
        tabs={TABS.map((id) => ({ id, label: t(`backoffice.reasons.types.${typeOfTab(id)}`) }))}
        value={tab}
        onChange={setTab}
      />
      <TabPanel id="reasons" tab={tab}>
        <ReasonsPanel key={type} type={type} manage={manage} />
      </TabPanel>
    </>
  )
}

function CreateReasonDialog({ type, onClose, onDone }: { type: ReasonType; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const create = useCreateReason()
  return (
    <Dialog open title={t('backoffice.reasons.create')} onClose={onClose}>
      <ReasonForm
        initialType={type}
        submitting={create.isPending}
        error={create.error}
        onCancel={onClose}
        onSubmit={async (values) => {
          await create.mutateAsync(createBody(values))
          onDone()
        }}
      />
    </Dialog>
  )
}

function EditReasonDialog({ reason, onClose, onDone }: { reason: ReasonCode; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const update = useUpdateReason()
  return (
    <Dialog open title={t('backoffice.reasons.edit', { code: reason.code })} onClose={onClose}>
      <ReasonForm
        reason={reason}
        initialType={reason.type}
        submitting={update.isPending}
        error={update.error}
        onCancel={onClose}
        onSubmit={async (values) => {
          const changes = changesOf(reason, values)
          // Rien n'a changé : pas d'écriture (ni version, ni journal).
          if (Object.keys(changes).length > 0) await update.mutateAsync({ id: reason.id, changes })
          onDone()
        }}
      />
    </Dialog>
  )
}

/** Motifs d'un type : tableau (désactivés compris), création, modification, activation. */
function ReasonsPanel({ type, manage }: { type: ReasonType; manage: boolean }) {
  const { t } = useTranslation()
  const reasons = useAdminReasons(type)
  const toggle = useUpdateReason()
  const [dialog, setDialog] = useState<'create' | ReasonCode | null>(null)
  const [confirm, setConfirm] = useState<ReasonCode | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const list = reasons.data ?? []
  const typeLabel = t(`backoffice.reasons.types.${type}`)
  const commentable = canRequireComment(type)

  const open = (next: 'create' | ReasonCode) => {
    setNotice(null)
    setDialog(next)
  }

  const setActive = (reason: ReasonCode, active: boolean) => {
    setNotice(null)
    toggle.mutate(
      { id: reason.id, changes: { is_active: active } },
      {
        onSuccess: () => {
          setConfirm(null)
          setNotice(t(active ? 'backoffice.reasons.activated' : 'backoffice.reasons.deactivated'))
        },
      },
    )
  }

  // Désactiver le dernier motif actif d'un type demande confirmation : plus aucun choix ne serait proposé.
  const requestToggle = (reason: ReasonCode) => {
    if (reason.is_active && isLastActive(list, reason)) {
      toggle.reset()
      setConfirm(reason)
    } else {
      setActive(reason, !reason.is_active)
    }
  }

  return (
    <Card
      title={typeLabel}
      actions={
        manage ? (
          <Button onClick={() => open('create')}>{t('backoffice.reasons.create')}</Button>
        ) : undefined
      }
    >
      <p className="mb-1 text-sm text-slate-700">{t(`backoffice.reasons.intro.${type}`)}</p>
      <p className="mb-4 text-sm text-slate-600">{t('backoffice.reasons.deactivateHint')}</p>
      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}
      <ErrorAlert error={reasons.error} className="mb-4" />
      {confirm === null && <ErrorAlert error={toggle.error} className="mb-4" />}
      {reasons.isPending && <Spinner />}

      {reasons.data && (
        <>
          <DataTable label={typeLabel} className="min-w-[640px]">
            <TableHead>
              <Th>{t('backoffice.reasons.columns.code')}</Th>
              <Th>{t('backoffice.reasons.columns.label')}</Th>
              <Th>{t('backoffice.reasons.columns.comment')}</Th>
              <Th className="text-end">{t('backoffice.reasons.columns.sortOrder')}</Th>
              <Th>{t('backoffice.reasons.columns.status')}</Th>
              {manage && (
                <Th>
                  <span className="sr-only">{t('backoffice.common.actions')}</span>
                </Th>
              )}
            </TableHead>
            <tbody className="divide-y divide-slate-100">
              {list.map((reason) => (
                <tr key={reason.id} className={`hover:bg-slate-50 ${reason.is_active ? '' : 'text-slate-500'}`} data-active={reason.is_active}>
                  <td className="py-2 pe-4 font-mono text-xs">{reason.code}</td>
                  <td className="py-2 pe-4">{reason.label}</td>
                  <td className="py-2 pe-4">
                    {commentable ? t(reason.requires_comment ? 'backoffice.reasons.comment.required' : 'backoffice.reasons.comment.optional') : '—'}
                  </td>
                  <td className="py-2 pe-4 text-end tabular-nums">{reason.sort_order}</td>
                  <td className="py-2 pe-4">
                    <ActiveBadge
                      active={reason.is_active}
                      labels={{ active: t('backoffice.reasons.status.active'), inactive: t('backoffice.reasons.status.inactive') }}
                    />
                  </td>
                  {manage && (
                    <td className="py-1 text-end whitespace-nowrap">
                      <Button variant="ghost" aria-label={t('backoffice.reasons.editActionLabel', { label: reason.label })} onClick={() => open(reason)}>
                        {t('backoffice.reasons.editAction')}
                      </Button>
                      <Button
                        variant="ghost"
                        aria-label={t(reason.is_active ? 'backoffice.reasons.deactivateLabel' : 'backoffice.reasons.activateLabel', { label: reason.label })}
                        disabled={toggle.isPending && toggle.variables?.id === reason.id}
                        onClick={() => requestToggle(reason)}
                      >
                        {t(reason.is_active ? 'backoffice.reasons.deactivate' : 'backoffice.reasons.activate')}
                      </Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </DataTable>
          {list.length === 0 && <EmptyState>{t('backoffice.reasons.empty')}</EmptyState>}
        </>
      )}

      {dialog === 'create' && (
        <CreateReasonDialog
          type={type}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null)
            setNotice(t('backoffice.reasons.created'))
          }}
        />
      )}
      {dialog !== null && dialog !== 'create' && (
        <EditReasonDialog
          reason={dialog}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null)
            setNotice(t('backoffice.reasons.updated'))
          }}
        />
      )}
      <ConfirmDialog
        open={confirm !== null}
        title={t('backoffice.reasons.lastActive.title')}
        confirmLabel={t('backoffice.reasons.lastActive.confirm')}
        tone="danger"
        pending={toggle.isPending}
        error={toggle.error}
        onConfirm={() => confirm && setActive(confirm, false)}
        onCancel={() => setConfirm(null)}
      >
        {confirm && (
          <p>
            {t('backoffice.reasons.lastActive.text', {
              label: confirm.label,
              consequence: t(`backoffice.reasons.lastActive.consequence.${type}`),
            })}
          </p>
        )}
      </ConfirmDialog>
    </Card>
  )
}
