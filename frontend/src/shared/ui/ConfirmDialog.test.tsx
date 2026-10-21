import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import '../i18n'
import { ApiError } from '../api/client'
import { ConfirmDialog } from './ConfirmDialog'

describe('shared/ui/ConfirmDialog', () => {
  it('renders nothing while closed', () => {
    render(
      <ConfirmDialog open={false} title="Annuler la commande" confirmLabel="Confirmer" onConfirm={vi.fn()} onCancel={vi.fn()}>
        <p>Texte</p>
      </ConfirmDialog>,
    )
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('focuses « Annuler », confirms on request, and shows the pending state and the server refusal', async () => {
    const onConfirm = vi.fn()
    const onCancel = vi.fn()
    const user = userEvent.setup()
    const error = new ApiError({ status: 409, code: 'VERSION_CONFLICT', detail: 'La commande a changé.' })
    const { rerender } = render(
      <ConfirmDialog open title="Annuler la commande" confirmLabel="Annuler la commande" tone="danger" onConfirm={onConfirm} onCancel={onCancel}>
        <p>Les quantités validées seront restituées.</p>
      </ConfirmDialog>,
    )

    const dialog = screen.getByRole('dialog', { name: 'Annuler la commande' })
    expect(dialog).toHaveTextContent('Les quantités validées seront restituées.')
    expect(screen.getByRole('button', { name: 'Annuler' })).toHaveFocus()
    await user.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    expect(onConfirm).toHaveBeenCalledOnce()
    await user.keyboard('{Escape}')
    expect(onCancel).toHaveBeenCalledOnce()

    rerender(
      <ConfirmDialog open title="Annuler la commande" confirmLabel="Annuler la commande" pending error={error} onConfirm={onConfirm} onCancel={onCancel}>
        <p>Les quantités validées seront restituées.</p>
      </ConfirmDialog>,
    )
    expect(screen.getByRole('button', { name: 'Enregistrement…' })).toBeDisabled()
    expect(screen.getByRole('alert')).toHaveTextContent('La commande a changé.')
  })
})
