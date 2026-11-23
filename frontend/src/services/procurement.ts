import { apiRequest } from './apiClient'
import type {
  Indicators,
  InvoiceDto,
  OrderDetail,
  OrderSummary,
  ProcurementReferences,
  PurchaseRequestDetail,
  PurchaseRequestSummary,
  QuotationInput,
  RequestInput,
} from '../types/procurement'

const id = (v: string) => encodeURIComponent(v)
const pr = (v: string) => `/purchase-requests/${id(v)}`

export interface OrderInput {
  payer_name: string
  invoice_holder_name: string
  invoice_holder_rut?: string
  contractual_buyer: string
  receiver_name: string
  delivery_address?: string
  expected_delivery_on?: string
  notes?: string
}

export interface InvoiceInput {
  number: string
  document_type?: string
  issued_on: string
  due_on?: string
  net_amount: string
  tax_amount?: string
  notes?: string
}

export interface ReceiptInput {
  received_on: string
  document_reference?: string
  has_incident?: boolean
  notes?: string
  /** Fase 5: si se indica, lo recibido conforme entra al stock de esa bodega. */
  warehouse_public_id?: string
  items: Array<{ line_number: number; quantity_received: string; quantity_rejected?: string; incident_note?: string }>
}

export const procurementApi = {
  references: () => apiRequest<ProcurementReferences>('GET', '/procurement/references'),

  list: (f: { q?: string; status?: string; mine?: boolean; page?: number; per_page?: number }) =>
    apiRequest<PurchaseRequestSummary[], { request_id: string; pagination?: { page: number; per_page: number; total: number; last_page: number }; status_counts: Record<string, number> }>(
      'GET',
      '/purchase-requests',
      { query: f },
    ),
  get: (publicId: string) => apiRequest<PurchaseRequestDetail>('GET', pr(publicId)),
  create: (body: RequestInput) => apiRequest<PurchaseRequestDetail>('POST', '/purchase-requests', { body }),
  update: (publicId: string, body: Partial<RequestInput>) => apiRequest<PurchaseRequestDetail>('PATCH', pr(publicId), { body }),
  submit: (publicId: string) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/submit`),
  startQuoting: (publicId: string) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/start-quoting`),
  cancel: (publicId: string, reason: string) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/cancel`, { body: { reason } }),

  requestQuote: (publicId: string, body: { supplier_public_id: string; due_on?: string; notes?: string }) =>
    apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/quotation-requests`, { body }),
  registerQuotation: (publicId: string, body: QuotationInput) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/quotations`, { body }),
  discardQuotation: (publicId: string, quotationId: string) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/quotations/${id(quotationId)}/discard`),
  deleteQuotation: (publicId: string, quotationId: string) => apiRequest<PurchaseRequestDetail>('DELETE', `${pr(publicId)}/quotations/${id(quotationId)}`),
  compare: (publicId: string, weights?: { price: number; delivery: number; supplier: number }) =>
    apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/compare`, { body: { weights } }),
  propose: (publicId: string, body: { quotation_public_id: string; justification?: string }) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/propose`, { body }),
  decide: (publicId: string, body: { decision: 'APPROVED' | 'REJECTED'; comment?: string }) => apiRequest<PurchaseRequestDetail>('POST', `${pr(publicId)}/decision`, { body }),

  issueOrder: (publicId: string, body: OrderInput) => apiRequest<OrderDetail>('POST', `${pr(publicId)}/order`, { body }),
  orders: (f: { q?: string; status?: string; page?: number; per_page?: number }) => apiRequest<OrderSummary[]>('GET', '/purchase-orders', { query: f }),
  order: (publicId: string) => apiRequest<OrderDetail>('GET', `/purchase-orders/${id(publicId)}`),
  markSent: (publicId: string) => apiRequest<OrderDetail>('POST', `/purchase-orders/${id(publicId)}/sent`),
  cancelOrder: (publicId: string, reason: string) => apiRequest<OrderDetail>('POST', `/purchase-orders/${id(publicId)}/cancel`, { body: { reason } }),
  deleteOrder: (publicId: string) => apiRequest<{ deleted: boolean; message: string }>('DELETE', `/purchase-orders/${id(publicId)}`),
  receive: (publicId: string, body: ReceiptInput) => apiRequest<OrderDetail>('POST', `/purchase-orders/${id(publicId)}/receipts`, { body }),

  invoices: (f: { status?: string; q?: string; page?: number; per_page?: number }) => apiRequest<InvoiceDto[]>('GET', '/invoices', { query: f }),
  registerInvoice: (orderId: string, body: InvoiceInput) => apiRequest<OrderDetail>('POST', `/purchase-orders/${id(orderId)}/invoices`, { body }),
  informPayment: (invoiceId: string, body: { paid_on: string; paid_by_label: string; payment_reference?: string }) =>
    apiRequest<InvoiceDto>('POST', `/invoices/${id(invoiceId)}/payment`, { body }),
  indicators: (f: { from?: string; to?: string }) => apiRequest<Indicators>('GET', '/procurement/indicators', { query: f }),
}
