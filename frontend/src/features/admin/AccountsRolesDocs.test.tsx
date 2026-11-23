import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { DeleteRecordButton } from '../../components/DeleteRecordButton'
import { ApiError } from '../../services/apiClient'
import { OpportunityDocumentsSection } from '../public-procurement/OpportunityDocumentsSection'
import { AdminAccountsPage } from './AdminAccountsPage'
import { AdminRolesPage } from './AdminRolesPage'

const createUser = vi.fn()
const updateRole = vi.fn()
const deleteRole = vi.fn()
const upload = vi.fn()

vi.mock('../../services/company', () => ({
  accountsApi: {
    references: () =>
      Promise.resolve({
        data: {
          platform_roles: [{ key: 'tiare_admin', name: 'Administrador TIARE' }],
          tenant_roles: [{ key: 'tenant_admin', name: 'Administrador de empresa' }],
          tenants: [{ public_id: 'T1', name: 'Empresa Ficticia', status: 'ACTIVE' }],
        },
      }),
    users: () =>
      Promise.resolve({
        data: [{ public_id: 'U1', name: 'Ana Ficticia', email: 'ana@ejemplo.test', is_active: true, platform_role: null, memberships: [{ tenant: { public_id: 'T1', name: 'Empresa Ficticia', status: 'ACTIVE' }, role: { key: 'tenant_admin', name: 'Administrador de empresa' }, is_active: true }], last_login_at: null, created_at: null }],
        meta: { request_id: 'r', self_public_id: 'U9', pagination: { page: 1, per_page: 20, total: 1, last_page: 1 } },
      }),
    createUser: (...a: unknown[]) => {
      createUser(...a)
      return Promise.resolve({ data: { message: 'Cuenta creada.', name: 'Luis Prueba', email: 'luis@ejemplo.test', initial_password: 'Kp7m-Qx3r-Vb9t-Hw2n-58' } })
    },
    roles: () =>
      Promise.resolve({
        data: [
          { key: 'tenant_read_only', name: 'Solo lectura', description: null, scope: 'tenant', is_system: true, locked: false, customized: false, deletable: true, permissions: ['suppliers.read'], users_count: 2 },
          { key: 'tenant_approver', name: 'Aprobador', description: null, scope: 'tenant', is_system: true, locked: false, customized: false, deletable: true, permissions: [], users_count: 0 },
          { key: 'customer_portal', name: 'Portal de cliente', description: null, scope: 'tenant', is_system: true, locked: true, customized: false, deletable: false, permissions: [], users_count: 0 },
          { key: 'tiare_admin', name: 'Administrador TIARE', description: null, scope: 'platform', is_system: true, locked: true, customized: false, deletable: false, permissions: [], users_count: 1 },
        ],
        meta: {
          request_id: 'r',
          permissions: [
            { key: 'suppliers.read', description: 'Ver proveedores', group: 'Proveedores', platform_only: false },
            { key: 'reports.read', description: 'Ver informes', group: 'Analítica e informes', platform_only: false },
            { key: 'platform.users.manage', description: 'Administrar cuentas', group: 'Administración TIARE', platform_only: true },
          ],
        },
      }),
    deleteRole: (...a: unknown[]) => {
      deleteRole(...a)
      return Promise.resolve({ data: { deleted: true, moved_accounts: 2 } })
    },
    updateRole: (...a: unknown[]) => {
      updateRole(...a)
      return Promise.resolve({ data: {} })
    },
  },
}))

