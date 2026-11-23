import { fireEvent, render, screen } from '@testing-library/react'
import { CompraAgilRow } from './CompraAgilRow'
import type { CompraAgil } from '../../types/api'

const base: CompraAgil = {
  public_id: '01JTEST0000000000000000000',
  code: '9000001-11-COT26',
  source_type: 'compra_agil',
  data_origin: 'official',
  title: 'SOPORTES Y TELONES (FIXTURE)',
  status: { code: 'publicada', label: 'Publicada' },
  call_number: 2,
  dates: { published_at: null, closes_at: '2030-01-01T14:00:00+00:00', first_call_closes_at: null, second_call_closes_at: null, cancelled_at: null, source_updated_at: null },
  buyer: { name: 'ORGANISMO FICTICIO', rut: '61.000.001-1', unit: null, region_code: 13, region_name: 'Región Metropolitana de Santiago' },
  budget: { currency: 'CLP', type: null, estimated: null, available: '1599000', available_clp: '1599000' },
  offers_count: 0,
  purchase_order: { known: false, issued: null, source_id: null, code: null },
  reasons: { cancellation: null, deserted: null, selection: null },
  freshness: { last_fetched_at: null, detail_fetched_at: null },
}

describe('CompraAgilRow', () => {
  it('muestra código, estado, organismo y monto, y abre la ficha', () => {
    const onOpen = vi.fn()
    render(<CompraAgilRow item={base} onOpen={onOpen} />)

    expect(screen.getByText('9000001-11-COT26')).toHaveClass('code')
    expect(screen.getByText('Publicada').closest('.status-chip')).toHaveClass('tone-approved')
    expect(screen.getByText(/ORGANISMO FICTICIO, Región Metropolitana/)).toBeInTheDocument()
    expect(screen.getByText(/Segundo llamado/)).toBeInTheDocument()
    expect(screen.getByText(/1\.599\.000/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button'))
    expect(onOpen).toHaveBeenCalledWith('9000001-11-COT26')
  })

  it('marca en cobre los cierres de menos de 24 horas', () => {
    const soon = new Date(Date.now() + 3 * 3_600_000).toISOString()
    render(<CompraAgilRow item={{ ...base, dates: { ...base.dates, closes_at: soon } }} onOpen={() => {}} />)
    expect(screen.getByText(/Cierra en \d+ h/).closest('.closing-chip')).toHaveClass('tone-soon')
  })
})
