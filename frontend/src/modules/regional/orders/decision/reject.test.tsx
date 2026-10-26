import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { RegionalOrder } from '../../../../shared/api/types'
import { json, problem } from '../../../../test/utils'
import { decidable, makeOrder, ORDER_ID, orderUrl, rejectUrl, renderOrders, validateUrl } from '../testing'
import { draftStore } from './drafts'

afterEach(() => {
  vi.restoreAllMocks()
  // Le gestionnaire de connexion de TanStack Query garde l'état « hors ligne » d'un test à l'autre.
  window.dispatchEvent(new Event('online'))
})

const DETAIL = `/regional/commandes/${ORDER_ID}`
const REJECT = rejectUrl(ORDER_ID)
const TITLE = 'Rejeter la commande RMK-261008-00042'

const rejectedOrder = (extra: Partial<RegionalOrder> = {}): RegionalOrder =>
  decidable({
    status: 'REJECTED',
    status_label: 'Rejetée',
    version: 3,
    decided_at: '2026-10-08T10:15:00+00:00',
    decided_by: { id: 7, name: 'Karim Régional' },
    decision_reason_code: 'QUOTA_EPUISE',
    decision_reason: { code: 'QUOTA_EPUISE', label: 'Quota épuisé' },
    can_validate: false,
    can_reject: false,
    ...extra,
  })

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
  await user.click(await screen.findByRole('button', { name: 'Rejeter' }))
  return screen.findByRole('dialog', { name: TITLE })
}

async function chooseReason(user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement, label: string) {
  const select = within(dialog).getByRole('combobox', { name: 'Motif du rejet' })
  await waitFor(() => expect(select).toBeEnabled())
  await user.selectOptions(select, label)
}

