import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import type { AgentCatalog, AgentRunDetail } from '../../services/agents'
import { AgentLauncher } from './AgentLauncher'
import { AgentsPage } from './AgentsPage'

const start = vi.fn()
const review = vi.fn()

const catalog: AgentCatalog = {
  model: { available: true, provider: 'anthropic', name: 'claude-opus-5-5', daily_limit: 30, remaining_today: 29 },
  agents: [
    {
      id: 'tender_intelligence_agent',
      name: 'Análisis de Compra Ágil',
      prompt_version: '1.0.0',
      purpose: 'Resume la oportunidad y arma un checklist preliminar.',
      subject_type: 'compra_agil',
      data_used: ['Ficha oficial ya sincronizada.'],
      limits: ['No lee los documentos adjuntos.'],
      can_run: true,
    },
  ],
}

const run: AgentRunDetail = {
  public_id: 'R1',
  agent: { id: 'tender_intelligence_agent', name: 'Análisis de Compra Ágil', prompt_version: '1.0.0' },
  subject: { type: 'compra_agil', ref: '1234-56-COT26', label: '1234-56-COT26 · Detergente' },
  mode: 'llm',
  status: 'COMPLETED',
  model: 'claude-opus-5-5',
  review_status: 'PENDING',
  requested_by: 'Admin',
  created_at: '2026-11-20T12:00:00Z',
  facts: {
    open: true,
    hours_to_close: 15,
    closes_at: '2026-11-21 00:00',
    items_count: 2,
    documents_count: 1,
    budget: { currency: 'CLP', available: '900000.00', available_clp: '900000.00', estimated: null },
    catalog_matches: [{ requested: 'Detergente industrial 5 L', catalog: ['Detergente industrial'] }],
    alerts: [{ level: 'urgent', text: 'Cierra en 15 horas.' }],
    checklist: ['Descargar y leer los 1 documentos adjuntos en Mercado Público.'],
    source: { name: 'Dirección ChileCompra / Mercado Público', code: '1234-56-COT26', last_fetched_at: '2026-11-20 08:00', portal_url: 'https://www.mercadopublico.cl/' },
  },
  analysis: {
    summary: 'El municipio compra detergente y escobas.',
    observable_requirements: [{ requirement: '20 bidones de 5 L', source: 'productos' }],
    risks: [{ risk: 'Plazo de entrega de 5 días', severity: 'media' }],
    questions_for_buyer: ['¿Aceptan marcas equivalentes?'],
    checklist_additions: [],
    catalog_fit: { level: 'medio', rationale: 'Tienes detergente pero no escobas.', matched_products: ['Detergente industrial'] },
    uncertainty: 'No conozco el contenido de los adjuntos.',
  },
  usage: { input_tokens: 1200, output_tokens: 300, latency_ms: 9000, provider: 'anthropic' },
  error: null,
  input_hash: 'x',
  review: { status: 'PENDING', by: null, at: null, comment: null },
  disclaimer: 'Análisis de apoyo.',
}

vi.mock('../../app/AuthContext', () => ({ useAuth: () => ({ profile: null, can: () => true }) }))
vi.mock('../../services/agents', () => ({
  agentsApi: {
    catalog: () => Promise.resolve({ data: catalog }),
    runs: () => Promise.resolve({ data: [], meta: { request_id: 'x', pagination: { page: 1, per_page: 20, total: 0, last_page: 1 } } }),
    run: () => Promise.resolve({ data: run }),
    start: (...a: unknown[]) => {
      start(...a)
      return Promise.resolve({ data: run })
    },
    review: (...a: unknown[]) => {
      review(...a)
      return Promise.resolve({ data: { ...run, review_status: 'USEFUL', review: { status: 'USEFUL', by: 'Admin', at: '2026-11-20T12:05:00Z', comment: null } } })
    },
  },
}))

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Fase 6: agentes IA', () => {
  it('muestra el estado del modelo, qué hace y qué no hace cada agente', async () => {
    wrap(<AgentsPage />)
    expect(await screen.findByText(/Modelo activo: claude-opus-5-5\. Quedan 29 de 30/, {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText('No lee los documentos adjuntos.')).toBeInTheDocument()
    expect(await screen.findByText('Aún no hay análisis')).toBeInTheDocument()
  })

  it('analiza una Compra Ágil, separa datos calculados del texto de IA y registra la revisión', async () => {
    wrap(<AgentLauncher agent="tender_intelligence_agent" subjectType="compra_agil" subjectRef="1234-56-COT26" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Analizar con IA' }, { timeout: 5000 }))
    await waitFor(() => expect(start).toHaveBeenCalledWith('tender_intelligence_agent', '1234-56-COT26', true))

    expect(await screen.findByText('Cierra en 15 horas.')).toBeInTheDocument()
    expect(screen.getByText('Estimación IA')).toBeInTheDocument()
    expect(screen.getByText('El municipio compra detergente y escobas.')).toBeInTheDocument()
    expect(screen.getByText('Calce medio')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Marcar como útil' }))
    await waitFor(() => expect(review).toHaveBeenCalledWith('R1', 'USEFUL', ''))
    expect(await screen.findByText(/Marcado como útil por Admin/)).toBeInTheDocument()
  })
})
