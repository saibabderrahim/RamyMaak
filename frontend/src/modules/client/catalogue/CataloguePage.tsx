import { type ReactNode, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate, useSearchParams } from 'react-router'
import type { ClientToday } from '../../../shared/api/types'
import { OfflineDataNotice } from '../../../shared/offline/OfflineDataNotice'
import { Alert, Button, ErrorAlert, PageHeader } from '../../../shared/ui'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { CartNoticeAlert, CartPanel, DaySummary, EditingBanner, TodayLoading } from '../cart/components'
import { ModifyOrderButton } from '../cart/ModifyOrderButton'
import { cartItems, cartTotals } from '../cart/reconcile'
import type { Cart, CartStore } from '../cart/store'
import { useCartToday } from '../cart/sync'
import { CartBar, FamilyTabs, SearchBox, ViewToggle } from './controls'
import { ProductItem } from './ProductItem'
import { ProductSheet } from './ProductSheet'
import { type CatalogueView, filterProducts, useCatalogueView, useDesktop, withParam } from './view'

// Liste compacte sur téléphone, galerie à partir de la tablette : 3 colonnes, 4 sur PC à côté du panier
// latéral (§7.3 : 4 à 6) ; la page étant limitée à 1 152 px, 5 colonnes n'y laisseraient plus la place du sélecteur.
// Charte Ramy (lot 7) : la liste du téléphone est un seul bloc à filets, une carte par produit à partir de la tablette.
const GRID: Record<CatalogueView, string> = {
  list:
    'flex flex-col divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white px-3 ' +
    'sm:grid sm:grid-cols-3 sm:gap-3 sm:divide-y-0 sm:rounded-none sm:border-0 sm:bg-transparent sm:px-0 lg:grid-cols-4',
  gallery: 'grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 lg:grid-cols-4',
}

/**
 * Catalogue du jour (EF-CLT-02, §7.3 ; contrat du lot 5 §6) : produits
 * commandables de la région avec la borne et le disponible du client
 * (RG-C09), familles en onglets (`?famille=`), recherche instantanée
 * (`?q=`), fiche produit (`?produit=ID`), quantités liées au panier.
 *
 * Journée lue : celle de la commande en cours de modification, avec
 * `?order=` (disponible hors de ses quantités, I-62), sinon la journée
 * proposée (I-42). Lecture seule pour un client bloqué ou une commande
 * impossible, sauf une commande envoyée encore modifiable.
 */
export default function CataloguePage() {
  const { t } = useTranslation()
  const { cart, store, query, notice, dismissNotice } = useCartToday()
  const today = query.data

  return (
    <>
      <PageHeader title={t('nav.order')} />
      {today && <OfflineDataNotice updatedAt={query.dataUpdatedAt} hint={t('offline.cart.offlineNew')} className="mb-4" />}
      {query.error && !today ? (
        <div className="flex flex-col gap-3">
          {cart.editing && <EditingBanner number={cart.editing.number} modifiable={false} onAbandon={() => store.clear()} />}
          <ErrorAlert error={query.error} />
          <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      ) : !today ? (
        <TodayLoading paused={query.fetchStatus === 'paused'} />
      ) : (
        <Catalogue today={today} cart={cart} store={store} notice={notice && <CartNoticeAlert notice={notice} onDismiss={dismissNotice} />} />
      )}
    </>
  )
}

