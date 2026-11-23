import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { ApiTokensCard } from '../settings/ApiTokensCard'
import { PlanCard } from '../settings/PlanCard'
import { AdminTenantsPage } from './AdminTenantsPage'

const create = vi.fn()
const createToken = vi.fn()
let canAdmin = true

vi.mock('../../app/AuthContext', () => ({
  useAuth: () => ({ profile: { user: { public_id: 'U1' } }, can: (p: string) => (p === 'platform.tenants.manage' ? canAdmin : true) }),
}))

const plan = { key: 'start', name: 'Start', description: 'Compras privadas básicas.', modules: [{ key: 'procurement', name: 'Compras privadas' }, { key: 'suppliers', name: 'Proveedores' }], max_users: 5 }
const subscription = { public_id: 'S1', status: 'active', status_label: 'Vigente', starts_on: '2026-11-01', ends_on: null, plan }

vi.mock('../../services/company', () => ({
  adminApi: {
    plans: () => Promise.resolve({ data: [plan], meta: { request_id: 'r', modules: [] } }),
    tenants: () =>
      Promise.resolve({
        data: [{ public_id: 'T1', name: 'Constructora Ficticia', legal_name: null, rut: '76.086.428-5', status: 'ACTIVE', is_demo: false, subscription, seats: { used: 2, max: 5 }, created_at: null }],
        meta: { request_id: 'r', pagination: { page: 1, per_page: 20, total: 1, last_page: 1 } },
      }),
    tenant: vi.fn(),
    create: (...a: unknown[]) => {
      create(...a)
      return Promise.resolve({ data: { message: 'Empresa creada.' } })
    },
  },
  tokensApi: {
    list: () => Promise.resolve({ data: [], meta: { request_id: 'r', available_abilities: ['suppliers.read', 'suppliers.update'], expiry_days: [30, 90] } }),
    create: (...a: unknown[]) => {
      createToken(...a)
      return Promise.resolve({ data: { token: {}, plain_text_token: '7|tiare_valorDePrueba' } })
    },
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

describe('Administración TIARE', () => {
  it('lista empresas con su plan y crea una con su administrador', async () => {
    canAdmin = true
    wrap(<AdminTenantsPage />)
    expect(await screen.findByText('Constructora Ficticia', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getAllByText('Start').length).toBeGreaterThan(0)

    fireEvent.click(screen.getByRole('button', { name: 'Nueva empresa' }))
    const submit = await screen.findByRole('button', { name: 'Crear empresa e invitar administrador' })
    expect(submit).toBeDisabled()
    fireEvent.change(screen.getByRole('textbox', { name: 'Nombre' }), { target: { value: 'Minera Ficticia' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Nombre y apellido' }), { target: { value: 'Ana Prueba' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Correo' }), { target: { value: 'ana@ejemplo.test' } })
    fireEvent.click(submit)
    await waitFor(() => expect(create).toHaveBeenCalledWith(expect.objectContaining({ name: 'Minera Ficticia', plan_key: 'start', admin_email: 'ana@ejemplo.test' })))
  })

  it('no se muestra a quien no es administrador TIARE', () => {
    canAdmin = false
    wrap(<AdminTenantsPage />)
    expect(screen.queryByText('Administración TIARE')).not.toBeInTheDocument()
  })
})

describe('Plan y tokens de la empresa', () => {
  it('muestra el plan contratado con módulos y cupo de personas', () => {
    wrap(<PlanCard company={{ public_id: 'T1', name: 'X', legal_name: null, rut: null, status: 'ACTIVE', is_demo: false, members_count: 2, can_manage: true, can_manage_users: true, can_manage_tokens: true, subscription: subscription as never, seats: { used: 2, max: 5 } }} />)
    expect(screen.getByText('Plan Start')).toBeInTheDocument()
    expect(screen.getByText('Compras privadas')).toBeInTheDocument()
    expect(screen.getByText('2 de 5')).toBeInTheDocument()
  })

  it('avisa cuando la empresa no tiene plan vigente', () => {
    wrap(<PlanCard company={{ public_id: 'T1', name: 'X', legal_name: null, rut: null, status: 'ACTIVE', is_demo: false, members_count: 1, can_manage: true, can_manage_users: true, can_manage_tokens: true, subscription: null, seats: { used: 1, max: null } }} />)
    expect(screen.getByText(/no tiene un plan vigente/)).toBeInTheDocument()
  })

  it('crea un token con permisos elegidos y lo muestra una sola vez', async () => {
    wrap(<ApiTokensCard onToast={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Crear token' }, { timeout: 5000 }))
    fireEvent.change(await screen.findByRole('textbox', { name: 'Nombre' }), { target: { value: 'ERP interno' } })
    fireEvent.click(screen.getByRole('button', { name: 'Solo lectura' }))
    const buttons = screen.getAllByRole('button', { name: 'Crear token' })
    fireEvent.click(buttons[buttons.length - 1])
    await waitFor(() => expect(createToken).toHaveBeenCalledWith({ name: 'ERP interno', abilities: ['suppliers.read'], expires_in_days: 90 }))
    expect(await screen.findByText('7|tiare_valorDePrueba')).toBeInTheDocument()
    expect(screen.getByText(/no se volverá a mostrar/)).toBeInTheDocument()
  })
})
