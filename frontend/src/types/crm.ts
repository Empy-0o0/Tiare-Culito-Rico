import type { SubscriptionSummary } from './api'

export interface CodeLabel {
  code: string
  label: string
}

export interface ContactDto {
  public_id: string
  full_name: string
  position: string | null
  email: string | null
  phone: string | null
  is_primary: boolean
}

export interface OrganizationDto {
  public_id: string
  legal_name: string
  trade_name: string | null
  rut: string | null
  kind: { code: string; label: string }
  segment: string | null
  email: string | null
  phone: string | null
  website: string | null
  address: string | null
  commune: string | null
  region: { code: number; name: string } | null
  notes: string | null
  contacts?: ContactDto[]
}

export interface CustomerDto {
  public_id: string
  data_origin: 'client'
  status: CodeLabel
  plan: string | null
  source: string | null
  owner: { public_id: string; name: string } | null
  next_action_at: string | null
  next_action_summary: string | null
  organization: OrganizationDto
}

export interface CategoryDto {
  public_id: string
  code: string | null
  name: string
  is_active: boolean
}

export interface SupplierDto {
  public_id: string
  data_origin: 'client'
  status: CodeLabel
  coverage_regions: Array<{ code: number; name: string }>
  payment_terms: string | null
  lead_time_days: number | null
  notes: string | null
  categories?: CategoryDto[]
  organization: OrganizationDto
  /** Solo en el listado. */
  evaluation?: { count: number; average: string | null }
  expired_documents?: number
  offerings_count?: number
}

export interface ProductDto {
  public_id: string
  kind: 'PRODUCT' | 'SERVICE'
  sku: string | null
  name: string
  description: string | null
  brand: string | null
  public_catalog_code: string | null
  is_active: boolean
  unit: { code: string; name: string } | null
  category: CategoryDto | null
}

export interface References {
  organization_kinds: CodeLabel[]
  customer_statuses: CodeLabel[]
  supplier_statuses: CodeLabel[]
  customer_plans: string[]
  units_of_measure: Array<{ code: string; name: string }>
}

export interface OrganizationInput {
  legal_name: string
  trade_name?: string
  rut?: string
  kind: string
  segment?: string
  email?: string
  phone?: string
  region_code?: number | null
}

export interface ContactInput {
  full_name: string
  position?: string
  email?: string
  phone?: string
}

export interface ProductInput {
  name: string
  kind: string
  unit_code: string
  sku?: string
  brand?: string
  category_public_id?: string | null
  public_catalog_code?: string
}

// ---- Fase 2b: evaluación y documentos de proveedores ----

export type CriterionKey = 'price_score' | 'quality_score' | 'delivery_score' | 'compliance_score' | 'service_score'

export interface SupplierEvaluationDto {
  public_id: string
  evaluated_on: string
  reference: string | null
  scores: Record<CriterionKey, number>
  overall: string
  had_incident: boolean
  comment: string | null
  evaluated_by: string | null
  created_at: string | null
  data_origin: 'client'
}

export interface EvaluationInput extends Record<CriterionKey, number> {
  evaluated_on: string
  reference?: string
  had_incident?: boolean
  comment?: string
}

export interface EvaluationsMeta {
  request_id: string
  summary: { count: number; overall: string | null; by_criterion: Record<CriterionKey, string | null>; incidents: number; last_evaluated_on: string | null }
  criteria?: Array<{ key: CriterionKey; label: string }>
  methodology?: string
}

export type DocumentValidity = 'valid' | 'expiring' | 'expired' | 'no_expiry'

export interface SupplierDocumentDto {
  public_id: string
  type: CodeLabel
  name: string
  issued_on: string | null
  expires_on: string | null
  validity: DocumentValidity
  file: { name: string; mime_type: string; size_bytes: number }
  download_url: string
  uploaded_by: string | null
  uploaded_at: string | null
  archived: boolean
  data_origin: 'client'
}

export interface DocumentsMeta {
  request_id: string
  types: CodeLabel[]
  expiring_days: number
  accepted: string
}

// ---- Fase 2b: configuración de la empresa ----

export interface CompanyDto {
  public_id: string
  name: string
  legal_name: string | null
  rut: string | null
  status: string
  is_demo: boolean
  members_count: number
  can_manage: boolean
  can_manage_users: boolean
  can_manage_tokens: boolean
  subscription: SubscriptionSummary | null
  seats: { used: number; max: number | null }
}

export interface ApiTokenDto {
  id: string
  name: string
  abilities: string[]
  last_used_at: string | null
  expires_at: string | null
  expired: boolean
  created_at: string | null
}

export interface AdminTenantDto {
  public_id: string
  name: string
  legal_name: string | null
  rut: string | null
  status: 'ACTIVE' | 'SUSPENDED'
  is_demo: boolean
  subscription: SubscriptionSummary | null
  seats: { used: number; max: number | null }
  created_at: string | null
}

export interface AdminTenantDetailDto extends AdminTenantDto {
  users: { public_id: string; name: string; email: string; role: string | null; is_active: boolean; last_login_at: string | null }[]
  subscriptions: SubscriptionSummary[]
}

export interface CompanyUserDto {
  public_id: string
  name: string
  must_change_password?: boolean
  /** null = la empresa puede generarle una contraseña de inicio; si no, el motivo. */
  initial_password_blocker?: string | null
  email: string
  role: { key: string | null; name: string }
  is_active: boolean
  account_active: boolean
  /** Monto máximo que puede aprobar (CLP). Null = sin límite. */
  approval_limit_clp: string | null
  last_login_at: string | null
  joined_at: string | null
}

export interface TenantRoleDto {
  key: string
  name: string
  permissions: string[]
}

/** Insumo o servicio que entrega un proveedor y su capacidad declarada (TD-026). */
export interface SupplierOfferingDto {
  public_id: string
  kind: { code: 'PRODUCT' | 'SERVICE'; label: string }
  name: string
  description: string | null
  category: { public_id: string; name: string } | null
  product: { public_id: string; name: string; sku: string | null } | null
  unit: { code: string; name: string } | null
  capacity: {
    quantity: string | null
    period: { code: string; label: string } | null
    min_order_quantity: string | null
    lead_time_days: number | null
    notes: string | null
    confirmed_on: string | null
    data_origin: 'supplier_declared'
  }
  is_active: boolean
  updated_at: string | null
}

export interface SupplierOfferingInput {
  kind: 'PRODUCT' | 'SERVICE'
  name: string
  description?: string | null
  category_public_id?: string | null
  unit_code?: string | null
  capacity_quantity?: number | null
  capacity_period?: string | null
  min_order_quantity?: number | null
  lead_time_days?: number | null
  capacity_notes?: string | null
  capacity_confirmed_on?: string | null
}

export interface OfferingsMeta {
  request_id: string
  kinds: { code: string; label: string }[]
  periods: { code: string; label: string }[]
  note: string
}
