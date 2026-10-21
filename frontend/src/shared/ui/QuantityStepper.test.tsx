import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { MAX_LINE_QUANTITY, QuantityStepper } from './QuantityStepper'

function Harness({ max }: { max: number | null }) {
  const [value, setValue] = useState(0)
  return <QuantityStepper name="Jus orange 1 L" value={value} onChange={setValue} max={max} />
}

describe('QuantityStepper', () => {
  it('steps and types a quantity, capped at the maximum when it is known', async () => {
    const user = userEvent.setup()
    render(<Harness max={3} />)
    const input = screen.getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })

    expect(screen.getByRole('button', { name: 'Diminuer la quantité de Jus orange 1 L' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' }))
    expect(input).toHaveValue('1')
    await user.clear(input)
    await user.type(input, '12{Enter}')
    expect(input).toHaveValue('3')
    expect(screen.getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' })).toBeDisabled()
  })

  it('has no maximum without a borne', async () => {
    const user = userEvent.setup()
    render(<Harness max={null} />)
    const input = screen.getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })
    await user.clear(input)
    await user.type(input, '250')
    await user.tab()
    expect(input).toHaveValue('250')
    // Plafond d'une ligne de commande (I-60), jamais affiché comme un maximum.
    await user.clear(input)
    await user.type(input, '2000000')
    await user.tab()
    expect(input).toHaveValue(String(MAX_LINE_QUANTITY))
    expect(screen.getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' })).toBeDisabled()
  })
})
