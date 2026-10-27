import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { inviteKey } from '../../../shared/push'
import { installPush, PUBLIC_KEY, uninstallPush } from '../../../shared/push/testing'
import { purgeSessionStorage } from '../../../shared/storage'
import { json } from '../../../test/utils'
import { clientApi, clientUser, makeToday, renderClient, USER_ID } from '../cart/testing'

afterEach(() => {
  uninstallPush()
  purgeSessionStorage()
})

describe('client home — push invitation (lot 8, contract §8)', () => {
  it('invites the client to receive the notifications, until « Plus tard »', async () => {
    installPush()
    const user = clientUser({ push: { public_key: PUBLIC_KEY } })
    clientApi(() => json(makeToday()), {}, user)
    const events = userEvent.setup()

    renderClient('/client', user)

    expect(await screen.findByRole('heading', { name: 'Recevez vos notifications' })).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Commande du jeudi 08/10/2026' })).toBeInTheDocument()

    await events.click(screen.getByRole('button', { name: 'Plus tard' }))

    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Recevez vos notifications' })).not.toBeInTheDocument())
    expect(localStorage.getItem(inviteKey(USER_ID))).not.toBeNull()
  })

  it('does not invite without push on the server', async () => {
    installPush()
    clientApi(() => json(makeToday()))

    renderClient('/client')

    expect(await screen.findByRole('heading', { name: 'Commande du jeudi 08/10/2026' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Recevez vos notifications' })).not.toBeInTheDocument()
  })
})
