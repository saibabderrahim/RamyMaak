import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { installPush, PUBLIC_KEY, uninstallPush } from '../../../shared/push/testing'
import { regional, renderMyDay } from './testing'

afterEach(() => uninstallPush())

describe('my day — push invitation (lot 8, contract §8)', () => {
  it('invites the regional to receive the notifications, and enables them', async () => {
    installPush()
    const { requests } = renderMyDay({
      user: regional({ push: { public_key: PUBLIC_KEY } }),
      routes: { 'POST /api/v1/push-subscriptions': () => new Response(JSON.stringify({ endpoint: 'x', created_at: '2026-10-08T09:00:00+00:00' }), { status: 201 }) },
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Activer' }))

    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Recevez vos notifications' })).not.toBeInTheDocument())
    expect(requests('POST /api/v1/push-subscriptions')).toHaveLength(1)
  })

  it('does not invite once the permission was asked', async () => {
    installPush({ permission: 'denied' })
    renderMyDay({ user: regional({ push: { public_key: PUBLIC_KEY } }) })

    expect(await screen.findByText('Ma journée')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Recevez vos notifications' })).not.toBeInTheDocument()
  })
})
