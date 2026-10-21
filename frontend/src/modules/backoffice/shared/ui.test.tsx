import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DataTable, TableHead, Th } from './ui'

describe('Th', () => {
  it('aligns at the start by default, and at the end when asked (quantities, amounts)', () => {
    render(
      <DataTable>
        <TableHead>
          <Th>Produit</Th>
          <Th className="text-end">Quantité</Th>
        </TableHead>
      </DataTable>,
    )

    expect(screen.getByRole('columnheader', { name: 'Produit' })).toHaveClass('text-start')
    // Deux classes text-* : l'ordre de la feuille de style l'emporterait et l'en-tête resterait à gauche.
    const quantity = screen.getByRole('columnheader', { name: 'Quantité' })
    expect(quantity).toHaveClass('text-end')
    expect(quantity).not.toHaveClass('text-start')
  })
})
