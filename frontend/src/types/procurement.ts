// Contratos de compras privadas (/api/v1/purchase-requests, /purchase-orders).
import type { CodeLabel } from './crm'

export interface RequestItem {
  line_number: number
  product_public_id: string | null
  description: string
  quantity: string
  unit_code: string
  estimated_unit_price: string | null
  notes: string | null
}

export interface PurchaseRequestSummary {
  public_id: string
  number: string
  title: string
  status: CodeLabel
  priority: CodeLabel
  needed_by: string | null
  currency: string
  estimated_total: string | null
  selected_total_clp: string | null
  items_count: number
  quotations_count: number | null
  requested_by: string | null
  customer: string | null
  created_at: string | null
  data_origin: 'client'
}

export interface QuotationItem {
  line_number: number
  quantity: string
  unit_price: string
  line_total: string
  offered_brand: string | null
}

export interface Quotation {
  public_id: string
  supplier: { public_id: string; name: string }
  reference: string | null
  status: 'RECEIVED' | 'SELECTED' | 'DISCARDED'
  currency: string
  exchange_rate_to_clp: string | null
  exchange_rate_source: string | null
  tax_rate: string
  prices_include_tax: boolean
  shipping_cost: string
  subtotal_net: string
  tax_amount: string
  total: string
  total_clp: string
  delivery_days: number | null
  warranty_months: number | null
  payment_terms: string | null
  valid_until: string | null
  expired: boolean
  received_on: string | null
  notes: string | null
  items: QuotationItem[]
}

export interface ComparisonRow {
  quotation_public_id: string
  supplier: string
  currency: string
  total: string
  total_clp: string
  delivery_days: number | null
  warranty_months: number | null
  valid_until: string | null
  supplier_rating: string | null
  eligible: boolean
  excluded_reasons: string[]
  scores: { price: number; delivery: number; supplier: number; total: number } | null
}

export interface Comparison {
  public_id: string
  methodology: { weights: { price: number; delivery: number; supplier: number }; formula: string; assumptions: string[]; generated_at: string; request_version: number }
  results: ComparisonRow[]
  recommended_quotation_public_id: string | null
  generated_by: string | null
  created_at: string | null
}

export interface Approval {
  public_id: string
  decision: 'APPROVED' | 'REJECTED'
  comment: string | null
  user: string | null
  request_version: number
  amount_clp: string | null
  created_at: string | null
}

export interface OrderSummary {
  public_id: string
  number: string
  status: CodeLabel
  supplier: { public_id: string; name: string }
  currency: string
  total: string
  issued_at: string | null
  expected_delivery_on: string | null
  request: { public_id: string; number: string; title: string }
}

export interface RequestActions {
  edit: boolean
  submit: boolean
  start_quoting: boolean
  manage_quotations: boolean
  /** Eliminar cotizaciones registradas por error (solo en cotización). */
  delete_quotations: boolean
  compare: boolean
  propose: boolean
  decide: boolean
  decide_blocked_reason: string | null
  issue_order: boolean
  cancel: boolean
}

export interface PurchaseRequestDetail extends PurchaseRequestSummary {
  description: string | null
  cost_center: string | null
  delivery_address: string | null
  customer_public_id: string | null
  version: number
  proposed_by: string | null
  proposal_justification: string | null
  selected_quotation_public_id: string | null
  dates: { submitted_at: string | null; proposed_at: string | null; decided_at: string | null; cancelled_at: string | null }
  cancel_reason: string | null
  items: RequestItem[]
  quotation_requests: Array<{ public_id: string; supplier: { public_id: string; name: string }; status: string; due_on: string | null; notes: string | null }>
  quotations: Quotation[]
  comparison: Comparison | null
  approvals: Approval[]
  order: OrderSummary | null
  actions: RequestActions
}

export interface RequestInput {
  title: string
  description?: string
  customer_public_id?: string | null
  cost_center?: string
  delivery_address?: string
  needed_by?: string
  priority?: string
  items: Array<{ product_public_id?: string | null; description?: string; quantity: number | string; unit_code?: string; estimated_unit_price?: number | string | null; notes?: string }>
}

export interface QuotationInput {
  supplier_public_id: string
  reference?: string
  currency?: string
  exchange_rate_to_clp?: string
  exchange_rate_source?: string
  tax_rate?: string
  prices_include_tax?: boolean
  shipping_cost?: string
  delivery_days?: string
  warranty_months?: string
  payment_terms?: string
  valid_until?: string
  notes?: string
  items: Array<{ line_number: number; unit_price: string; offered_brand?: string }>
}

export interface OrderDetail extends OrderSummary {
  exchange_rate_to_clp: string | null
  subtotal_net: string
  shipping_cost: string
  tax_amount: string
  responsibles: { payer_name: string; invoice_holder_name: string; invoice_holder_rut: string | null; contractual_buyer: string; receiver_name: string }
  delivery_address: string | null
  payment_terms: string | null
  notes: string | null
  issued_by: string | null
  sent_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  items: Array<{ line_number: number; description: string; unit_code: string; quantity: string; unit_price: string; line_total: string; received_quantity: string }>
  receipts: Array<{
    public_id: string
    received_on: string | null
    document_reference: string | null
    has_incident: boolean
    notes: string | null
    received_by: string | null
    items: Array<{ line_number: number; quantity_received: string; quantity_rejected: string; incident_note: string | null }>
  }>
  invoices: InvoiceDto[]
  invoicing: Invoicing
  actions: { mark_sent: boolean; receive: boolean; cancel: boolean; delete: boolean; register_invoice: boolean; inform_payment: boolean }
}

export interface ProcurementReferences {
  statuses: CodeLabel[]
  order_statuses: CodeLabel[]
  priorities: CodeLabel[]
  default_weights: { price: number; delivery: number; supplier: number }
  default_tax_rate: number
}

// ---- Pasos 11–12: facturas e indicadores ----

export interface InvoiceDto {
  public_id: string
  number: string
  document_type: { code: string; label: string }
  issued_on: string
  due_on: string | null
  currency: string
  net_amount: string
  tax_amount: string
  total: string
  status: 'REGISTERED' | 'PAID'
  overdue: boolean
  paid_on: string | null
  payment_reference: string | null
  paid_by_label: string | null
  notes: string | null
  supplier: string | null
  order: { public_id: string; number: string } | null
  data_origin: 'client'
}

export interface Invoicing {
  invoiced_total: string
  order_total: string
  difference: string
  matches: boolean
  paid_total: string
}

export interface IndicatorMetric {
  key: string
  label: string
  value: string | number | null
  unit: 'CLP' | 'days' | '%' | 'count'
  n: number
  formula: string
  assumptions: string[]
  percent?: number | null
  average?: number | null
}

export interface Indicators {
  period: { from: string; to: string; timezone: string }
  generated_at: string
  currency: string
  source: string
  metrics: IndicatorMetric[]
  pipeline: Record<string, number>
  invoices: { pending_count: number; pending_amount_clp: string; overdue_count: number; orders_with_difference: number; note: string }
}
