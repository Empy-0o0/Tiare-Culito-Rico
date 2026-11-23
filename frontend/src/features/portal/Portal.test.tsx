import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { PortalAccessFields, PortalAccessPanel } from '../crm/PortalAccessPanel'
import { PortalPage } from './PortalPage'

const grant = vi.fn()
let accessData: unknown = null
let profileType: 'supplier' | 'customer' = 'supplier'

const org = { legal_name: 'Proveedor Ficticio SpA', trade_name: null, rut: '11.111.111-1', email: null, phone: null, address: null, commune: null, region: 'Metropolitana de Santiago', contacts: [] }

vi.mock('../../services/portal', () => ({
  portalApi: {
    profile: () =>
      Promise.resolve({
        data:
          profileType === 'supplier'
            ? {
                type: 'supplier',
                company: { name: 'Empresa Ficticia' },
                organization: org,
                status: { code: 'REGISTERED', label: 'Registrado' },
                categories: ['Aseo'],
                coverage_regions: ['Metropolitana de Santiago'],
                payment_terms: '30 días',
                lead_time_days: 3,
                offerings: [{ kind: 'Insumo / producto', name: 'Guantes de nitrilo', unit: 'Unidad', capacity_quantity: '2000.000', capacity_period: 'por mes', min_order_quantity: null, lead_time_days: 4, capacity_confirmed_on: null }],
                documents: [{ type: 'Certificado', name: 'Certificado de calidad', expires_on: '2027-01-01', validity: 'valid' }],
                quotation_requests: [{ request_number: 'SC-0001', title: 'Guantes para bodega', status: 'REQUESTED', due_on: null, created_at: null }],
                purchase_orders: [],
              }
            : {
                type: 'customer',
                company: { name: 'Empresa Ficticia' },
                organization: { ...org, legal_name: 'Cliente Ficticio SpA' },
                status: { code: 'ACTIVE', label: 'Activo' },
                plan: 'Growth',
                purchase_requests: [{ number: 'SC-0002', title: 'Resmas de papel', status: { code: 'SUBMITTED', label: 'Enviada' }, needed_by: null, currency: 'CLP', estimated_total: null, created_at: null }],
              },
        meta: { request_id: 'r', note: 'Información registrada por Empresa Ficticia.' },
      }),
    access: () => Promise.resolve({ data: accessData }),
    grant: (...a: unknown[]) => {
      grant(...a)
      return Promise.resolve({ data: { message: 'Acceso creado.', initial_password: 'Kp7m-Qx3r-Vb9t-Hw2n-58', user: { email: 'ventas@proveedor.test' } } })
    },
    password: vi.fn(),
    setActive: vi.fn(),
    revoke: vi.fn(),
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

describe('Portal de proveedor y cliente', () => {
  it('el proveedor ve su perfil, capacidad, documentos y cotizaciones (sin evaluaciones)', async () => {
    profileType = 'supplier'
    wrap(<PortalPage />)
    expect(await screen.findByText('Guantes de nitrilo', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('2.000 unidad por mes')).toBeInTheDocument()
    expect(screen.getByText('Certificado de calidad')).toBeInTheDocument()
    expect(screen.getByText('SC-0001 · Guantes para bodega')).toBeInTheDocument()
    expect(screen.queryByText(/Evaluaci/)).not.toBeInTheDocument()
  })

  it('el cliente ve sus solicitudes de compra', async () => {
    profileType = 'customer'
    wrap(<PortalPage />)
    expect(await screen.findByText('Resmas de papel', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('Portal de cliente · Empresa Ficticia')).toBeInTheDocument()
    expect(screen.queryByText('Órdenes de compra')).not.toBeInTheDocument()
  })
})

describe('Acceso al portal desde la ficha', () => {
  it('crea usuario y muestra la contraseña de inicio una vez', async () => {
    accessData = null
    wrap(<PortalAccessPanel type="supplier" partyId="S1" partyName="Proveedor Ficticio SpA" canManage defaultEmail="ventas@proveedor.test" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Crear usuario y contraseña' }, { timeout: 5000 }))
    await waitFor(() => expect(grant).toHaveBeenCalledWith('supplier', 'S1', { email: 'ventas@proveedor.test' }))
    expect(await screen.findByText('Kp7m-Qx3r-Vb9t-Hw2n-58')).toBeInTheDocument()
  })

  it('en el alta exige un correo válido si se crea el acceso', () => {
    const onChange = vi.fn()
    wrap(<PortalAccessFields type="customer" value={{ enabled: true, email: 'malo' }} onChange={onChange} />)
    expect(screen.getByRole('textbox', { name: /Correo de acceso/ })).toHaveAttribute('aria-invalid', 'true')
    fireEvent.click(screen.getByRole('checkbox'))
    expect(onChange).toHaveBeenCalledWith({ enabled: false, email: 'malo' })
  })
})