vi.mock('../../services/opportunityFiles', () => ({
  opportunityFilesApi: {
    list: () =>
      Promise.resolve({
        data: {
          official: [{ id: '987', name: 'Bases técnicas.pdf' }],
          files: [{ public_id: 'F1', name: 'Bases', official_document_id: '987', file: { name: 'bases.pdf', mime_type: 'application/pdf', size_bytes: 2048 }, download_url: '/api/v1/public-procurement/files/F1/download', uploaded_by: 'Ana', uploaded_at: null, data_origin: 'tenant' }],
        },
        meta: { request_id: 'r', accepted: 'PDF', official_download_available: false, official_download_note: 'La API oficial no entrega el archivo.', portal_url: 'https://www.mercadopublico.cl/', can_upload: true, archive_url: '/api/v1/public-procurement/files-archive?source_type=compra_agil&code=1234-5-COT26' },
      }),
    upload: (...a: unknown[]) => {
      upload(...a)
      const file = a[2] as { file: File }
      return Promise.resolve({ data: file.file.name.startsWith('bases') ? { duplicate: false, official_document_id: '987', matched: { id: '987', score: 95 } } : { duplicate: false, official_document_id: null, matched: null } })
    },
    remove: vi.fn(),
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

describe('Mantenedor de cuentas y roles', () => {
  it('lista cuentas y crea una con empresa y rol', async () => {
    wrap(<AdminAccountsPage />)
    expect(await screen.findByText('Ana Ficticia', {}, { timeout: 5000 })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Nueva cuenta' }))
    const submit = await screen.findByRole('button', { name: 'Crear cuenta y generar contraseña' })
    fireEvent.change(screen.getByRole('textbox', { name: 'Nombre y apellido' }), { target: { value: 'Luis Prueba' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Correo' }), { target: { value: 'luis@ejemplo.test' } })
    expect(submit).toBeDisabled() // falta rol de plataforma o empresa
    fireEvent.mouseDown(screen.getByRole('combobox', { name: 'Empresa' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Empresa Ficticia' }))
    fireEvent.click(submit)
    await waitFor(() => expect(createUser).toHaveBeenCalledWith({ name: 'Luis Prueba', email: 'luis@ejemplo.test', credential: 'password', platform_role_key: null, memberships: [{ tenant_public_id: 'T1', role_key: 'tenant_admin' }] }))
    // La contraseña de inicio se muestra una sola vez tras crear la cuenta.
    expect(await screen.findByText('Kp7m-Qx3r-Vb9t-Hw2n-58')).toBeInTheDocument()
  })

  it('edita permisos de un rol y protege los roles administradores', async () => {
    wrap(<AdminRolesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Editar rol Solo lectura' }, { timeout: 5000 }))
    expect(screen.queryByText('Administrar cuentas')).not.toBeInTheDocument() // un rol de empresa no ve permisos de plataforma
    fireEvent.click(screen.getByRole('checkbox', { name: /Ver informes/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Guardar permisos' }))
    await waitFor(() => expect(updateRole).toHaveBeenCalledWith('tenant_read_only', expect.objectContaining({ permissions: ['suppliers.read', 'reports.read'] })))
  })

  it('elimina un rol en uso moviendo sus cuentas a otro rol del mismo alcance', async () => {
    wrap(<AdminRolesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Eliminar rol Solo lectura' }, { timeout: 5000 }))
    expect(screen.queryByRole('button', { name: 'Eliminar rol Administrador TIARE' })).not.toBeInTheDocument() // protegido
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/usan 2 cuentas/)).toBeInTheDocument()
    const confirm = within(dialog).getByRole('button', { name: 'Eliminar rol' })
    expect(confirm).toBeDisabled() // falta elegir el rol de reemplazo
    fireEvent.mouseDown(within(dialog).getByRole('combobox', { name: 'Mover las cuentas a' }))
    expect(screen.queryByRole('option', { name: 'Portal de cliente' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Administrador TIARE' })).not.toBeInTheDocument() // otro alcance
    fireEvent.click(await screen.findByRole('option', { name: 'Aprobador' }))
    fireEvent.click(confirm)
    await waitFor(() => expect(deleteRole).toHaveBeenCalledWith('tenant_read_only', 'tenant_approver'))
  })

  it('los roles administradores de plataforma son de solo lectura', async () => {
    wrap(<AdminRolesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Editar rol Administrador TIARE' }, { timeout: 5000 }))
    expect(await screen.findByText(/conservan todos los permisos/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Guardar permisos' })).toBeDisabled()
  })
})

describe('Documentos de Mercado Público', () => {
  it('muestra adjuntos oficiales, copias descargables y permite subir', async () => {
    wrap(<OpportunityDocumentsSection sourceType="compra_agil" code="1234-5-COT26" />)
    expect(await screen.findByText('Bases técnicas.pdf', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('Copia en TIARE')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Descargar Bases' })).toHaveAttribute('href', '/api/v1/public-procurement/files/F1/download')
    expect(screen.getByText(/La API oficial no entrega el archivo\./)).toBeInTheDocument()
    // Varios archivos a la vez: cada uno se sube y se informa a qué adjunto oficial quedó asociado.
    const other = new File(['%PDF'], 'cotizacion-propia.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByLabelText('Archivos a subir'), { target: { files: [new File(['%PDF'], 'bases-tecnicas.pdf', { type: 'application/pdf' }), other] } })
    await waitFor(() => expect(upload).toHaveBeenCalledTimes(2))
    expect(upload).toHaveBeenCalledWith('compra_agil', '1234-5-COT26', { file: other })
    expect(await screen.findByText('Asociado a: Bases técnicas.pdf')).toBeInTheDocument()
    expect(screen.getByText(/no coincide con un adjunto oficial/)).toBeInTheDocument()
    // Descargar todo en ZIP y todos los adjuntos con copia.
    expect(screen.getByRole('link', { name: /Descargar todo/ })).toHaveAttribute('href', expect.stringContaining('files-archive'))
    expect(screen.getByText('Todos los adjuntos oficiales tienen copia en TIARE.')).toBeInTheDocument()
  })
})

describe('Eliminar registros', () => {
  it('confirma antes de eliminar y muestra el motivo si hay historial', async () => {
    const onDeleted = vi.fn()
    const onDelete = vi.fn().mockRejectedValue(new ApiError(409, 'HAS_HISTORY', 'No se puede eliminar: el proveedor tiene historial (2 cotizaciones).'))
    wrap(<DeleteRecordButton noun="proveedor" name="Proveedor Ficticio" onDelete={onDelete} onDeleted={onDeleted} />)
    fireEvent.click(screen.getByRole('button', { name: 'Eliminar proveedor' }))
    expect(await screen.findByText(/no se puede deshacer/)).toBeInTheDocument()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Eliminar proveedor' }))
    expect(await screen.findByText(/tiene historial/)).toBeInTheDocument()
    expect(onDeleted).not.toHaveBeenCalled()
  })
})