describe('regional order — rejection', () => {
  it('asks for a reason of rejection, then sends the rejection with the version read', async () => {
    const user = userEvent.setup()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(decidable()), [REJECT]: () => json(rejectedOrder()) },
    })
    const dialog = await openDialog(user)

    // Motifs de rejet seulement, dans l'ordre du serveur ; rien n'est envoyé sans motif.
    await waitFor(() => expect(within(dialog).getByRole('combobox', { name: 'Motif du rejet' })).toBeEnabled())
    expect(within(within(dialog).getByRole('combobox', { name: 'Motif du rejet' })).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'Choisir un motif',
      'Quota épuisé',
      'Doublon',
      'Autre',
    ])
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    expect(await within(dialog).findByText('Choisissez un motif de rejet.')).toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

    await chooseReason(user, dialog, 'Quota épuisé')
    await user.type(within(dialog).getByRole('textbox', { name: 'Commentaire' }), 'Plus de stock')
    expect(within(dialog).getByText('Visible par le client · 13 / 500 caractères')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await screen.findByText('Commande RMK-261008-00042 rejetée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.path).toBe(`/api/v1/regional/orders/${ORDER_ID}/reject`)
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ version: 2, reason_code: 'QUOTA_EPUISE', comment: 'Plus de stock' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(await screen.findByText('Rejetée le 08/10/2026 11:15 par Karim Régional')).toBeInTheDocument()
    expect(screen.getByText('Motif du rejet : Quota épuisé')).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
  })

  it('sends no comment when it is blank, and requires one with a reason which asks for it (AUTRE)', async () => {
    const user = userEvent.setup()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(decidable()), [REJECT]: () => json(rejectedOrder({ decision_reason_code: 'AUTRE', decision_reason: { code: 'AUTRE', label: 'Autre' } })) },
    })
    const dialog = await openDialog(user)

    await chooseReason(user, dialog, 'Autre')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    expect(await within(dialog).findByText('Un commentaire est obligatoire avec ce motif.')).toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

    await user.type(within(dialog).getByRole('textbox', { name: 'Commentaire' }), '   ')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    expect(await within(dialog).findByText('Un commentaire est obligatoire avec ce motif.')).toBeInTheDocument()

    await chooseReason(user, dialog, 'Doublon')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    await screen.findByText('Commande RMK-261008-00042 rejetée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ version: 2, reason_code: 'DOUBLON', comment: null })
  })

  it('closes with Cancel or Escape, and keeps the draft of the validation', async () => {
    const user = userEvent.setup()
    const { queryClient } = renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    await user.click(await screen.findByRole('button', { name: 'Appliquer 720 à « Lben 1 L »' }))

    await user.click(screen.getByRole('button', { name: 'Rejeter' }))
    await user.click(within(await screen.findByRole('dialog', { name: TITLE })).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Rejeter' }))
    await screen.findByRole('dialog', { name: TITLE })
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Quantité de Lben 1 L' })).toHaveValue('720')
    expect(draftStore(queryClient).get(ORDER_ID)?.lines[57]?.quantity).toBe(720)
  })

  it('shows the refusal of the server in the dialog and keeps the dialog open', async () => {
    const user = userEvent.setup()
    let attempts = 0
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(decidable()),
        [REJECT]: () => {
          attempts += 1
          return attempts === 1 ? problem(422, 'VALIDATION_FAILED', 'Le motif de rejet n’est plus disponible.') : json(rejectedOrder())
        },
      },
    })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Quota épuisé')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await within(dialog).findByText('Le motif de rejet n’est plus disponible.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: TITLE })).toBeInTheDocument()
    // Saisie conservée : on peut renvoyer.
    expect(within(dialog).getByRole('combobox', { name: 'Motif du rejet' })).toHaveValue('QUOTA_EPUISE')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    expect(await screen.findByText('Commande RMK-261008-00042 rejetée.')).toBeInTheDocument()
  })

  it('says there is no network and keeps the dialog', async () => {
    const user = userEvent.setup()
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(decidable()),
        [REJECT]: () => {
          throw new TypeError('Failed to fetch')
        },
      },
    })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Doublon')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await within(dialog).findByText('Pas de réseau : la décision n’a pas été enregistrée. Votre saisie est conservée, réessayez.')).toBeInTheDocument()
  })

  it('disables the rejection offline', async () => {
    const user = userEvent.setup()
    const online = vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(true)
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Doublon')
    expect(within(dialog).getByRole('button', { name: 'Rejeter la commande' })).toBeEnabled()

    online.mockReturnValue(false)
    act(() => window.dispatchEvent(new Event('offline')))
    expect(within(dialog).getByRole('button', { name: 'Rejeter la commande' })).toBeDisabled()
    expect(within(dialog).getByText('Hors ligne : la décision est impossible pour le moment.')).toBeInTheDocument()
  })

  it('closes the dialog on a version conflict: the order modified by the client is announced instead', async () => {
    const user = userEvent.setup()
    let version = 2
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(decidable({ version })),
        [REJECT]: () => {
          version = 3
          return problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée.', { version: 3 })
        },
      },
    })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Quota épuisé')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await screen.findByText('Commande modifiée par le client')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('does not reopen the dialog when the regional validates after the refused rejection', async () => {
    const user = userEvent.setup()
    let version = 2
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(decidable({ version })),
        [REJECT]: () => {
          version = 3
          return problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée.', { version: 3 })
        },
        [validateUrl(ORDER_ID)]: () => problem(503, 'SERVICE_UNAVAILABLE', 'Service indisponible.'),
      },
    })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Quota épuisé')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    await user.click(await screen.findByRole('button', { name: 'Reprendre avec la commande à jour' }))
    await user.click(await screen.findByRole('button', { name: 'Valider' }))

    expect(await screen.findByText('Service indisponible.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('says the order was already processed and closes the dialog', async () => {
    const user = userEvent.setup()
    let processed = false
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(processed ? rejectedOrder({ decided_by: { id: 9, name: 'Suppléant' } }) : decidable()),
        [REJECT]: () => {
          processed = true
          return problem(409, 'INVALID_TRANSITION', 'Commande « Rejetée » : impossible de passer à « Rejetée ».')
        },
      },
    })
    const dialog = await openDialog(user)
    await chooseReason(user, dialog, 'Quota épuisé')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await screen.findByText('Cette commande a déjà été traitée, par un autre compte ou depuis un autre appareil : elle a été rechargée.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(await screen.findByText('Rejetée le 08/10/2026 11:15 par Suppléant')).toBeInTheDocument()
  })

  it('is never blocked by the validation cutoff nor by a blocked client (I-71)', async () => {
    const user = userEvent.setup()
    const blocked = decidable({ can_validate: false, blocked_reason: 'CLIENT_BLOCKED', client: { ...makeOrder().client, status: 'BLOCKED' } })
    const { calls } = renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(blocked), [REJECT]: () => json(rejectedOrder({ decision_reason_code: 'CLIENT_BLOQUE' })) } })
    const dialog = await openDialog(user)

    await chooseReason(user, dialog, 'Doublon')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))
    await screen.findByText('Commande RMK-261008-00042 rejetée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ version: 2, reason_code: 'DOUBLON', comment: null })
  })

  it('does not offer to reject an order the regional has no right to reject', async () => {
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable({ can_validate: true, can_reject: false })) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    expect(await screen.findByRole('button', { name: 'Valider' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rejeter' })).not.toBeInTheDocument()
  })
})
