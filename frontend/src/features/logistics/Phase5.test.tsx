import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import type { ShipmentDetail } from '../../services/logistics'
import { InventoryPage } from '../inventory/InventoryPage'
import { LogisticsPage } from './LogisticsPage'

const move = vi.fn()
const deliver = vi.fn()

const pagination = { page: 1, per_page: 25, total: 1, last_page: 1 }
const warehouse = { public_id: 'W1', code: 'CENTRAL', name: 'Bodega central', address: null, region: null, is_active: true, items_count: 1 }
const product = { public_id: 'P1', kind: 'PRODUCT', sku: 'DET-5L', name: 'Detergente 5 L', description: null, brand: null, public_catalog_code: null, is_active: true, unit: { code: 'EA', name: 'Unidad' }, category: null }

const shipment: ShipmentDetail = {
  public_id: 'D1',
  number: 'DSP-2026-0001',
  status: { code: 'IN_TRANSIT', label: 'En tránsito' },
  carrier: { public_id: 'C1', name: 'TRANSPORTES SUR' },
  service_type: { code: 'EXPRESS', label: 'Express' },
  origin: 'Bodega central, Santiago',
  destination: 'Av. Prat 100, Concepción',
  tracking_code: 'TS123',
  tracking_url: 'https://transportessur.cl/t?n=TS123',
  planned_delivery_on: '2026-11-10',
  delivered_at: null,
  late: true,
  open_incidents: 1,
  order: { public_id: 'O1', number: 'OCI-2026-0001' },
  destination_region: { code: 8, name: 'Biobío' },
  contact_name: null,
  contact_phone: null,
  packages: 2,
  weight_kg: '15.500',
  planned_pickup_on: null,
  picked_up_at: '2026-11-08T12:00:00Z',
  estimated_cost: '25000.00',
  actual_cost: null,
  received_by_name: null,
  delivery_notes: null,
  has_evidence: false,
  evidence_url: null,
  notes: null,
  events: [{ status: { code: 'IN_TRANSIT', label: 'En tránsito' }, description: 'Retirado en bodega', location: null, occurred_at: '2026-11-08T12:00:00Z', user: 'Operador' }],
  incidents: [{ public_id: 'I1', type: { code: 'DELAY', label: 'Atraso' }, description: 'Camión en panne', status: 'OPEN', resolution: null, reported_by: 'Operador', created_at: '2026-11-09T12:00:00Z', resolved_at: null }],
  actions: { update: true, transitions: ['FAILED', 'RETURNED'], deliver: true, report_incident: true },
}

vi.mock('../../app/AuthContext', () => ({ useAuth: () => ({ profile: null, can: () => true }) }))
vi.mock('../../services/publicProcurement', () => ({
  publicProcurementApi: { catalogs: () => Promise.resolve({ data: { regions: [{ code: 8, name: 'Biobío' }] } }) },
}))
vi.mock('../../services/crm', () => ({
  crmApi: {
    references: () => Promise.resolve({ data: {} }),
    products: () => Promise.resolve({ data: [product], meta: { request_id: 'x', pagination } }),
  },
}))
vi.mock('../../services/procurement', () => ({
  procurementApi: { orders: () => Promise.resolve({ data: [], meta: { request_id: 'x', pagination } }) },
}))
vi.mock('../../services/logistics', () => ({
  inventoryApi: {
    references: () => Promise.resolve({ data: { movement_types: [], warehouses: [warehouse] } }),
    warehouses: () => Promise.resolve({ data: [warehouse] }),
    stock: () =>
      Promise.resolve({
        data: [
          { product: { public_id: 'P1', name: 'Detergente 5 L', sku: 'DET-5L', unit: 'EA' }, warehouse: { public_id: 'W1', name: 'Bodega central', code: 'CENTRAL' }, quantity_on_hand: '3.0000', min_quantity: '5.0000', max_quantity: '20.0000', location: null, low_stock: true, reorder_suggestion: '17.0000' },
        ],
        meta: { request_id: 'x', pagination, low_stock_count: 1 },
      }),
    movements: () => Promise.resolve({ data: [], meta: { request_id: 'x', pagination } }),
    move: (...a: unknown[]) => {
      move(...a)
      return Promise.resolve({ data: {} })
    },
  },
  logisticsApi: {
    references: () =>
      Promise.resolve({
        data: { statuses: [{ code: 'IN_TRANSIT', label: 'En tránsito' }], service_types: [{ code: 'EXPRESS', label: 'Express' }], incident_types: [{ code: 'DELAY', label: 'Atraso' }] },
      }),
    carriers: () => Promise.resolve({ data: [] }),
    shipments: () => Promise.resolve({ data: [shipment], meta: { request_id: 'x', pagination, status_counts: { IN_TRANSIT: 1 } } }),
    shipment: () => Promise.resolve({ data: shipment }),
    deliver: (...a: unknown[]) => {
      deliver(...a)
      return Promise.resolve({ data: { ...shipment, status: { code: 'DELIVERED', label: 'Entregado' }, actions: { ...shipment.actions, deliver: false, transitions: [] } } })
    },
  },
}))

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Fase 5: inventario', () => {
  it('avisa stock bajo y sugiere reposición', async () => {
    wrap(<InventoryPage />)
    expect(await screen.findByText('Un producto está bajo su stock mínimo.', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('Reponer 17')).toBeInTheDocument()
  })

  it('exige motivo para un ajuste y envía el movimiento', async () => {
    wrap(<InventoryPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Mover' }, { timeout: 5000 }))
    const dialog = await screen.findByRole('dialog')
    await waitFor(() => expect(within(dialog).getByLabelText(/Producto/)).toHaveValue('Detergente 5 L (DET-5L)'))

    fireEvent.mouseDown(within(dialog).getByLabelText('Tipo'))
    fireEvent.click(await screen.findByRole('option', { name: 'Ajuste negativo' }))
    fireEvent.change(within(dialog).getByLabelText(/Cantidad/), { target: { value: '1' } })
    const submit = within(dialog).getByRole('button', { name: 'Registrar movimiento' })
    expect(submit).toBeDisabled()

    fireEvent.change(within(dialog).getByLabelText(/Motivo del ajuste/), { target: { value: 'Conteo físico' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)
    await waitFor(() => expect(move).toHaveBeenCalled())
    expect(move.mock.calls[0][0]).toMatchObject({ type: 'ADJUST_OUT', product_public_id: 'P1', warehouse_public_id: 'W1', quantity: '1', reason: 'Conteo físico' })
  })
})

describe('Fase 5: logística', () => {
  it('lista despachos atrasados y confirma la entrega desde la ficha', async () => {
    wrap(<LogisticsPage />)
    const row = await screen.findByRole('button', { name: 'Abrir despacho DSP-2026-0001' }, { timeout: 5000 })
    expect(within(row).getByText('1 incidencia')).toBeInTheDocument()
    fireEvent.click(row)

    expect(await screen.findByText('Camión en panne')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'TS123' })).toHaveAttribute('href', 'https://transportessur.cl/t?n=TS123')

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar entrega' }))
    const dialog = await screen.findByRole('dialog', { name: /Confirmar entrega de DSP-2026-0001/ })
    fireEvent.change(within(dialog).getByLabelText(/Nombre de quien recibe/), { target: { value: 'María Pérez' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmar entrega' }))
    await waitFor(() => expect(deliver).toHaveBeenCalled())
    expect(deliver.mock.calls[0]).toEqual(['D1', { received_by_name: 'María Pérez', delivery_notes: undefined, evidence: null }])
  })
})
