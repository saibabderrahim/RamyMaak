import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import type { OrderSummary } from '../../../shared/api/types'
import { Alert, Button, ErrorAlert } from '../../../shared/ui'
import { Dialog } from '../../../shared/ui/Dialog'
import { clientKey } from '../api'
import { fetchOrder } from './api'
import { useCart } from './store'

export type ModifyOrderButtonProps = {
  /** Commande à modifier (OrderSummary ou Order) : seuls `id` et `number` servent, la commande est relue. */
  order: Pick<OrderSummary, 'id' | 'number'>
  /** Classes du bouton (largeur, alignement). */
  className?: string
  /** Libellé du bouton ; « Modifier ma commande » par défaut. */
  label?: string
  /**
   * La commande relue ne peut plus être modifiée. L'écran qui cache alors le
   * bouton garde le message ; sans ce rappel, le bouton l'affiche lui-même.
   */
  onNotModifiable?: () => void
}

/**
 * « Modifier ma commande » (RG-C05), partagé par l'accueil, le catalogue et
 * « Mes commandes » (lot de travail E) : la commande est relue (version,
 * lignes et droits à jour), chargée dans le panier (`startEditing`), puis le
 * catalogue s'ouvre. Un panier qui contient autre chose est remplacé après
 * confirmation ; la modification déjà en cours de cette commande est reprise
 * telle quelle. Commande qui ne peut plus être modifiée (`can_modify` faux à
 * la relecture) : message, rien n'est changé, les données du client sont
 * relues.
 */
export function ModifyOrderButton({ order, className, label, onNotModifiable }: ModifyOrderButtonProps) {
  const { t } = useTranslation()
  const { cart, store } = useCart()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [confirming, setConfirming] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [closed, setClosed] = useState(false)

  const resuming = cart.editing?.orderId === order.id
  const otherLines = resuming ? 0 : cart.lines.length
  // Modification d'une autre commande en cours, même sans ligne : elle serait abandonnée.
  const otherEditing = resuming ? null : cart.editing

  const start = async () => {
    setError(null)
    setClosed(false)
    if (resuming) {
      navigate('/client/catalogue')
      return
    }
    setLoading(true)
    try {
      const current = await fetchOrder(queryClient, order.id)
      if (!current.can_modify) {
        if (onNotModifiable) onNotModifiable()
        else setClosed(true)
        void queryClient.invalidateQueries({ queryKey: clientKey })
        return
      }
      store.startEditing(current)
      navigate('/client/catalogue')
    } catch (fetchError) {
      setError(fetchError)
    } finally {
      setLoading(false)
      setConfirming(false)
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Button className={className} disabled={loading} onClick={() => (otherLines > 0 || otherEditing ? setConfirming(true) : void start())}>
        {loading ? t('orders.client.modify.loading') : (label ?? t('orders.client.home.modify'))}
      </Button>
      {closed && <Alert tone="warning">{t('orders.client.modify.notModifiable')}</Alert>}
      <ErrorAlert error={error} />
      {confirming && (
        <Dialog title={t('orders.client.modify.title', { number: order.number })} onClose={() => (loading ? undefined : setConfirming(false))}>
          <div className="flex flex-col gap-4 text-sm text-slate-700">
            <p>
              {otherEditing
                ? t('orders.client.modify.replaceEditing', { current: otherEditing.number, number: order.number })
                : t('orders.client.modify.replace', { count: otherLines, number: order.number })}
            </p>
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" disabled={loading} onClick={() => setConfirming(false)}>
                {t('common.cancel')}
              </Button>
              <Button data-autofocus disabled={loading} onClick={() => void start()}>
                {loading ? t('orders.client.modify.loading') : t('orders.client.modify.confirm')}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  )
}
