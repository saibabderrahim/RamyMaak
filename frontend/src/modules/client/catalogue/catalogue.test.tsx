import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ClientProduct, ClientToday } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { totals } from '../../../shared/money'
import { purgeSessionStorage } from '../../../shared/storage'
import { json } from '../../../test/utils'
import {
  clientApi,
  DAY,
  fraise,
  lben,
  makeOrder,
  makeSummary,
  makeToday,
  mockDesktop,
  ORDER_ID,
  ORDER_NUMBER,
  orange,
  plain,
  pomme,
  renderClient,
  testCart,
} from '../cart/testing'
import { CATALOGUE_VIEW_KEY } from './view'

afterEach(() => {
  purgeSessionStorage()
  vi.restoreAllMocks()
})

const catalogueApi = (today: ClientToday | ((url: URL) => ClientToday) = makeToday()) =>
  clientApi((url) => json(typeof today === 'function' ? today(url) : today))

const productList = () => screen.getByRole('list', { name: 'Produits du jour' })
const shownNames = () => within(productList()).getAllByRole('article').map((article) => article.getAttribute('aria-label'))
const card = (name: string) => screen.getByRole('article', { name })
const stepper = (scope: HTMLElement, name: string) => ({
  input: within(scope).getByRole('textbox', { name: `Quantité de ${name}` }),
  plus: within(scope).getByRole('button', { name: `Augmenter la quantité de ${name}` }),
  minus: within(scope).getByRole('button', { name: `Diminuer la quantité de ${name}` }),
})
const location = () => screen.getByTestId('location').textContent

