import { apiRequest } from './apiClient'
import type { ApiMeta, PlanSummary } from '../types/api'
import type { AdminTenantDetailDto, AdminTenantDto, ApiTokenDto, CompanyDto, CompanyUserDto, TenantRoleDto } from '../types/crm'

export const companyApi = {
  get: () => apiRequest<CompanyDto>('GET', '/company'),
  update: (body: { name?: string; legal_name?: string | null; rut?: string | null }) => apiRequest<CompanyDto>('PATCH', '/company', { body }),
  users: () => apiRequest<CompanyUserDto[]>('GET', '/company/users'),
  roles: () => apiRequest<TenantRoleDto[]>('GET', '/company/roles'),
  invite: (body: { name: string; email: string; role_key: string; credential?: 'link' | 'password' }) =>
    apiRequest<{ public_id: string; new_account: boolean; link_sent: boolean; initial_password: string | null; message: string }>('POST', '/company/users', { body }),
  initialPassword: (publicId: string) => apiRequest<{ initial_password: string; message: string }>('POST', `/company/users/${encodeURIComponent(publicId)}/initial-password`),
  updateUser: (publicId: string, body: { role_key?: string; is_active?: boolean; approval_limit_clp?: number | null }) =>
    apiRequest<CompanyUserDto[]>('PATCH', `/company/users/${encodeURIComponent(publicId)}`, { body }),
  /** Quita a la persona de la empresa; si no le queda otro acceso, su cuenta se elimina (el historial conserva su nombre). */
  removeUser: (publicId: string) => apiRequest<CompanyUserDto[]>('DELETE', `/company/users/${encodeURIComponent(publicId)}`),
}

/** Tokens API de la empresa. El valor completo solo llega una vez, en la respuesta de creación. */
export const tokensApi = {
  list: () => apiRequest<ApiTokenDto[], ApiMeta & { available_abilities: string[]; expiry_days: number[] }>('GET', '/auth/tokens'),
  create: (body: { name: string; abilities: string[]; expires_in_days: number }) =>
    apiRequest<{ token: ApiTokenDto; plain_text_token: string }>('POST', '/auth/tokens', { body }),
  revoke: (id: string) => apiRequest<{ revoked: boolean }>('DELETE', `/auth/tokens/${encodeURIComponent(id)}`),
}

/** Administración TIARE (solo rol de plataforma). */
export const adminApi = {
  plans: () => apiRequest<PlanSummary[], ApiMeta & { modules: { key: string; name: string }[] }>('GET', '/admin/plans'),
  tenants: (query: { q?: string; status?: string; page?: number }) => apiRequest<AdminTenantDto[]>('GET', '/admin/tenants', { query }),
  tenant: (id: string) => apiRequest<AdminTenantDetailDto>('GET', `/admin/tenants/${encodeURIComponent(id)}`),
  create: (body: { name: string; legal_name?: string | null; rut?: string | null; plan_key: string; starts_on?: string | null; ends_on?: string | null; admin_name: string; admin_email: string }) =>
    apiRequest<AdminTenantDto & { message: string; link_sent: boolean }>('POST', '/admin/tenants', { body }),
  setStatus: (id: string, status: 'ACTIVE' | 'SUSPENDED') => apiRequest<AdminTenantDto>('PATCH', `/admin/tenants/${encodeURIComponent(id)}`, { body: { status } }),
  subscribe: (id: string, body: { plan_key: string; starts_on?: string | null; ends_on?: string | null; notes?: string | null }) =>
    apiRequest<AdminTenantDto>('POST', `/admin/tenants/${encodeURIComponent(id)}/subscription`, { body }),
}

export interface AdminUserDto {
  public_id: string
  name: string
  email: string
  is_active: boolean
  must_change_password: boolean
  password_changed_at: string | null
  platform_role: { key: string; name: string } | null
  memberships: { tenant: { public_id: string; name: string; status: string }; role: { key: string | null; name: string | null }; is_active: boolean }[]
  last_login_at: string | null
  created_at: string | null
}

