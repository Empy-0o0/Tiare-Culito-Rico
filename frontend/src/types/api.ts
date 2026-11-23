// Contratos de la API interna TIARE (/api/v1). No reflejan el payload de Mercado Público.

export interface ApiMeta {
  request_id: string
  pagination?: { page: number; per_page: number; total: number; last_page: number }
  [key: string]: unknown
}

export interface ApiEnvelope<T, M = ApiMeta> {
  data: T
  meta: M
}

export interface ApiErrorBody {
  error: { code: string; message: string; details?: Record<string, string[]> }
  meta?: { request_id?: string }
}

export interface TenantSummary {
  public_id: string
  name: string
  is_demo: boolean
}

export interface PlanSummary {
  key: string
  name: string
  description: string | null
  modules: { key: string; name: string }[]
  max_users: number | null
}

export interface SubscriptionSummary {
  public_id: string
  status: 'active' | 'suspended' | 'cancelled'
  status_label: string
  starts_on: string | null
  ends_on: string | null
  plan: PlanSummary | null
}

export interface Profile {
  user: { public_id: string; name: string; email: string; platform_role: string | null }
  is_platform_admin: boolean
  /** Contraseña de inicio pendiente de cambio: la app solo permite ir a "Mi cuenta". */
  must_change_password: boolean
  password_changed_at: string | null
  /** Cuenta de portal de cliente/proveedor en la empresa activa: solo ve su propio perfil. */
  portal: { type: 'customer' | 'supplier'; name: string | null } | null
  current_tenant: (TenantSummary & { subscription: SubscriptionSummary | null }) | null
  tenants: TenantSummary[]
  permissions: string[]
}

export interface SourceInfo {
  name: string
  api: string
  portal_url: string
  data_origin: 'official'
  timezone: string
}

export interface SyncSummary {
  last_success_at: string | null
  last_attempt_at: string | null
  last_status: string | null
  last_item_fetched_at: string | null
}

export interface CompraAgil {
  public_id: string
  code: string
  source_type: string
  data_origin: 'official'
  title: string
  description?: string | null
  status: { code: string | null; label: string | null }
  call_number: number | null
  dates: {
    published_at: string | null
    closes_at: string | null
    first_call_closes_at: string | null
    second_call_closes_at: string | null
    cancelled_at: string | null
    source_updated_at: string | null
  }
  buyer: { name: string | null; rut: string | null; unit: string | null; region_code: number | null; region_name: string | null }
  budget: { currency: string | null; type: string | null; estimated: string | null; available: string | null; available_clp: string | null }
  offers_count: number | null
  delivery?: { address: string | null; days: number | null }
  purchase_order: { known: boolean; issued: boolean | null; source_id: string | null; code: string | null }
  reasons: { cancellation: string | null; deserted: string | null; selection: string | null }
  items?: Array<{ line_number: number; product_code: string | null; name: string | null; description: string | null; quantity: string | null; unit_of_measure: string | null }>
  documents?: Array<{ external_id: string; name: string | null }>
  quotes?: CompraAgilQuote[]
  freshness: { last_fetched_at: string | null; detail_fetched_at: string | null }
}

export interface CompraAgilListMeta extends ApiMeta {
  source: SourceInfo
  sync: SyncSummary
}

export interface CompraAgilDetailMeta extends ApiMeta {
  source: SourceInfo
  stale: boolean
  warning: string | null
}

export interface Catalogs {
  regions: Array<{ code: number; name: string }>
  compra_agil_statuses: Array<{ code: string; label: string }>
  tender_statuses: CatalogOption[]
  tender_status_filters: CatalogOption[]
  tender_types: CatalogOption[]
  order_statuses: CatalogOption[]
  order_status_filters: CatalogOption[]
}

export interface SyncRun {
  public_id: string
  source_api: string
  trigger: string
  status: string
  started_at: string | null
  finished_at: string | null
  pages_processed: number
  pages_total: number | null
  requests_used: number
  items_received: number
  items_created: number
  items_updated: number
  items_unchanged: number
  error_code: string | null
  error_summary: string | null
}

export interface SyncStatus {
  integration: { enabled: boolean; configured: boolean; scheduled_sync_enabled: boolean; sync_interval_minutes: number }
  compra_agil: {
    last_success_at: string | null
    last_attempt_at: string | null
    last_status: string | null
    /** TD-029: "publications" = solo publicaciones nuevas (eficiente); "changes" = todos los cambios. */
    mode?: 'publications' | 'changes'
    known_until?: string | null
    pending_resume?: boolean
    manual_cooldown_minutes?: number
  }
  quota: { v2: QuotaUsage; v1: QuotaUsage }
  runs: SyncRun[]
}

