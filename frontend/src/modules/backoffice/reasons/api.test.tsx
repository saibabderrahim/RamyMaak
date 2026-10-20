import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createQueryClient } from '../../../shared/api/queryClient'
import type { ReasonCode } from '../../../shared/api/types'
import { reasonKey } from '../../../shared/reasons'
import { json, mockApi } from '../../../test/utils'
import { adminReasonsOfType, useAdminReasons, useCreateReason, useUpdateReason } from './api'

afterEach(() => {
  vi.restoreAllMocks()
})

const saved: ReasonCode = {
  id: 9,
  type: 'ADJUSTMENT',
  code: 'DEMANDE_CLIENT',
  label: 'À la demande du client',
  requires_comment: false,
  sort_order: 30,
  is_active: true,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
}

function setup() {
  const queryClient = createQueryClient()
  // Listes de choix des écrans terrain (régional, ADV) déjà en cache, et liste de l'Admin.
  queryClient.setQueryData(reasonKey(['ADJUSTMENT']), { data: [] })
  queryClient.setQueryData(adminReasonsOfType('ADJUSTMENT'), { data: [] })
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  return { queryClient, wrapper }
}

describe('reason codes — data (EF-ADM-05)', () => {
  it('reads the reasons of a type, the deactivated ones included', async () => {
    const { calls } = mockApi({ 'GET /api/v1/admin/reason-codes': () => json({ data: [saved, { ...saved, id: 10, is_active: false }] }) })
    const { wrapper } = setup()
    const { result } = renderHook(() => useAdminReasons('CANCELLATION'), { wrapper })

    await waitFor(() => expect(result.current.data).toHaveLength(2))
    expect(calls[0]).toMatchObject({ method: 'GET', path: '/api/v1/admin/reason-codes' })
  })

  it('does not read when disabled', () => {
    const { calls } = mockApi({})
    const { wrapper } = setup()
    renderHook(() => useAdminReasons('CANCELLATION', false), { wrapper })
    expect(calls).toEqual([])
  })

  it('reads again the lists of the administrator and the choice lists of the field screens after a creation', async () => {
    const { calls } = mockApi({ 'POST /api/v1/admin/reason-codes': () => json(saved, 201) })
    const { queryClient, wrapper } = setup()
    const { result } = renderHook(() => useCreateReason(), { wrapper })

    await result.current.mutateAsync({ type: 'ADJUSTMENT', code: 'DEMANDE_CLIENT', label: 'À la demande du client', sort_order: 30 })

    expect(calls.find((call) => call.method === 'POST')).toMatchObject({ method: 'POST', path: '/api/v1/admin/reason-codes', body: { type: 'ADJUSTMENT', code: 'DEMANDE_CLIENT', label: 'À la demande du client', sort_order: 30 } })
    expect(queryClient.getQueryState(adminReasonsOfType('ADJUSTMENT'))?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(reasonKey(['ADJUSTMENT']))?.isInvalidated).toBe(true)
  })

  it('reads again both lists after a modification, sending the changes to the reason named by the path', async () => {
    const { calls } = mockApi({ 'PATCH /api/v1/admin/reason-codes/9': () => json({ ...saved, is_active: false }) })
    const { queryClient, wrapper } = setup()
    const { result } = renderHook(() => useUpdateReason(), { wrapper })

    await result.current.mutateAsync({ id: 9, changes: { is_active: false } })

    expect(calls.find((call) => call.method === 'PATCH')).toMatchObject({ method: 'PATCH', path: '/api/v1/admin/reason-codes/9', body: { is_active: false } })
    expect(queryClient.getQueryState(adminReasonsOfType('ADJUSTMENT'))?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(reasonKey(['ADJUSTMENT']))?.isInvalidated).toBe(true)
  })
})
