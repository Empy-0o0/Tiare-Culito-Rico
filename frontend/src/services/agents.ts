import { apiRequest } from './apiClient'

// ---- Tipos (Fase 6: agentes IA) ----

export type AgentId = 'tender_intelligence_agent' | 'cost_intelligence_agent'

export interface AgentInfo {
  id: AgentId
  name: string
  prompt_version: string
  purpose: string
  subject_type: 'compra_agil' | 'purchase_request'
  data_used: string[]
  limits: string[]
  can_run: boolean
}

export interface AgentCatalog {
  model: {
    available: boolean
    provider: string | null
    name: string | null
    daily_limit: number
    remaining_today: number
  }
  agents: AgentInfo[]
}

export interface Alert {
  level: 'info' | 'warning' | 'urgent'
  text: string
}

export interface AgentRunSummary {
  public_id: string
  agent: { id: AgentId; name: string; prompt_version: string }
  subject: { type: string; ref: string; label: string | null }
  mode: 'deterministic' | 'llm'
  status: 'COMPLETED' | 'PARTIAL' | 'FAILED'
  model: string | null
  review_status: 'PENDING' | 'USEFUL' | 'DISCARDED'
  requested_by: string | null
  created_at: string | null
}

export interface TenderFacts {
  open: boolean
  hours_to_close: number | null
  closes_at: string | null
  items_count: number
  documents_count: number
  budget: {
    currency: string | null
    available: string | null
    available_clp: string | null
    estimated: string | null
  }
  catalog_matches: Array<{ requested: string; catalog: string[] }>
  alerts: Alert[]
  checklist: string[]
  source: {
    name: string
    code: string
    last_fetched_at: string | null
    portal_url: string
  }
}

export interface CostFacts {
  quotations: number
  comparable: number
  cheapest: { supplier: string; total_clp: string } | null
  spread: { amount_clp: string; percent: number } | null
  cheapest_vs_estimate_percent: number | null
  comparison: {
    recommended_supplier: string | null
    recommended_is_cheapest: boolean
    stale: boolean
  } | null
  alerts: Alert[]
  notes: string[]
}

type Severity = 'baja' | 'media' | 'alta'

export interface TenderAnalysis {
  summary: string
  observable_requirements: Array<{ requirement: string; source: string }>
  risks: Array<{ risk: string; severity: Severity }>
  questions_for_buyer: string[]
  checklist_additions: string[]
  catalog_fit: {
    level: 'alto' | 'medio' | 'bajo' | 'sin_datos'
    rationale: string
    matched_products: string[]
  }
  uncertainty: string
}

export interface CostAnalysis {
  summary: string
  quotation_notes: Array<{
    supplier: string
    strengths: string[]
    weaknesses: string[]
  }>
  risks: Array<{ risk: string; severity: Severity }>
  questions_for_suppliers: Array<{ supplier: string; question: string }>
  negotiation_points: string[]
  data_gaps: string[]
  uncertainty: string
}

export interface AgentRunDetail extends AgentRunSummary {
  facts: TenderFacts | CostFacts
  analysis: TenderAnalysis | CostAnalysis | null
  usage: {
    input_tokens: number | null
    output_tokens: number | null
    latency_ms: number | null
    provider: string | null
  }
  error: { code: string; message: string } | null
  input_hash: string
  review: {
    status: AgentRunSummary['review_status']
    by: string | null
    at: string | null
    comment: string | null
  }
  disclaimer: string
}

type PagedMeta = {
  request_id: string
  pagination?: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}
const id = (v: string) => encodeURIComponent(v)

export const agentsApi = {
  catalog: () => apiRequest<AgentCatalog>('GET', '/agents'),
  runs: (f: { agent?: string; subject_type?: string; subject_ref?: string; review_status?: string; page?: number; per_page?: number }) =>
    apiRequest<AgentRunSummary[], PagedMeta>('GET', '/agent-runs', {
      query: f,
    }),
  run: (runId: string) => apiRequest<AgentRunDetail>('GET', `/agent-runs/${id(runId)}`),
  start: (agent: AgentId, subjectRef: string, useModel: boolean) =>
    apiRequest<AgentRunDetail>('POST', `/agents/${id(agent)}/runs`, {
      body: { subject_ref: subjectRef, use_model: useModel },
    }),
  review: (runId: string, verdict: 'USEFUL' | 'DISCARDED', comment?: string) =>
    apiRequest<AgentRunDetail>('POST', `/agent-runs/${id(runId)}/review`, {
      body: { verdict, comment: comment || undefined },
    }),
}
