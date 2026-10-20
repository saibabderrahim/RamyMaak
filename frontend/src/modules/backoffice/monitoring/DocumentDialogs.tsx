import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  EXPORT_FORMATS,
  type ExportFormat,
  PICKING_LIST_FORMATS,
  PICKING_LIST_GROUPS,
  type PickingListFormat,
  type PickingListGroup,
} from '../../../shared/api/types'
import { Button, ErrorAlert } from '../../../shared/ui'
import { useOnline } from '../../../shared/pwa/online'
import { formatDayLong } from '../days/dates'
import { Dialog } from '../shared/ui'
import { useOrdersExport, usePickingList } from './api'

type DialogProps = { day: string; open: boolean; onClose: () => void }

const OPTION =
  'flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50'

/** Pied commun : Annuler, Télécharger (désactivé hors ligne ou pendant la préparation du fichier). */
function Actions({ pending, online, onCancel }: { pending: boolean; online: boolean; onCancel: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-2">
      {!online && <p className="text-xs text-slate-700">{t('backoffice.documents.offline')}</p>}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={onCancel}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={pending || !online}>
          {pending ? t('backoffice.documents.downloading') : t('backoffice.documents.download')}
        </Button>
      </div>
    </div>
  )
}

/**
 * Bon de préparation consolidé de la journée (RG-A04, EF-ADV-05) : regroupement
 * par région ou par dépôt, PDF ou Excel. Le fichier est demandé par une requête
 * authentifiée puis enregistré ; un refus (4xx) s'affiche dans la boîte, jamais
 * comme un fichier.
 */
export function PickingListDialog({ day, open, onClose }: DialogProps) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} title={t('backoffice.documents.picking.title', { day: formatDayLong(day) })} onClose={onClose}>
      {open && <PickingListForm day={day} onClose={onClose} />}
    </Dialog>
  )
}

function PickingListForm({ day, onClose }: { day: string; onClose: () => void }) {
  const { t } = useTranslation()
  const online = useOnline()
  const download = usePickingList(day)
  const [group, setGroup] = useState<PickingListGroup>('region')
  const [format, setFormat] = useState<PickingListFormat>('pdf')

  const submit = (event: FormEvent) => {
    event.preventDefault()
    download.mutate({ group, format }, { onSuccess: onClose })
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <p className="text-sm text-slate-600">{t('backoffice.documents.picking.intro')}</p>
      <ErrorAlert error={download.error} />
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.documents.picking.group')}</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {PICKING_LIST_GROUPS.map((value) => (
            <label key={value} className={OPTION}>
              <input type="radio" name="group" value={value} checked={group === value} onChange={() => setGroup(value)} className="size-5 shrink-0 accent-brand-600" />
              <span>
                <span className="block font-semibold text-slate-900">{t(`backoffice.documents.picking.groups.${value}`)}</span>
                <span className="block text-xs text-slate-600">{t(`backoffice.documents.picking.groupHints.${value}`)}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.documents.picking.format')}</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {PICKING_LIST_FORMATS.map((value) => (
            <label key={value} className={OPTION}>
              <input type="radio" name="format" value={value} checked={format === value} onChange={() => setFormat(value)} className="size-5 shrink-0 accent-brand-600" />
              <span className="font-semibold text-slate-900">{t(`backoffice.documents.picking.formats.${value}`)}</span>
            </label>
          ))}
        </div>
      </fieldset>
      <Actions pending={download.isPending} online={online} onCancel={onClose} />
    </form>
  )
}

/** Export des commandes de la journée (RG-A04, EF-ADV-07) : Excel, CSV pour Excel français ou format ERP provisoire. */
export function ExportDialog({ day, open, onClose }: DialogProps) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} title={t('backoffice.documents.exportDialog.title', { day: formatDayLong(day) })} onClose={onClose}>
      {open && <ExportForm day={day} onClose={onClose} />}
    </Dialog>
  )
}

function ExportForm({ day, onClose }: { day: string; onClose: () => void }) {
  const { t } = useTranslation()
  const online = useOnline()
  const download = useOrdersExport(day)
  const [format, setFormat] = useState<ExportFormat>('xlsx')

  const submit = (event: FormEvent) => {
    event.preventDefault()
    download.mutate(format, { onSuccess: onClose })
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <p className="text-sm text-slate-600">{t('backoffice.documents.exportDialog.intro')}</p>
      <ErrorAlert error={download.error} />
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-medium text-slate-800">{t('backoffice.documents.exportDialog.format')}</legend>
        <div className="flex flex-col gap-2">
          {EXPORT_FORMATS.map((value) => (
            <label key={value} className={OPTION}>
              <input type="radio" name="format" value={value} checked={format === value} onChange={() => setFormat(value)} className="size-5 shrink-0 accent-brand-600" />
              <span>
                <span className="block font-semibold text-slate-900">{t(`backoffice.documents.exportDialog.formats.${value}`)}</span>
                <span className="block text-xs text-slate-600">{t(`backoffice.documents.exportDialog.formatHints.${value}`)}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>
      <Actions pending={download.isPending} online={online} onCancel={onClose} />
    </form>
  )
}
