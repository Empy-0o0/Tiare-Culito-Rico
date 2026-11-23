import { apiRequest } from './apiClient'
import type {
  BuyersMeta,
  Catalogs,
  CompraAgil,
  CompraAgilDetailMeta,
  CompraAgilListMeta,
  Profile,
  PublicBuyer,
  PublicPurchaseOrder,
  SupplierLookupResult,
  SyncStatus,
  Tender,
  V1DetailMeta,
  V1ListMeta,
} from '../types/api'

export interface CompraAgilFilters {
  q?: string
  status?: string[]
  region?: number[]
  open_only?: boolean
  sort?: 'published_at' | 'closes_at' | 'source_updated_at' | 'available_amount_clp'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

/**
 * Filtros de licitaciones / órdenes de compra.
 * `date`, `source_status`, `buyer_code` y `supplier_code` disparan una consulta a Mercado Público
 * (una solicitud, cacheada en el servidor); el resto filtra lo ya guardado.
 */
export interface V1Filters {
  q?: string
  status?: number
  type?: string
  region?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
  date?: string
  source_status?: string
  buyer_code?: string
  supplier_code?: string
  fetch?: boolean
  refresh?: boolean
}

export const authApi = {
  login: (email: string, password: string) => apiRequest<Profile>('POST', '/auth/login', { body: { email, password } }),
  logout: () => apiRequest<{ logged_out: boolean }>('POST', '/auth/logout'),
  me: () => apiRequest<Profile>('GET', '/auth/me'),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    apiRequest<{ message: string; password_changed_at: string | null }>('PUT', '/auth/password', { body }),
}

export const publicProcurementApi = {
  catalogs: () => apiRequest<Catalogs>('GET', '/public-procurement/catalogs'),
  listCompraAgil: (filters: CompraAgilFilters) =>
    apiRequest<CompraAgil[], CompraAgilListMeta>('GET', '/public-procurement/compra-agil', { query: { ...filters } }),
  getCompraAgil: (code: string, refresh = false) =>
    apiRequest<CompraAgil, CompraAgilDetailMeta>('GET', `/public-procurement/compra-agil/${encodeURIComponent(code)}`, {
      query: { refresh },
    }),
  syncStatus: () => apiRequest<SyncStatus>('GET', '/public-procurement/sync-status'),
  triggerSync: () => apiRequest<{ queued: boolean; skipped?: boolean; message: string }>('POST', '/public-procurement/sync'),

  listTenders: (filters: V1Filters) => apiRequest<Tender[], V1ListMeta>('GET', '/public-procurement/tenders', { query: { ...filters } }),
  getTender: (code: string, refresh = false) =>
    apiRequest<Tender, V1DetailMeta>('GET', `/public-procurement/tenders/${encodeURIComponent(code)}`, { query: { refresh } }),

  listPurchaseOrders: (filters: V1Filters) =>
    apiRequest<PublicPurchaseOrder[], V1ListMeta>('GET', '/public-procurement/purchase-orders', { query: { ...filters } }),
  getPurchaseOrder: (code: string, refresh = false) =>
    apiRequest<PublicPurchaseOrder, V1DetailMeta>('GET', `/public-procurement/purchase-orders/${encodeURIComponent(code)}`, {
      query: { refresh },
    }),

  buyers: (f: { q?: string; page?: number; per_page?: number; refresh?: boolean }) =>
    apiRequest<PublicBuyer[], BuyersMeta>('GET', '/public-procurement/buyers', { query: f }),
  lookupSupplier: (rut: string) =>
    apiRequest<SupplierLookupResult, V1DetailMeta & { cached: boolean }>('GET', '/public-procurement/suppliers/lookup', { query: { rut } }),
}
