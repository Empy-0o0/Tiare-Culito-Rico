import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { CatalogPage } from './CatalogPage'
import { CustomersPage } from './CustomersPage'
import { SuppliersPage } from './SuppliersPage'

const page = (data: unknown[]) => Promise.resolve({ data, meta: { request_id: 'x', pagination: { page: 1, per_page: 25, total: data.length, last_page: 1 } } })

const org = (name: string) => ({
  public_id: `org-${name}`,
  legal_name: name,
  trade_name: null,
  rut: '76.000.000-0',
  kind: { code: 'SME', label: 'Pyme' },
  segment: 'Construcción',
  email: 'contacto@ejemplo.test',
  phone: null,
  website: null,
  address: null,
  commune: null,
  region: { code: 13, name: 'Metropolitana de Santiago' },
  notes: null,
})

vi.mock('../../app/AuthContext', () => ({
  useAuth: () => ({ profile: { current_tenant: { is_demo: true } }, can: () => true }),
}))

vi.mock('../../services/publicProcurement', () => ({
  publicProcurementApi: { catalogs: () => Promise.resolve({ data: { regions: [{ code: 13, name: 'Metropolitana de Santiago' }] } }) },
}))

vi.mock('../../services/crm', () => ({
  crmApi: {
    references: () =>
      Promise.resolve({
        data: {
          organization_kinds: [{ code: 'SME', label: 'Pyme' }],
          customer_statuses: [{ code: 'ACTIVE', label: 'Activo' }],
          supplier_statuses: [{ code: 'REGISTERED', label: 'Registrado' }],
          customer_plans: ['Básico'],
          units_of_measure: [{ code: 'EA', name: 'Unidad' }],
        },
      }),
    customers: () => page([{ public_id: 'c1', data_origin: 'client', status: { code: 'ACTIVE', label: 'Activo' }, plan: 'Básico', source: null, owner: null, next_action_at: null, next_action_summary: null, organization: org('CONSTRUCTORA FICTICIA') }]),
    customer: () => Promise.resolve({ data: { public_id: 'c1', status: { code: 'ACTIVE', label: 'Activo' }, plan: 'Básico', owner: null, organization: { ...org('CONSTRUCTORA FICTICIA'), contacts: [] } } }),
    suppliers: () =>
      page([
        {
          public_id: 's1',
          data_origin: 'client',
          status: { code: 'REGISTERED', label: 'Registrado' },
          coverage_regions: [{ code: 13, name: 'Metropolitana de Santiago' }],
          payment_terms: null,
          lead_time_days: 5,
          notes: null,
          categories: [{ public_id: 'k1', code: null, name: 'Aseo', is_active: true }],
          organization: org('PROVEEDOR FICTICIO SPA'),
          evaluation: { count: 1, average: '4.00' },
          expired_documents: 1,
        },
      ]),
    categories: () => Promise.resolve({ data: [{ public_id: 'k1', code: null, name: 'Aseo', is_active: true }] }),
    evaluations: () =>
      Promise.resolve({
        data: [{ public_id: 'e1', evaluated_on: '2026-11-10', reference: 'OC-1', scores: { price_score: 4, quality_score: 4, delivery_score: 4, compliance_score: 4, service_score: 4 }, overall: '4.00', had_incident: true, comment: 'Atraso de 2 días', evaluated_by: 'Admin', created_at: null, data_origin: 'client' }],
        meta: { request_id: 'x', summary: { count: 1, overall: '4.00', by_criterion: { price_score: '4.00', quality_score: '4.00', delivery_score: '4.00', compliance_score: '4.00', service_score: '4.00' }, incidents: 1, last_evaluated_on: '2026-11-10' }, methodology: 'Promedio simple.' },
      }),
    documents: () =>
      Promise.resolve({
        data: [{ public_id: 'd1', type: { code: 'LICENSE', label: 'Permiso' }, name: 'Patente municipal', issued_on: null, expires_on: '2026-10-01', validity: 'expired', file: { name: 'p.pdf', mime_type: 'application/pdf', size_bytes: 2048 }, download_url: '/api/v1/x', uploaded_by: 'Admin', uploaded_at: null, archived: false, data_origin: 'client' }],
        meta: { request_id: 'x', types: [{ code: 'LICENSE', label: 'Permiso' }], expiring_days: 30, accepted: 'PDF' },
      }),
    products: () =>
      page([{ public_id: 'p1', kind: 'PRODUCT', sku: 'SKU-1', name: 'Detergente 5 L', description: null, brand: 'Marca', public_catalog_code: null, is_active: true, unit: { code: 'EA', name: 'Unidad' }, category: { public_id: 'k1', code: null, name: 'Aseo', is_active: true } }]),
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

describe('páginas CRM con MUI', () => {
  it('Clientes lista, abre la ficha y el formulario', async () => {
    wrap(<CustomersPage />)
    expect(await screen.findByText('CONSTRUCTORA FICTICIA')).toBeInTheDocument()
    expect(screen.getByText('Activo').closest('.status-chip')).toHaveClass('tone-approved')

    fireEvent.click(screen.getByRole('button', { name: 'Ver ficha de CONSTRUCTORA FICTICIA' }))
    expect(await screen.findByRole('heading', { name: 'CONSTRUCTORA FICTICIA' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }))
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'CONSTRUCTORA FICTICIA' })).not.toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: 'Agregar cliente' }))
    expect(await screen.findByRole('button', { name: 'Guardar cliente' })).toBeDisabled()
  })

  it('Proveedores muestra categorías y plazo', async () => {
    wrap(<SuppliersPage />)
    expect(await screen.findByText('PROVEEDOR FICTICIO SPA')).toBeInTheDocument()
    expect(screen.getAllByText('Aseo').length).toBeGreaterThan(0)
    expect(screen.getByText('5 días')).toBeInTheDocument()
    expect(screen.getByText('1 documento vencido')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Ver ficha de PROVEEDOR FICTICIO SPA' }))
    fireEvent.click(await screen.findByRole('tab', { name: 'Evaluación' }))
    expect(await screen.findByText('Atraso de 2 días')).toBeInTheDocument()
    expect(screen.getByText(/1 evaluación, 1 con incidente/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: /Documentos/ }))
    expect(await screen.findByText('Patente municipal')).toBeInTheDocument()
    expect(screen.getByText('Vencido')).toBeInTheDocument()
  })

  it('Catálogo lista productos y categorías', async () => {
    wrap(<CatalogPage />)
    expect(await screen.findByText('Detergente 5 L')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Aseo' })).toBeInTheDocument())
    expect(screen.getByText('SKU-1')).toHaveClass('code')
  })
})
