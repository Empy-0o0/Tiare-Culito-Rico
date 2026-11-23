import { apiRequest } from './apiClient'

export type PartyType = 'customer' | 'supplier'

export interface PortalAccessDto {
  public_id: string
  type: PartyType
  user: { name: string; email: string }
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  created_at: string | null
  shared_account: boolean
}

export interface PortalOrganization {
  legal_name: string | null
  trade_name: string | null
  rut: string | null
  email: string | null
  phone: string | null
  address: string | null
  commune: string | null
  region: string | null
  contacts: { full_name: string; position: string | null; email: string | null; phone: string | null }[]
}

export interface PortalProfile {
  type: PartyType
  company: { name: string }
  organization: PortalOrganization
  status: { code: string | null; label: string | null }
  // Cliente
  plan?: string | null
  purchase_requests?: { number: string; title: string; status: { code: string; label: string }; needed_by: string | null; currency: string; estimated_total: string | null; created_at: string | null }[]
  // Proveedor
  categories?: string[]
  coverage_regions?: string[]
  payment_terms?: string | null
  lead_time_days?: number | null
  offerings?: { kind: string; name: string; unit: string | null; capacity_quantity: string | null; capacity_period: string | null; min_order_quantity: string | null; lead_time_days: number | null; capacity_confirmed_on: string | null }[]
  documents?: { type: string; name: string; expires_on: string | null; validity: string }[]
  quotation_requests?: { request_number: string | null; title: string | null; status: string; due_on: string | null; created_at: string | null }[]
  purchase_orders?: { number: string; status: { code: string; label: string }; currency: string; total: string; issued_at: string | null; expected_delivery_on: string | null }[]
}

const base = (type: PartyType, id: string) => `/${type === 'customer' ? 'customers' : 'suppliers'}/${encodeURIComponent(id)}/portal-access`

/** Portal propio (cuenta de cliente/proveedor) y gestión de accesos desde la ficha (TD-028). */
export const portalApi = {
  profile: () => apiRequest<PortalProfile, { request_id: string; note: string }>('GET', '/portal/profile'),
  access: (type: PartyType, id: string) => apiRequest<PortalAccessDto | null>('GET', base(type, id)),
  grant: (type: PartyType, id: string, body: { email: string; name?: string }) =>
    apiRequest<PortalAccessDto & { initial_password: string | null; message: string }>('POST', base(type, id), { body }),
  password: (type: PartyType, id: string) => apiRequest<{ initial_password: string; message: string }>('POST', `${base(type, id)}/password`),
  setActive: (type: PartyType, id: string, is_active: boolean) => apiRequest<PortalAccessDto>('PATCH', base(type, id), { body: { is_active } }),
  revoke: (type: PartyType, id: string) => apiRequest<{ revoked: boolean }>('DELETE', base(type, id)),
}

/** Lo que devuelve el alta de cliente/proveedor cuando se pidió crear su acceso. */
export type CreatedPortalMeta = { request_id: string; portal_access?: PortalAccessDto & { initial_password: string | null } }
