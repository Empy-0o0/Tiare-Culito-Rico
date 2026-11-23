import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { ReportsPage } from './ReportsPage'

vi.mock('../../app/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }))

vi.mock('../../services/procurement', () => ({
  procurementApi: {
    indicators: () =>
      Promise.resolve({
        data: {
          period: { from: '2026-08-21', to: '2026-11-18', timezone: 'America/Santiago' },
          generated_at: '2026-11-18T15:00:00Z',
          currency: 'CLP',
          source: 'Datos registrados en TIARE.',
          metrics: [
            { key: 'savings_vs_estimate', label: 'Ahorro vs precio de referencia', value: '15000', unit: 'CLP', n: 1, formula: 'Σ (estimado − adjudicado)', assumptions: ['Comparación en neto'], percent: 9.4 },
            { key: 'on_time', label: 'Entregas a tiempo', value: null, unit: '%', n: 0, formula: 'a ÷ b', assumptions: [] },
          ],
          pipeline: { QUOTING: 2 },
          invoices: { pending_count: 1, pending_amount_clp: '172550', overdue_count: 1, orders_with_difference: 0, note: 'Pagos informados.' },
        },
      }),
    invoices: () => Promise.resolve({ data: [], meta: { request_id: 'x', pagination: { page: 1, per_page: 15, total: 0, last_page: 1 } } }),
  },
}))

describe('Analítica e informes', () => {
  it('muestra cada indicador con su base, sin inventar valores, y explica el cálculo', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <ReportsPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    expect(await screen.findByText('Ahorro vs precio de referencia', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText(/15\.000/)).toBeInTheDocument()
    expect(screen.getByText(/Base: 1 caso, 9,4 % del estimado/)).toBeInTheDocument()
    expect(screen.getByText('Sin datos en el período')).toBeInTheDocument()
    expect(screen.getByText('En cotización: 2')).toBeInTheDocument()

    fireEvent.click(screen.getAllByRole('button', { name: 'Cómo se calcula' })[0])
    expect(screen.getByText('Comparación en neto')).toBeVisible()
  })
})
