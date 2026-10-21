import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import type { ReasonOption } from '../api/types'
import { createQueryClient } from '../api/queryClient'
import { Field } from '../ui'
import { json, mockApi, problem } from '../../test/utils'
import { findReason, reasonKey, ReasonSelect, reasonText, requiresComment, useReasonCodes } from '.'

const REASONS_URL = 'GET /api/v1/reason-codes'

const reason = (overrides: Partial<ReasonOption> & Pick<ReasonOption, 'code' | 'label'>): ReasonOption => ({
  type: 'ADJUSTMENT',
  requires_comment: false,
  sort_order: 0,
  ...overrides,
})

const ADJUSTMENTS = [
  reason({ code: 'QUOTA_INSUFFISANT', label: 'Quota insuffisant', sort_order: 1 }),
  reason({ code: 'BORNE_ATTEINTE', label: 'Borne du client atteinte', sort_order: 2 }),
  reason({ code: 'AUTRE', label: 'Autre', requires_comment: true, sort_order: 9 }),
]
const REJECTIONS = [reason({ type: 'REJECTION', code: 'QUOTA_EPUISE', label: 'Quota épuisé' })]

function renderWithClient(children: ReactNode) {
  const queryClient = createQueryClient()
  return render(<QueryClientProvider client={queryClient}>{children}</QueryClientProvider>)
}

function Codes({ types }: { types: Parameters<typeof useReasonCodes>[0] }) {
  const reasons = useReasonCodes(types)
  return <p>{reasons.data ? reasons.data.map((item) => item.code).join(' ') : 'chargement'}</p>
}

// Contrat du lot 6, §4 : motifs actifs par type, lus par le régional et l'ADV.
describe('shared/reasons', () => {
  it('reads the active reasons of several types once, under one sorted key', async () => {
    const { calls, fetchMock } = mockApi({ [REASONS_URL]: () => json({ data: [...ADJUSTMENTS, ...REJECTIONS] }) })

    renderWithClient(
      <>
        <Codes types={['REJECTION', 'ADJUSTMENT']} />
        <Codes types={['ADJUSTMENT', 'REJECTION', 'ADJUSTMENT']} />
      </>,
    )

    expect(await screen.findAllByText('QUOTA_INSUFFISANT BORNE_ATTEINTE AUTRE QUOTA_EPUISE')).toHaveLength(2)
    expect(calls.filter((call) => call.path === '/api/v1/reason-codes')).toHaveLength(1)
    const url = new URL(String(fetchMock.mock.calls[0]?.[0]), 'http://localhost')
    expect(url.searchParams.get('type')).toBe('ADJUSTMENT,REJECTION')
    expect(reasonKey(['REJECTION', 'ADJUSTMENT'])).toEqual(['reasons', 'ADJUSTMENT,REJECTION'])
  })

  it('lists the reasons of its type in a select, with an empty choice, inside a Field', async () => {
    mockApi({ [REASONS_URL]: ({ url }) => json({ data: url.searchParams.get('type') === 'ADJUSTMENT' ? ADJUSTMENTS : REJECTIONS }) })

    renderWithClient(<Field label="Motif d’ajustement">{(props) => <ReasonSelect type="ADJUSTMENT" {...props} defaultValue="" />}</Field>)

    const select = screen.getByRole('combobox', { name: 'Motif d’ajustement' })
    // Pendant le chargement : désactivée.
    expect(select).toBeDisabled()
    await waitFor(() => expect(select).toBeEnabled())
    expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'Choisir un motif',
      'Quota insuffisant',
      'Borne du client atteinte',
      'Autre',
    ])
    expect(within(select).getByRole('option', { name: 'Quota insuffisant' })).toHaveValue('QUOTA_INSUFFISANT')
  })

  it('keeps a recorded reason that has been deactivated since', async () => {
    mockApi({ [REASONS_URL]: () => json({ data: ADJUSTMENTS }) })

    renderWithClient(<ReasonSelect type="ADJUSTMENT" aria-label="Motif" current={{ code: 'DEMANDE_CLIENT', label: 'À la demande du client' }} defaultValue="DEMANDE_CLIENT" />)

    const select = screen.getByRole('combobox', { name: 'Motif' })
    await waitFor(() => expect(select).toBeEnabled())
    expect(within(select).getByRole('option', { name: 'À la demande du client (désactivé)' })).toHaveValue('DEMANDE_CLIENT')
    expect(select).toHaveValue('DEMANDE_CLIENT')
  })

  it('says when the reasons cannot be read', async () => {
    mockApi({ [REASONS_URL]: () => problem(403, 'FORBIDDEN', 'Accès refusé.') })

    renderWithClient(<ReasonSelect type="CANCELLATION" aria-label="Motif d’annulation" />)

    expect(await screen.findByRole('option', { name: 'Motifs indisponibles' })).toBeInTheDocument()
  })

  it('finds a reason, its comment rule and its text', () => {
    const reasons = [...ADJUSTMENTS, ...REJECTIONS]
    expect(findReason(reasons, 'ADJUSTMENT', 'AUTRE')?.label).toBe('Autre')
    expect(findReason(reasons, 'REJECTION', 'AUTRE')).toBeUndefined()
    expect(requiresComment(reasons, 'ADJUSTMENT', 'AUTRE')).toBe(true)
    expect(requiresComment(reasons, 'ADJUSTMENT', 'QUOTA_INSUFFISANT')).toBe(false)
    expect(requiresComment(undefined, 'ADJUSTMENT', null)).toBe(false)

    expect(reasonText({ code: 'QUOTA_EPUISE', label: 'Quota épuisé' })).toBe('Quota épuisé')
    expect(reasonText(null, 'RUPTURE_STOCK')).toBe('Rupture stock')
    expect(reasonText(undefined, null)).toBeNull()
  })
})
