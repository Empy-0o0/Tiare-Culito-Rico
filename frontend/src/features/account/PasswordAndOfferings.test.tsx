import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { InitialPasswordDialog } from '../admin/InitialPasswordDialog'
import { SupplierOfferingsPanel } from '../crm/SupplierOfferingsPanel'
import { MyAccountPage } from './MyAccountPage'

const changePassword = vi.fn()
const refresh = vi.fn()
const createOffering = vi.fn()
let mustChange = true

vi.mock('../../app/AuthContext', () => ({
  useAuth: () => ({ profile: { user: { public_id: 'U1', name: 'Ana Ficticia', email: 'ana@ejemplo.test' }, current_tenant: null, must_change_password: mustChange, password_changed_at: null }, refresh, can: () => true }),
}))

vi.mock('../../services/publicProcurement', () => ({
  authApi: {
    changePassword: (...a: unknown[]) => {
      changePassword(...a)
      return Promise.resolve({ data: { message: 'Contraseña actualizada.', password_changed_at: null } })
    },
  },
  publicProcurementApi: { catalogs: () => Promise.resolve({ data: { regions: [] } }) },
}))

vi.mock('../../services/crm', () => ({
  crmApi: {
    references: () => Promise.resolve({ data: { units_of_measure: [{ code: 'EA', name: 'Unidad' }] } }),
    categories: () => Promise.resolve({ data: [] }),
    offerings: () =>
      Promise.resolve({
        data: [
          {
            public_id: 'O1',
            kind: { code: 'PRODUCT', label: 'Insumo / producto' },
            name: 'Papel higiénico doble hoja',
            description: null,
            category: null,
            product: null,
            unit: { code: 'EA', name: 'Unidad' },
            capacity: { quantity: '5000.000', period: { code: 'MONTH', label: 'por mes' }, min_order_quantity: '100.000', lead_time_days: 3, notes: null, confirmed_on: null, data_origin: 'supplier_declared' },
            is_active: true,
            updated_at: null,
          },
        ],
        meta: { request_id: 'r', kinds: [], periods: [{ code: 'MONTH', label: 'por mes' }, { code: 'DAY', label: 'por día' }], note: 'Capacidad declarada por el proveedor.' },
      }),
    createOffering: (...a: unknown[]) => {
      createOffering(...a)
      return Promise.resolve({ data: {} })
    },
    updateOffering: vi.fn(),
    deleteOffering: vi.fn(),
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

describe('Mi cuenta', () => {
  it('con contraseña de inicio exige cambiarla y valida la política antes de enviar', async () => {
    mustChange = true
    wrap(<MyAccountPage />)
    expect(screen.getByText(/contraseña de inicio/)).toBeInTheDocument()
    const submit = screen.getByRole('button', { name: 'Cambiar contraseña' })
    fireEvent.change(screen.getByLabelText(/Contraseña de inicio/), { target: { value: 'Kp7m-Qx3r-Vb9t-Hw2n-58' } })
    fireEvent.change(screen.getByLabelText(/^Nueva contraseña/), { target: { value: 'corta' } })
    expect(submit).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/^Nueva contraseña/), { target: { value: 'MiClaveNueva2026' } })
    fireEvent.change(screen.getByLabelText(/Repite la nueva contraseña/), { target: { value: 'MiClaveNueva2026' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)
    await waitFor(() => expect(changePassword).toHaveBeenCalledWith({ current_password: 'Kp7m-Qx3r-Vb9t-Hw2n-58', password: 'MiClaveNueva2026', password_confirmation: 'MiClaveNueva2026' }))
    await waitFor(() => expect(refresh).toHaveBeenCalled())
  })
})

describe('Contraseña de inicio', () => {
  it('se muestra una vez con advertencia', () => {
    wrap(<InitialPasswordDialog value="Kp7m-Qx3r-Vb9t-Hw2n-58" name="Ana" email="ana@ejemplo.test" onClose={() => {}} />)
    expect(screen.getByText('Kp7m-Qx3r-Vb9t-Hw2n-58')).toBeInTheDocument()
    expect(screen.getByText(/solo esta vez/)).toBeInTheDocument()
  })
})

describe('Oferta y capacidad del proveedor', () => {
  it('muestra capacidad declarada y agrega un servicio', async () => {
    wrap(<SupplierOfferingsPanel supplierId="S1" canManage />)
    expect(await screen.findByText('Papel higiénico doble hoja', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('5.000 unidad por mes')).toBeInTheDocument()
    expect(screen.getByText('3 días')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Agregar insumo o servicio' }))
    fireEvent.change(await screen.findByRole('textbox', { name: 'Nombre' }), { target: { value: 'Limpieza de oficinas' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Cantidad' }), { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(createOffering).toHaveBeenCalledWith('S1', expect.objectContaining({ name: 'Limpieza de oficinas', capacity_quantity: 20, capacity_period: 'MONTH' })))
  })
})