// ---- API legacy V1: licitaciones, órdenes de compra, organismos, proveedores ----

export interface V1SearchInfo {
  query: { date?: string; status?: string; buyer_code?: string; supplier_code?: string }
  fetched_at: string
  from_cache: boolean
  received: number
}

export interface QuotaUsage {
  api: string
  date: string
  used: number
  errors: number
  budget: number
  remaining: number | null
  blocked_until: string | null
  /** Hasta dónde puede llegar la sincronización automática; el resto queda para las personas (TD-029). */
  background_limit?: number
  interactive_reserve?: number
}

export interface V1ListMeta extends ApiMeta {
  source: SourceInfo
  search: V1SearchInfo | null
  quota: QuotaUsage
}

export interface V1DetailMeta extends ApiMeta {
  source: SourceInfo
  stale: boolean
  warning: string | null
}

export interface Tender {
  public_id: string
  code: string
  data_origin: 'official'
  detail_loaded: boolean
  title: string
  description?: string | null
  status: { code: number | null; label: string | null }
  type: { code: string | null; label: string | null }
  dates: { published_at: string | null; closes_at: string | null; awarded_at: string | null }
  buyer: { code: string | null; name: string | null; rut: string | null; unit: string | null; region_code: number | null; region_name: string | null }
  budget: { currency: string | null; estimation: string | null; estimated: string | null }
  delivery_address?: string | null
  details?: {
    type_label?: string | null
    is_public?: boolean | null
    open_call?: boolean | null
    stages?: number | null
    days_to_close?: number | null
    requires_comptroller_review?: boolean | null
    complaints_count?: number | null
    payment_modality?: string | null
    subcontracting_allowed?: boolean | null
    contract_duration?: string | null
    renewable?: boolean | null
    auto_extension?: boolean | null
    dates?: Record<string, string>
    award?: { act_type: string | null; act_number: string | null; act_date: string | null; awarded_suppliers: number | null; record_url: string | null } | null
  }
  items?: Array<{
    line_number: number
    product_code: string | null
    category: string | null
    name: string | null
    description: string | null
    quantity: string | null
    unit_of_measure: string | null
    award: { supplier_rut: string | null; supplier_name: string | null; quantity: string | null; unit_price: string | null } | null
  }>
  freshness: { last_fetched_at: string | null; detail_fetched_at: string | null }
}

export interface PublicPurchaseOrder {
  public_id: string
  code: string
  data_origin: 'official'
  detail_loaded: boolean
  title: string | null
  description?: string | null
  status: { code: number | null; label: string | null }
  supplier_status: string | null
  tender_code: string | null
  type: { code: string | null; label: string | null }
  currency: string | null
  amounts: { net: string | null; tax: string | null; tax_rate: string | null; total: string | null }
  dates: { issued_at: string | null; sent_at: string | null; accepted_at: string | null; cancelled_at: string | null; source_updated_at: string | null }
  buyer: { code: string | null; name: string | null; unit_rut: string | null; unit: string | null; region: string | null }
  supplier: { code: string | null; name: string | null; rut: string | null; region: string | null }
  details?: Record<string, string | number | null>
  items?: Array<{
    line_number: number
    product_code: string | null
    category: string | null
    buyer_spec: string | null
    supplier_spec: string | null
    quantity: string | null
    unit: string | null
    currency: string | null
    unit_net_price: string | null
    total: string | null
  }>
  freshness: { last_fetched_at: string | null; detail_fetched_at: string | null }
}

export interface PublicBuyer {
  code: string
  name: string
}

export interface BuyersMeta extends ApiMeta {
  source: SourceInfo
  last_fetched_at: string | null
  stale: boolean
  refreshed: boolean
}

export interface SupplierLookupResult {
  code: string
  rut: string
  name: string
  last_fetched_at: string | null
}

export interface CompraAgilQuote {
  supplier_rut: string | null
  supplier_name: string | null
  is_small_business: boolean | null
  active: boolean | null
  net_amount: string | null
  tax_amount: string | null
  shipping_amount: string | null
  total_amount: string | null
}

export interface CatalogOption {
  code: string
  label: string
}
