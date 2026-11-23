import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { OrderDetail, PurchaseRequestDetail } from '../../types/procurement'
import { PurchaseOrdersPage } from './PurchaseOrdersPage'
import { PurchaseRequestDetailPage } from './PurchaseRequestDetailPage'

const decide = vi.fn()
const receive = vi.fn()

const quotation = {
  public_id: 'Q1',
  supplier: { public_id: 'S1', name: 'PROVEEDOR BETA SPA' },
  reference: null,
  status: 'SELECTED' as const,
  currency: 'CLP',
  exchange_rate_to_clp: null,
  exchange_rate_source: null,
  tax_rate: '19.00',
  prices_include_tax: false,
  shipping_cost: '0.00',
  subtotal_net: '145000.00',
  tax_amount: '27550.00',
  total: '172550.00',
  total_clp: '172550.00',
  delivery_days: 2,
  warranty_months: null,
  payment_terms: null,
  valid_until: '2030-12-15',
  expired: false,
  received_on: '2026-11-18',
  notes: null,
  items: [{ line_number: 1, quantity: '10.0000', unit_price: '14500.00', line_total: '145000.00', offered_brand: null }],
}

const request: PurchaseRequestDetail = {
  public_id: 'R1',
  number: 'SC-2026-0001',
  title: 'Insumos de aseo',
  status: { code: 'PENDING_APPROVAL', label: 'Esperando aprobación' },
  priority: { code: 'HIGH', label: 'Alta' },
  needed_by: null,
  currency: 'CLP',
  estimated_total: '160000.00',
  selected_total_clp: '172550.00',
  items_count: 1,
  quotations_count: 1,
  requested_by: 'Comprador',
  customer: null,
  created_at: null,
  data_origin: 'client',
  description: null,
  cost_center: null,
  delivery_address: null,
  customer_public_id: null,
  version: 1,
  proposed_by: 'Analista',
  proposal_justification: null,
  selected_quotation_public_id: 'Q1',
  dates: { submitted_at: null, proposed_at: '2026-11-18T15:00:00Z', decided_at: null, cancelled_at: null },
  cancel_reason: null,
  items: [{ line_number: 1, product_public_id: null, description: 'Detergente 5 L', quantity: '10.0000', unit_code: 'EA', estimated_unit_price: '16000.00', notes: null }],
  quotation_requests: [],
  quotations: [quotation],
  comparison: null,
  approvals: [],
  order: null,
  actions: { edit: false, submit: false, start_quoting: false, manage_quotations: false, delete_quotations: false, compare: false, propose: false, decide: true, decide_blocked_reason: null, issue_order: false, cancel: true },
}

const order: OrderDetail = {
  public_id: 'O1',
  number: 'OCI-2026-0001',
  status: { code: 'SENT', label: 'Enviada al proveedor' },
  supplier: { public_id: 'S1', name: 'PROVEEDOR BETA SPA' },
  currency: 'CLP',
  total: '172550.00',
  issued_at: null,
  expected_delivery_on: null,
  request: { public_id: 'R1', number: 'SC-2026-0001', title: 'Insumos de aseo' },
  exchange_rate_to_clp: null,
  subtotal_net: '145000.00',
  shipping_cost: '0.00',
  tax_amount: '27550.00',
  responsibles: { payer_name: 'Empresa', invoice_holder_name: 'Empresa', invoice_holder_rut: null, contractual_buyer: 'Empresa', receiver_name: 'Bodega' },
  delivery_address: null,
  payment_terms: null,
  notes: null,
  issued_by: null,
  sent_at: null,
  cancelled_at: null,
  cancel_reason: null,
  items: [{ line_number: 1, description: 'Detergente 5 L', unit_code: 'EA', quantity: '10.0000', unit_price: '14500.00', line_total: '145000.00', received_quantity: '4.0000' }],
  receipts: [],
  invoices: [],
  invoicing: { invoiced_total: '0.00', order_total: '172550.00', difference: '-172550.00', matches: false, paid_total: '0.00' },
  actions: { mark_sent: false, receive: true, cancel: false, delete: false, register_invoice: true, inform_payment: true },
}

vi.mock('../../services/procurement', () => ({
  procurementApi: {
    references: () => Promise.resolve({ data: { statuses: [], order_statuses: [], priorities: [], default_weights: { price: 0.6, delivery: 0.25, supplier: 0.15 }, default_tax_rate: 19 } }),
    get: () => Promise.resolve({ data: request }),
    decide: (...a: unknown[]) => {
      decide(...a)
      return Promise.resolve({ data: { ...request, status: { code: 'APPROVED', label: 'Aprobada' } } })
    },
    orders: () => Promise.resolve({ data: [order], meta: { request_id: 'x', pagination: { page: 1, per_page: 25, total: 1, last_page: 1 } } }),
    order: () => Promise.resolve({ data: order }),
    receive: (...a: unknown[]) => {
      receive(...a)
      return Promise.resolve({ data: { ...order, status: { code: 'RECEIVED', label: 'Recibida' } } })
    },
  },
}))

vi.mock('../../app/AuthContext', () => ({ useAuth: () => ({ profile: null, can: () => true }) }))
vi.mock('../../services/logistics', () => ({
  inventoryApi: { warehouses: () => Promise.resolve({ data: [{ public_id: 'W1', code: 'CENTRAL', name: 'Bodega central', address: null, region: null, is_active: true }] }) },
}))

function wrap(ui: ReactNode, path: string, route: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={route} element={ui} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('compras privadas', () => {
  it('la ficha abre en aprobación y permite aprobar; rechazar exige comentario', async () => {
    wrap(<PurchaseRequestDetailPage />, '/solicitudes/R1', '/solicitudes/:id')
    expect(await screen.findByRole('button', { name: 'Aprobar compra' }, { timeout: 5000 })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Rechazar y volver a cotizar' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Aprobar compra' }))
    await waitFor(() => expect(decide).toHaveBeenCalledWith('R1', { decision: 'APPROVED', comment: undefined }))
  })

  it('registra una recepción limitada a lo pendiente', async () => {
    wrap(<PurchaseOrdersPage />, '/ordenes?ficha=O1', '/ordenes')
    fireEvent.click(await screen.findByRole('button', { name: 'Registrar recepción' }, { timeout: 5000 }))
    const input = await screen.findByLabelText(/Recibido conforme/)
    expect(input).toHaveValue(6) // pendiente = 10 − 4

    fireEvent.change(input, { target: { value: '7' } })
    expect(screen.getByText('Máximo 6')).toBeInTheDocument()
    fireEvent.change(input, { target: { value: '6' } })
    const buttons = screen.getAllByRole('button', { name: 'Registrar recepción' })
    fireEvent.click(buttons[buttons.length - 1])
    await waitFor(() => expect(receive).toHaveBeenCalled())
    expect(receive.mock.calls[0][1].warehouse_public_id).toBeUndefined()
    expect(receive.mock.calls[0][1].items).toEqual([{ line_number: 1, quantity_received: '6', quantity_rejected: '0', incident_note: undefined }])
  })
})
