import { apiRequest } from './apiClient'
import type { CodeLabel } from '../types/crm'

// ---- Tipos (Fase 5: inventario y logística) ----

export interface Warehouse {
  public_id: string
  code: string
  name: string
  address: string | null
  region: { code: number; name: string } | null
  is_active: boolean
  items_count?: number
}

export interface StockItem {
  product: { public_id: string; name: string; sku: string | null; unit: string | null }
  warehouse: { public_id: string; name: string; code: string }
  quantity_on_hand: string
  min_quantity: string | null
  max_quantity: string | null
  location: string | null
  low_stock: boolean
  reorder_suggestion: string | null
}

export interface Movement {
  public_id: string
  type: { code: string; label: string; direction: 'in' | 'out' }
  product: { public_id: string; name: string; sku: string | null }
  warehouse: { public_id: string; name: string }
  quantity: string
  balance_after: string
  unit_cost: string | null
  lot: string | null
  expires_on: string | null
  reason: string | null
  reference_type: string | null
  created_by: string | null
  created_at: string | null
}

export interface Carrier {
  public_id: string
  name: string
  rut: string | null
  service_types: CodeLabel[]
  coverage_regions: Array<{ code: number; name: string }>
  contact_name: string | null
  contact_phone: string | null
  contact_email: string | null
  tracking_url_template: string | null
  is_active: boolean
  notes: string | null
}

export interface ShipmentSummary {
  public_id: string
  number: string
  status: CodeLabel
  carrier: { public_id: string; name: string } | null
  service_type: CodeLabel | null
  origin: string
  destination: string
  tracking_code: string | null
  tracking_url: string | null
  planned_delivery_on: string | null
  delivered_at: string | null
  late: boolean
  open_incidents: number
  order: { public_id: string; number: string } | null
}

export interface ShipmentDetail extends ShipmentSummary {
  destination_region: { code: number; name: string } | null
  contact_name: string | null
  contact_phone: string | null
  packages: number | null
  weight_kg: string | null
  planned_pickup_on: string | null
  picked_up_at: string | null
  estimated_cost: string | null
  actual_cost: string | null
  received_by_name: string | null
  delivery_notes: string | null
  has_evidence: boolean
  evidence_url: string | null
  notes: string | null
  events: Array<{ status: CodeLabel | null; description: string; location: string | null; occurred_at: string | null; user: string | null }>
  incidents: Array<{ public_id: string; type: CodeLabel; description: string; status: 'OPEN' | 'RESOLVED'; resolution: string | null; reported_by: string | null; created_at: string | null; resolved_at: string | null }>
  actions: { update: boolean; transitions: string[]; deliver: boolean; report_incident: boolean }
}

export interface ShipmentInput {
  order_public_id?: string | null
  carrier_public_id?: string | null
  service_type?: string | null
  origin?: string
  destination?: string
  destination_region?: number | null
  contact_name?: string
  contact_phone?: string
  tracking_code?: string
  packages?: string
  weight_kg?: string
  planned_pickup_on?: string
  planned_delivery_on?: string
  estimated_cost?: string
  actual_cost?: string
  notes?: string
}

type Page = { page?: number; per_page?: number }
type PagedMeta = { request_id: string; pagination?: { page: number; per_page: number; total: number; last_page: number } }
const id = (v: string) => encodeURIComponent(v)
const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== undefined)) as T

export const inventoryApi = {
  references: () => apiRequest<{ movement_types: CodeLabel[]; warehouses: Warehouse[] }>('GET', '/inventory/references'),
  warehouses: () => apiRequest<Warehouse[]>('GET', '/warehouses'),
  createWarehouse: (b: { code: string; name: string; address?: string; region_code?: number | null }) => apiRequest<Warehouse>('POST', '/warehouses', { body: clean(b) }),
  updateWarehouse: (w: string, b: Partial<{ name: string; address: string; region_code: number | null; is_active: boolean }>) => apiRequest<Warehouse>('PATCH', `/warehouses/${id(w)}`, { body: b }),
  stock: (f: Page & { q?: string; warehouse_public_id?: string; low_only?: boolean }) =>
    apiRequest<StockItem[], PagedMeta & { low_stock_count: number }>('GET', '/inventory/stock', { query: f }),
  movements: (f: Page & { warehouse_public_id?: string; type?: string }) => apiRequest<Movement[], PagedMeta>('GET', '/inventory/movements', { query: f }),
  move: (b: { type: string; product_public_id: string; warehouse_public_id: string; to_warehouse_public_id?: string; quantity: string; unit_cost?: string; lot?: string; expires_on?: string; reason?: string }) =>
    apiRequest<StockItem>('POST', '/inventory/movements', { body: clean(b) }),
  levels: (warehouse: string, product: string, b: { min_quantity: string | null; max_quantity: string | null; location: string | null }) =>
    apiRequest<StockItem>('PATCH', `/inventory/stock/${id(warehouse)}/${id(product)}`, { body: b }),
}

export const logisticsApi = {
  references: () => apiRequest<{ statuses: CodeLabel[]; service_types: CodeLabel[]; incident_types: CodeLabel[] }>('GET', '/logistics/references'),
  carriers: () => apiRequest<Carrier[]>('GET', '/carriers'),
  createCarrier: (b: Partial<Omit<Carrier, 'public_id' | 'service_types' | 'coverage_regions'>> & { service_types?: string[]; coverage_regions?: number[] }) =>
    apiRequest<Carrier>('POST', '/carriers', { body: clean(b) }),
  updateCarrier: (c: string, b: Partial<Omit<Carrier, 'public_id' | 'service_types' | 'coverage_regions'>> & { service_types?: string[]; coverage_regions?: number[] }) =>
    apiRequest<Carrier>('PATCH', `/carriers/${id(c)}`, { body: b }),
  shipments: (f: Page & { q?: string; status?: string; late?: boolean }) => apiRequest<ShipmentSummary[], PagedMeta & { status_counts: Record<string, number> }>('GET', '/shipments', { query: f }),
  shipment: (s: string) => apiRequest<ShipmentDetail>('GET', `/shipments/${id(s)}`),
  create: (b: ShipmentInput) => apiRequest<ShipmentDetail>('POST', '/shipments', { body: clean(b) }),
  update: (s: string, b: ShipmentInput) => apiRequest<ShipmentDetail>('PATCH', `/shipments/${id(s)}`, { body: b }),
  status: (s: string, b: { status: string; description?: string; location?: string }) => apiRequest<ShipmentDetail>('POST', `/shipments/${id(s)}/status`, { body: clean(b) }),
  note: (s: string, b: { description: string; location?: string }) => apiRequest<ShipmentDetail>('POST', `/shipments/${id(s)}/events`, { body: clean(b) }),
  incident: (s: string, b: { type: string; description: string }) => apiRequest<ShipmentDetail>('POST', `/shipments/${id(s)}/incidents`, { body: b }),
  resolve: (s: string, i: string, resolution: string) => apiRequest<ShipmentDetail>('POST', `/shipments/${id(s)}/incidents/${id(i)}/resolve`, { body: { resolution } }),
  deliver: (s: string, b: { received_by_name: string; delivery_notes?: string; evidence?: File | null }) => {
    const form = new FormData()
    form.append('received_by_name', b.received_by_name)
    if (b.delivery_notes) form.append('delivery_notes', b.delivery_notes)
    if (b.evidence) form.append('evidence', b.evidence)
    return apiRequest<ShipmentDetail>('POST', `/shipments/${id(s)}/deliver`, { form })
  },
}