describe('catalogue of the day (EF-CLT-02)', () => {
  it('shows the products of the day by family, with an instant search on the name, the short name and the code', async () => {
    const user = userEvent.setup()
    catalogueApi()

    renderClient('/client/catalogue')

    await screen.findByRole('list', { name: 'Produits du jour' })
    expect(shownNames()).toEqual(['Jus orange 1 L', 'Jus pomme 1 L', 'Jus fraise 1 L', 'Lben 1 L'])
    expect(screen.getByText('4 produits')).toBeInTheDocument()
    expect(screen.getByText('Commande du jeudi 08/10/2026')).toBeInTheDocument()

    const families = screen.getByRole('group', { name: 'Familles' })
    expect(within(families).getAllByRole('button').map((button) => button.textContent)).toEqual(['Tous', 'Jus', 'Laitiers'])
    expect(within(families).getByRole('button', { name: 'Tous' })).toHaveAttribute('aria-pressed', 'true')
    await user.click(within(families).getByRole('button', { name: 'Laitiers' }))
    expect(shownNames()).toEqual(['Lben 1 L'])
    expect(location()).toBe('/client/catalogue?famille=1')
    await user.click(within(families).getByRole('button', { name: 'Tous' }))
    expect(shownNames()).toHaveLength(4)

    const search = screen.getByRole('searchbox', { name: 'Rechercher un produit' })
    // Nom court (« Pomme Ramy »), sans casse.
    await user.type(search, 'ramy')
    await waitFor(() => expect(shownNames()).toEqual(['Jus pomme 1 L']))
    await waitFor(() => expect(location()).toBe('/client/catalogue?q=ramy'))
    // Code article.
    await user.clear(search)
    await user.type(search, 'p0061')
    await waitFor(() => expect(shownNames()).toEqual(['Lben 1 L']))
    // Nom, sans accents.
    await user.clear(search)
    await user.type(search, 'JUS ORANGE')
    await waitFor(() => expect(shownNames()).toEqual(['Jus orange 1 L']))
    await user.clear(search)
    await user.type(search, 'citron')
    expect(await screen.findByText('Aucun produit ne correspond à votre recherche.')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Produits du jour' })).not.toBeInTheDocument()
  })

  it('opens on the family and the search of the address', async () => {
    catalogueApi()
    renderClient('/client/catalogue?famille=2&q=jus')

    await screen.findByRole('list', { name: 'Produits du jour' })
    expect(shownNames()).toEqual(['Jus orange 1 L', 'Jus pomme 1 L', 'Jus fraise 1 L'])
    expect(screen.getByRole('button', { name: 'Jus' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('searchbox')).toHaveValue('jus')
  })

  it('shows the price, the sales unit and « Max » only when a borne exists', async () => {
    catalogueApi()
    renderClient('/client/catalogue')

    const orangeCard = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(orangeCard).toHaveTextContent('Fardeau 6 × 1 L')
    expect(orangeCard).toHaveTextContent(plain(`${formatAmount(900)} HT · ${formatAmount(1071)} TTC`))
    // Disponible sous la borne (déjà engagé) : les deux sont montrés (RG-C09).
    expect(within(orangeCard).getByText('Borne : 50 · Max : 40')).toBeInTheDocument()
    expect(within(orangeCard).getByText('Mis en avant')).toBeInTheDocument()
    // Désignation courte sur téléphone, complète à partir de la tablette ; la fiche est annoncée sous le nom complet.
    expect(within(orangeCard).getByText('Orange 1 L')).toHaveClass('sm:hidden')
    expect(within(orangeCard).getByText('Jus orange 1 L')).toHaveClass('hidden', 'sm:inline')
    expect(within(orangeCard).getByRole('button', { name: 'Voir la fiche de Jus orange 1 L' })).toBeInTheDocument()
    // Vignette décorative (le nom est à côté) : photo principale, petite variante.
    expect(orangeCard.querySelector('img')).toHaveAttribute('src', '/media/p7-200.webp')

    // Sans borne : ni borne ni disponible (RG-C09).
    expect(card('Jus pomme 1 L')).not.toHaveTextContent('Max')
    expect(within(card('Jus pomme 1 L')).getByText('Nouveau')).toBeInTheDocument()
    expect(card('Lben 1 L')).not.toHaveTextContent('Max')
  })

  it('binds the quantity to the cart, capped at the available', async () => {
    const user = userEvent.setup()
    catalogueApi()
    renderClient('/client/catalogue')

    const orangeStepper = stepper(await screen.findByRole('article', { name: 'Jus orange 1 L' }), 'Jus orange 1 L')
    await user.click(orangeStepper.plus)
    expect(testCart().get()).toMatchObject({ day: DAY, lines: [{ product_id: orange.id, quantity: 1 }] })

    await user.clear(orangeStepper.input)
    await user.type(orangeStepper.input, '99{Enter}')
    expect(orangeStepper.input).toHaveValue('40')
    expect(orangeStepper.plus).toBeDisabled()
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 40 }])

    // Sans borne : pas de maximum montré, le serveur reste juge.
    const pommeStepper = stepper(card('Jus pomme 1 L'), 'Jus pomme 1 L')
    await user.clear(pommeStepper.input)
    await user.type(pommeStepper.input, '250{Enter}')
    expect(testCart().get().lines).toEqual([
      { product_id: orange.id, quantity: 40 },
      { product_id: pomme.id, quantity: 250 },
    ])

    await user.click(orangeStepper.minus)
    expect(testCart().get().lines[0]).toEqual({ product_id: orange.id, quantity: 39 })
  })

  it('greys and disables a sold out product', async () => {
    catalogueApi()
    renderClient('/client/catalogue')

    const sold = await screen.findByRole('article', { name: 'Jus fraise 1 L' })
    expect(within(sold).getByText('Épuisé')).toBeInTheDocument()
    expect(sold).toHaveClass('opacity-60')
    const { input, plus, minus } = stepper(sold, 'Jus fraise 1 L')
    expect(input).toBeDisabled()
    expect(plus).toBeDisabled()
    expect(minus).toBeDisabled()
    expect(card('Jus orange 1 L')).not.toHaveClass('opacity-60')
  })

  it('switches the phone list to a 2-column gallery and remembers the choice on the device', async () => {
    const user = userEvent.setup()
    catalogueApi()
    const { unmount } = renderClient('/client/catalogue')

    const view = await screen.findByRole('group', { name: 'Affichage du catalogue' })
    expect(within(view).getByRole('button', { name: 'Liste' })).toHaveAttribute('aria-pressed', 'true')
    expect(productList()).not.toHaveClass('grid-cols-2')

    await user.click(within(view).getByRole('button', { name: 'Galerie' }))
    expect(within(view).getByRole('button', { name: 'Galerie' })).toHaveAttribute('aria-pressed', 'true')
    expect(productList()).toHaveClass('grid-cols-2')
    expect(JSON.parse(localStorage.getItem(CATALOGUE_VIEW_KEY) ?? 'null')).toBe('gallery')
    unmount()

    renderClient('/client/catalogue')
    expect(await screen.findByRole('button', { name: 'Galerie' })).toHaveAttribute('aria-pressed', 'true')
    expect(productList()).toHaveClass('grid-cols-2')
  })

  it('keeps the list when the storage of the device is unavailable', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    const user = userEvent.setup()
    catalogueApi()
    renderClient('/client/catalogue')

    expect(await screen.findByRole('button', { name: 'Liste' })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: 'Galerie' }))
    expect(productList()).toHaveClass('grid-cols-2')
  })

  it('opens the product sheet with ?produit=, and the back button closes it', async () => {
    const user = userEvent.setup()
    catalogueApi()
    renderClient('/client/catalogue?famille=2')

    await user.click(await screen.findByRole('button', { name: 'Voir la fiche de Jus orange 1 L' }))
    const sheet = screen.getByRole('dialog', { name: 'Jus orange 1 L' })
    expect(location()).toBe('/client/catalogue?famille=2&produit=42')

    // Carrousel des photos, grande image.
    const photos = within(sheet).getByRole('group', { name: 'Photos de Jus orange 1 L' })
    const images = within(photos).getAllByRole('img')
    expect(images.map((image) => image.getAttribute('alt'))).toEqual(['Jus orange 1 L — photo 1 sur 2', 'Jus orange 1 L — photo 2 sur 2'])
    expect(images[0]).toHaveAttribute('srcset', '/media/p7-600.webp 600w, /media/p7-1200.webp 1200w')
    expect(sheet).toHaveTextContent('Fardeau 6 × 1 L — 6 unités par unité de vente')
    expect(sheet).toHaveTextContent(plain(`Prix unitaire indicatif${formatAmount(150)} HT`))
    expect(sheet).toHaveTextContent(plain(`Prix HT${formatAmount(900)}`))
    expect(sheet).toHaveTextContent(plain(`Prix TTC${formatAmount(1071)}`))
    expect(sheet).toHaveTextContent('TVA19 %')
    expect(within(sheet).getByText('Borne : 50 · Max : 40')).toBeInTheDocument()

    await user.click(stepper(sheet, 'Jus orange 1 L').plus)
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 1 }])

    await user.click(screen.getByRole('button', { name: 'Retour du navigateur' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(location()).toBe('/client/catalogue?famille=2')

    // Fermeture par la boîte : même retour.
    await user.click(screen.getByRole('button', { name: 'Voir la fiche de Jus pomme 1 L' }))
    const pommeSheet = screen.getByRole('dialog', { name: 'Jus pomme 1 L' })
    expect(location()).toBe('/client/catalogue?famille=2&produit=57')
    await user.click(within(pommeSheet).getByRole('button', { name: 'Fermer' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(location()).toBe('/client/catalogue?famille=2')
  })

  it('opens the sheet from a link, and closes it without leaving the catalogue', async () => {
    const user = userEvent.setup()
    catalogueApi()
    renderClient('/client/catalogue?produit=61')

    const sheet = await screen.findByRole('dialog', { name: 'Lben 1 L' })
    // Sans photo : visuel par défaut ; sans borne : aucun maximum.
    expect(within(sheet).getByRole('img', { name: 'Lben 1 L' })).toBeInTheDocument()
    expect(sheet).toHaveTextContent('Caisse 12 × 1 L — 12 unités par unité de vente')
    expect(sheet).toHaveTextContent('TVA9 %')
    expect(sheet).not.toHaveTextContent('Max')
    await user.click(within(sheet).getByRole('button', { name: 'Continuer mes achats' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(location()).toBe('/client/catalogue')
  })

  it('ignores a product that is not in the catalogue of the day', async () => {
    catalogueApi()
    renderClient('/client/catalogue?produit=999')
    await screen.findByRole('list', { name: 'Produits du jour' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('sums the cart at the prices of the day in the bar fixed at the bottom', async () => {
    const user = userEvent.setup()
    testCart().setQuantity(DAY, orange.id, 3)
    testCart().setQuantity(DAY, lben.id, 7)
    catalogueApi()
    renderClient('/client/catalogue')

    const bar = await screen.findByRole('link', { name: /Voir le panier/ })
    const expected = totals([
      { unit_price_ht: 900, vat_rate: 19, quantity: 3 },
      { unit_price_ht: 130.5, vat_rate: 9, quantity: 7 },
    ])
    expect(expected.ht).toBe(3613.5)
    expect(bar).toHaveTextContent(plain(`2 produits${formatAmount(expected.ht)} HT`))

    await user.click(stepper(card('Jus pomme 1 L'), 'Jus pomme 1 L').plus)
    expect(bar).toHaveTextContent(plain(`3 produits${formatAmount(expected.ht + 900)} HT`))

    await user.click(bar)
    expect(location()).toBe('/client/panier')
  })

  it('has no cart bar while the cart is empty', async () => {
    catalogueApi()
    renderClient('/client/catalogue')
    await screen.findByRole('list', { name: 'Produits du jour' })
    expect(screen.queryByRole('link', { name: /Voir le panier/ })).not.toBeInTheDocument()
  })

  it('shows the cart in a side panel on a PC', async () => {
    mockDesktop(true)
    testCart().setQuantity(DAY, orange.id, 3)
    catalogueApi()
    renderClient('/client/catalogue')

    const panel = await screen.findByRole('complementary', { name: 'Mon panier' })
    expect(panel).toHaveTextContent(plain(`3 × Jus orange 1 L${formatAmount(2700)}`))
    expect(within(panel).getByText('Total TTC').nextSibling).toHaveTextContent(plain(formatAmount(3213)))
    expect(within(panel).getByRole('link', { name: 'Voir le panier et envoyer' })).toHaveAttribute('href', '/client/panier')
    // Pas de barre en bas de l'écran ni de bascule liste / galerie sur PC.
    expect(screen.queryByRole('link', { name: /^3 produits/ })).not.toBeInTheDocument()
  })

  it.each<[string, Partial<ClientToday>, string]>([
    ['for a blocked client', { client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'BLOCKED' }, can_submit: false, submit_blocker: 'CLIENT_BLOCKED' }, 'Commande suspendue.'],
    ['after the cutoff', { status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED' }, 'L’heure limite de commande est passée.'],
    ['once the order of the day is sent', { can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary({ can_modify: false })] }, 'Vous avez déjà envoyé votre commande pour cette journée.'],
  ])('is read-only %s', async (_, overrides, message) => {
    catalogueApi(makeToday(overrides))
    renderClient('/client/catalogue')

    const orangeCard = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(screen.getByText(message)).toBeInTheDocument()
    expect(screen.getByText('Catalogue en lecture seule.')).toBeInTheDocument()
    expect(stepper(orangeCard, 'Jus orange 1 L').plus).toBeDisabled()
    expect(stepper(orangeCard, 'Jus orange 1 L').input).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Modifier ma commande' })).not.toBeInTheDocument()
  })

  it('offers to modify the order of the day once the maximum is reached', async () => {
    catalogueApi(makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary()] }))
    renderClient('/client/catalogue')
    expect(await screen.findByRole('button', { name: 'Modifier ma commande' })).toBeInTheDocument()
  })

  it('shows why the catalogue is empty', async () => {
    for (const [overrides, message] of [
      [{ status: 'WAITING_QUOTAS', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [], families: [] }, 'Le catalogue du jour s’affichera dès que vos quantités seront disponibles.'],
      [{ status: 'DAY_CLOSED', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [], families: [] }, 'Cette journée est clôturée.'],
      [{ products: [], families: [] }, 'Aucun produit n’est disponible pour vous ce jour-là.'],
    ] satisfies [Partial<ClientToday>, string][]) {
      catalogueApi(makeToday(overrides))
      const view = renderClient('/client/catalogue')
      expect(await screen.findByText(message)).toBeInTheDocument()
      expect(screen.queryByRole('searchbox')).not.toBeInTheDocument()
      view.unmount()
    }
  })

  it('modifies a submitted order: the day of the order without its quantities, editable although the maximum is reached', async () => {
    const user = userEvent.setup()
    testCart().startEditing(makeOrder({ version: 2 }))
    // Disponible hors de la commande modifiée (I-62) : 50 = borne, ses 45 ne comptent pas.
    const editingProducts: ClientProduct[] = [{ ...orange, engaged: 0, available: 50 }, pomme, lben]
    const { todayQueries } = catalogueApi((url) =>
      url.searchParams.has('order')
        ? makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary({ version: 2 })], products: editingProducts })
        : makeToday(),
    )
    renderClient('/client/catalogue')

    const orangeCard = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(todayQueries()[0]).toEqual({ day: DAY, order: ORDER_ID })
    expect(screen.getByText(`Modification de la commande ${ORDER_NUMBER}`)).toBeInTheDocument()
    expect(screen.queryByText('Catalogue en lecture seule.')).not.toBeInTheDocument()
    // Ses propres quantités ne la plafonnent pas.
    const { input, plus } = stepper(orangeCard, 'Jus orange 1 L')
    expect(input).toHaveValue('45')
    expect(within(orangeCard).getByText('Max : 50')).toBeInTheDocument()
    await user.click(plus)
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 46 }])
    expect(testCart().get().editing).toMatchObject({ orderId: ORDER_ID, number: ORDER_NUMBER, version: 2 })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Abandonner la modification' }))
    await waitFor(() => expect(todayQueries().at(-1)).toEqual({}))
    expect(testCart().get().lines).toEqual([])
    expect(await screen.findByRole('article', { name: 'Jus fraise 1 L' })).toBeInTheDocument()
  })

  it('is read-only once the order being modified can no longer be modified', async () => {
    testCart().startEditing(makeOrder())
    catalogueApi(makeToday({ status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED', orders: [makeSummary({ can_modify: false, can_cancel: false })] }))
    renderClient('/client/catalogue')

    const orangeCard = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(screen.getByText('Cette commande ne peut plus être modifiée.')).toBeInTheDocument()
    expect(stepper(orangeCard, 'Jus orange 1 L').plus).toBeDisabled()
    // Rien n'est rapproché : la commande reste telle quelle jusqu'à l'abandon.
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 45 }])
  })

  it('removes from the cart what can no longer be ordered and caps the quantities, with a notice', async () => {
    testCart().setQuantity(DAY, fraise.id, 2)
    testCart().setQuantity(DAY, orange.id, 45)
    testCart().setQuantity(DAY, 99, 4)
    testCart().setQuantity(DAY, pomme.id, 600)
    testCart().setComment('Livrer avant 10 h')
    catalogueApi()
    renderClient('/client/catalogue')

    const notice = await screen.findByText('Votre panier a été mis à jour')
    const items = within(notice.parentElement as HTMLElement).getAllByRole('listitem').map((item) => item.textContent)
    expect(items).toEqual(['« Jus fraise 1 L » retiré : il n’est plus disponible.', '« Jus orange 1 L » : quantité ramenée de 45 à 40.', 'Un produit retiré : il n’est plus disponible.'])
    expect(testCart().get()).toMatchObject({
      lines: [
        { product_id: orange.id, quantity: 40 },
        { product_id: pomme.id, quantity: 600 },
      ],
      comment: 'Livrer avant 10 h',
    })

    await userEvent.setup().click(screen.getByRole('button', { name: 'Fermer l’avis' }))
    expect(screen.queryByText('Votre panier a été mis à jour')).not.toBeInTheDocument()
  })

  it('reconciles again when the day is read again, without touching what is being typed', async () => {
    const user = userEvent.setup()
    let available = 40
    catalogueApi(() => makeToday({ products: [{ ...orange, available }, pomme, fraise, lben] }))
    const { queryClient } = renderClient('/client/catalogue')

    const orangeStepper = stepper(await screen.findByRole('article', { name: 'Jus orange 1 L' }), 'Jus orange 1 L')
    await user.clear(orangeStepper.input)
    await user.type(orangeStepper.input, '30{Enter}')
    // Saisie en cours sur un autre produit pendant la relecture.
    const pommeInput = stepper(card('Jus pomme 1 L'), 'Jus pomme 1 L').input
    await user.clear(pommeInput)
    await user.type(pommeInput, '12')

    available = 25
    await act(() => queryClient.invalidateQueries({ queryKey: ['client'] }))

    expect(await screen.findByText('« Jus orange 1 L » : quantité ramenée de 30 à 25.')).toBeInTheDocument()
    expect(orangeStepper.input).toHaveValue('25')
    expect(pommeInput).toHaveValue('12')
    await user.tab()
    expect(testCart().get().lines).toEqual([
      { product_id: orange.id, quantity: 25 },
      { product_id: pomme.id, quantity: 12 },
    ])
  })
})
