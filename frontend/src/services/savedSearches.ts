import { apiRequest } from './apiClient'

export interface SearchCriteria {
  q?: string
  regions?: number[]
  min_amount?: number
  max_amount?: number
  buyer_rut?: string
  open_only?: boolean
  use_catalog?: boolean
  source_types?: Array<'compra_agil' | 'licitacion'>
}

export interface SavedSearch {
  public_id: string
  name: string
  criteria: SearchCriteria
  regions: Array<{ code: number; name: string | null }>
  alerts_enabled: boolean
  is_active: boolean
  matches_count: number | null
  new_count: number | null
  last_run_at: string | null
}

export interface RunResult {
  new: number
  total: number
  catalog_empty: boolean
}

export interface OpportunityMatch {
  public_id: string
  status: { code: 'NEW' | 'SEEN' | 'DISMISSED'; label: string }
  reasons: {
    words?: string[]
    catalog?: Array<{ requested: string; catalog: string[] }>
    region?: string | null
    amount_clp?: string | null
  }
  search: { public_id: string; name: string }
  first_matched_at: string | null
  opportunity: {
    source_type: 'compra_agil' | 'licitacion'
    code: string
    has_detail: boolean
    title: string
    status: { code: string | null; label: string | null }
    buyer: string | null
    region: string | null
    closes_at: string | null
    available_amount_clp: string | null
    last_fetched_at: string | null
    data_origin: 'official'
  }
}

export interface PublicAlertItem {
  public_id: string
  type: { code: 'NEW_MATCHES' | 'CLOSING_SOON'; label: string }
  title: string
  body: string | null
  codes: string[]
  source_type: 'compra_agil' | 'licitacion' | null
  search: { public_id: string; name: string } | null
  read: boolean
  created_at: string | null
}

type Pagination = {
  page: number
  per_page: number
  total: number
  last_page: number
}
const id = (v: string) => encodeURIComponent(v)
const BASE = '/public-procurement'

export const savedSearchesApi = {
  list: () => apiRequest<SavedSearch[], { request_id: string; max_searches: number; scheduler_enabled: boolean }>('GET', `${BASE}/saved-searches`),
  create: (b: { name: string; criteria: SearchCriteria; alerts_enabled: boolean }) => apiRequest<SavedSearch, { request_id: string; run?: RunResult }>('POST', `${BASE}/saved-searches`, { body: b }),
  update: (
    s: string,
    b: Partial<{
      name: string
      criteria: SearchCriteria
      alerts_enabled: boolean
      is_active: boolean
    }>,
  ) => apiRequest<SavedSearch, { request_id: string; run?: RunResult }>('PATCH', `${BASE}/saved-searches/${id(s)}`, { body: b }),
  run: (s: string) => apiRequest<SavedSearch, { request_id: string; run: RunResult }>('POST', `${BASE}/saved-searches/${id(s)}/run`),
  matches: (f: { search?: string; status?: string; open_only?: boolean; page?: number; per_page?: number }) =>
    apiRequest<
      OpportunityMatch[],
      {
        request_id: string
        pagination?: Pagination
        counts: Record<string, number>
      }
    >('GET', `${BASE}/matches`, { query: f }),
  setMatchStatus: (m: string, status: OpportunityMatch['status']['code']) =>
    apiRequest<OpportunityMatch>('PATCH', `${BASE}/matches/${id(m)}`, {
      body: { status },
    }),
  alerts: () => apiRequest<PublicAlertItem[], { request_id: string; unread_count: number }>('GET', `${BASE}/alerts`),
  markRead: (ids?: string[]) =>
    apiRequest<{ marked: number }>('POST', `${BASE}/alerts/read`, {
      body: { alert_public_ids: ids },
    }),
}