function Catalogue({ today, cart, store, notice }: { today: ClientToday; cart: Cart; store: CartStore; notice: ReactNode }) {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  const desktop = useDesktop()
  const [view, setView] = useCatalogueView()

  // Recherche au fil de la frappe ; l'adresse (`?q=`) suit, sans entrée d'historique.
  const [search, setSearch] = useState(() => params.get('q') ?? '')
  const debounced = useDebouncedValue(search, 200)
  const latest = useRef({ params, setParams })
  useEffect(() => {
    latest.current = { params, setParams }
  })
  useEffect(() => {
    const { params: current, setParams: update } = latest.current
    if ((current.get('q') ?? '') !== debounced) update((previous) => withParam(previous, 'q', debounced), { replace: true })
  }, [debounced])

  const familyParam = params.get('famille')
  const familyId = today.families.find((family) => String(family.id) === familyParam)?.id ?? null
  const selectFamily = (id: number | null) => setParams((previous) => withParam(previous, 'famille', id === null ? null : String(id)), { replace: true })

  // Fiche produit : `?produit=ID` ajouté à l'historique, le bouton Retour la referme.
  const sheetParam = params.get('produit')
  const sheetProduct = today.products.find((product) => String(product.id) === sheetParam)
  const openSheet = (id: number) => setParams((previous) => withParam(previous, 'produit', String(id)), { state: { sheet: true } })
  const closeSheet = () => {
    if ((location.state as { sheet?: boolean } | null)?.sheet) navigate(-1)
    else setParams((previous) => withParam(previous, 'produit', null), { replace: true })
  }

  const editing = cart.editing
  const editingOrder = editing ? today.orders.find((order) => order.id === editing.orderId) : undefined
  // Modification : permise tant que la commande l'est (même maximum atteint) ; sinon, l'envoi doit être possible.
  const readOnly = editing ? !editingOrder?.can_modify : !today.can_submit
  const modifiable = !editing && today.submit_blocker === 'MAX_ORDERS_REACHED' ? today.orders.find((order) => order.can_modify) : undefined

  const quantities = new Map(cart.lines.map((line) => [line.product_id, line.quantity]))
  const setQuantity = (productId: number, quantity: number) => store.setQuantity(today.day, productId, quantity)
  const items = cartItems(cart.lines, today.products, cart.editing?.prices)
  const visible = filterProducts(today.products, familyId, debounced)

  return (
    <div className={desktop ? 'grid grid-cols-[minmax(0,1fr)_18rem] items-start gap-6' : items.length > 0 ? 'pb-20' : ''}>
      <div className="flex min-w-0 flex-col gap-4">
        {editing && <EditingBanner number={editing.number} modifiable={!readOnly} onAbandon={() => store.clear()} />}
        <DaySummary today={today} />
        {!editing && today.submit_blocker !== null && (
          <Alert tone="warning">
            <p className="font-semibold">{t(`orders.client.blocker.${today.submit_blocker}`, { count: today.max_orders_per_day })}</p>
            <p>{t('orders.client.catalogue.readOnly')}</p>
            {modifiable && (
              <div className="mt-3">
                <ModifyOrderButton order={modifiable} />
              </div>
            )}
          </Alert>
        )}
        {notice}

        {today.products.length === 0 ? (
          <Alert>
            {today.status === 'WAITING_QUOTAS'
              ? t('orders.client.catalogue.waiting')
              : today.status === 'DAY_CLOSED'
                ? t('orders.client.catalogue.closed')
                : t('orders.client.catalogue.empty')}
          </Alert>
        ) : (
          <>
            <SearchBox value={search} onChange={setSearch} />
            <FamilyTabs families={today.families} selected={familyId} onSelect={selectFamily} />
            <div className="flex min-h-10 items-center justify-between gap-3">
              <p className="text-sm text-slate-600" aria-live="polite">
                {visible.length === 0 ? t('orders.client.catalogue.noMatch') : t('orders.client.catalogue.results', { count: visible.length })}
              </p>
              <ViewToggle view={view} onChange={setView} />
            </div>
            {visible.length > 0 && (
              <ul aria-label={t('orders.client.catalogue.products')} className={GRID[view]}>
                {visible.map((product) => (
                  <li key={product.id}>
                    <ProductItem
                      product={product}
                      quantity={quantities.get(product.id) ?? 0}
                      onQuantity={(quantity) => setQuantity(product.id, quantity)}
                      readOnly={readOnly}
                      view={view}
                      onOpen={() => openSheet(product.id)}
                    />
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>

      {desktop ? <CartPanel items={items} /> : items.length > 0 && <CartBar lines={items.length} totalHt={cartTotals(items).ht} />}

      {sheetProduct && (
        <ProductSheet
          product={sheetProduct}
          quantity={quantities.get(sheetProduct.id) ?? 0}
          onQuantity={(quantity) => setQuantity(sheetProduct.id, quantity)}
          readOnly={readOnly}
          onClose={closeSheet}
        />
      )}
    </div>
  )
}