export interface AdminRoleDto {
  key: string
  name: string
  description: string | null
  scope: 'platform' | 'tenant'
  is_system: boolean
  locked: boolean
  customized: boolean
  /** false: rol protegido (administración, portales y administrador de empresa). */
  deletable: boolean
  permissions: string[]
  users_count: number
}

export interface PermissionDto {
  key: string
  description: string | null
  group: string
  platform_only: boolean
}

export interface AccountReferences {
  platform_roles: { key: string; name: string }[]
  tenant_roles: { key: string; name: string }[]
  tenants: { public_id: string; name: string; status: string }[]
}

/** Mantenedor de cuentas y roles (solo administrador TIARE). */
export const accountsApi = {
  references: () => apiRequest<AccountReferences>('GET', '/admin/account-references'),
  users: (query: { q?: string; status?: string; role?: string; tenant?: string; page?: number }) => apiRequest<AdminUserDto[], ApiMeta & { self_public_id: string }>('GET', '/admin/users', { query }),
  createUser: (body: { name: string; email: string; credential?: 'link' | 'password'; platform_role_key?: string | null; memberships?: { tenant_public_id: string; role_key: string }[] }) =>
    apiRequest<AdminUserDto & { message: string; link_sent: boolean; initial_password: string | null }>('POST', '/admin/users', { body }),
  initialPassword: (id: string) => apiRequest<{ initial_password: string; message: string }>('POST', `/admin/users/${encodeURIComponent(id)}/initial-password`),
  updateUser: (id: string, body: { name?: string; email?: string; is_active?: boolean; platform_role_key?: string | null }) => apiRequest<AdminUserDto>('PATCH', `/admin/users/${encodeURIComponent(id)}`, { body }),
  saveMembership: (id: string, tenant: string, body: { role_key: string; is_active?: boolean }) =>
    apiRequest<AdminUserDto>('PUT', `/admin/users/${encodeURIComponent(id)}/memberships/${encodeURIComponent(tenant)}`, { body }),
  removeMembership: (id: string, tenant: string) => apiRequest<AdminUserDto>('DELETE', `/admin/users/${encodeURIComponent(id)}/memberships/${encodeURIComponent(tenant)}`),
  passwordLink: (id: string) => apiRequest<{ link_sent: boolean; message: string }>('POST', `/admin/users/${encodeURIComponent(id)}/password-link`),
  roles: () => apiRequest<AdminRoleDto[], ApiMeta & { permissions: PermissionDto[] }>('GET', '/admin/roles'),
  createRole: (body: { name: string; scope: 'platform' | 'tenant'; description?: string | null; permissions: string[] }) => apiRequest<AdminRoleDto>('POST', '/admin/roles', { body }),
  updateRole: (key: string, body: { name?: string; description?: string | null; permissions?: string[] }) => apiRequest<AdminRoleDto>('PATCH', `/admin/roles/${encodeURIComponent(key)}`, { body }),
  resetRole: (key: string) => apiRequest<AdminRoleDto>('POST', `/admin/roles/${encodeURIComponent(key)}/reset`),
  /** Si el rol tiene cuentas, se mueven a replacement_key (mismo alcance) antes de eliminarlo. */
  deleteRole: (key: string, replacementKey?: string | null) =>
    apiRequest<{ deleted: boolean; moved_accounts: number }>('DELETE', `/admin/roles/${encodeURIComponent(key)}`, { body: replacementKey ? { replacement_key: replacementKey } : {} }),
  deleteUser: (id: string) => apiRequest<{ deleted: boolean; message: string }>('DELETE', `/admin/users/${encodeURIComponent(id)}`),
}

export const passwordApi = {
  forgot: (email: string) => apiRequest<{ message: string }>('POST', '/auth/forgot-password', { body: { email } }),
  reset: (body: { token: string; email: string; password: string; password_confirmation: string }) =>
    apiRequest<{ message: string }>('POST', '/auth/reset-password', { body }),
}
