import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SettingsPage } from './SettingsPage'

const updateUser = vi.fn()
const invite = vi.fn()
const removeUser = vi.fn()

vi.mock('../../app/AuthContext', () => ({
  useAuth: () => ({ profile: { user: { public_id: 'U1' } }, can: () => true }),
}))

vi.mock('../../services/company', () => ({
  companyApi: {
    get: () => Promise.resolve({ data: { public_id: 'T1', name: 'Empresa Ficticia', legal_name: null, rut: null, status: 'ACTIVE', is_demo: false, members_count: 2, can_manage: true, can_manage_users: true } }),
    update: vi.fn(),
    users: () =>
      Promise.resolve({
        data: [
          { public_id: 'U1', name: 'Admin Uno', email: 'admin@ejemplo.test', role: { key: 'tenant_admin', name: 'Administrador de empresa' }, is_active: true, account_active: true, last_login_at: null, joined_at: null },
          { public_id: 'U2', name: 'Persona Dos', email: 'dos@ejemplo.test', role: { key: 'tenant_read_only', name: 'Solo lectura' }, is_active: true, account_active: true, last_login_at: null, joined_at: null },
        ],
      }),
    roles: () =>
      Promise.resolve({
        data: [
          { key: 'tenant_admin', name: 'Administrador de empresa', permissions: [] },
          { key: 'tenant_procurement_user', name: 'Usuario de compras', permissions: [] },
          { key: 'tenant_read_only', name: 'Solo lectura', permissions: [] },
        ],
      }),
    removeUser: (...a: unknown[]) => {
      removeUser(...a)
      return Promise.resolve({ data: [] })
    },
    updateUser: (...a: unknown[]) => {
      updateUser(...a)
      return Promise.resolve({ data: [] })
    },
    invite: (...a: unknown[]) => {
      invite(...a)
      return Promise.resolve({ data: { public_id: 'U3', new_account: true, link_sent: false, initial_password: 'Kp7m-Qx3r-Vb9t-Hw2n-58', message: 'Persona agregada.' } })
    },
  },
}))

function wrap() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Configuración', () => {
  it('muestra la empresa y protege la propia cuenta', async () => {
    wrap()
    expect(await screen.findByDisplayValue('Empresa Ficticia', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(await screen.findByText('Persona Dos')).toBeInTheDocument()

    // No puedes quitarte el acceso a ti mismo.
    expect(screen.getByRole('checkbox', { name: 'Acceso de Admin Uno' })).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Acceso de Persona Dos' }))
    await waitFor(() => expect(updateUser).toHaveBeenCalledWith('U2', { role_key: undefined, is_active: false }))
  })

  it('quita a una persona de la empresa con confirmación, pero no a uno mismo', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    wrap()
    expect(await screen.findByText('Persona Dos', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Quitar a Admin Uno de la empresa' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Quitar a Persona Dos de la empresa' }))
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('su cuenta se elimina'))
    await waitFor(() => expect(removeUser).toHaveBeenCalledWith('U2'))
    confirm.mockRestore()
  })

  it('invita a una persona con rol', async () => {
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Invitar persona' }, { timeout: 5000 }))
    const send = await screen.findByRole('button', { name: 'Agregar y generar contraseña' })
    expect(send).toBeDisabled()

    fireEvent.change(screen.getByLabelText(/Nombre y apellido/), { target: { value: 'Persona Tres' } })
    fireEvent.change(screen.getByLabelText(/^Correo/), { target: { value: 'tres@ejemplo.test' } })
    fireEvent.click(send)
    await waitFor(() => expect(invite).toHaveBeenCalledWith({ name: 'Persona Tres', email: 'tres@ejemplo.test', role_key: 'tenant_procurement_user', credential: 'password' }))
    // La contraseña de inicio se muestra una sola vez.
    expect(await screen.findByText('Kp7m-Qx3r-Vb9t-Hw2n-58')).toBeInTheDocument()
  })
})
