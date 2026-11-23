import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import type { OpportunityMatch, SavedSearch } from '../../services/savedSearches'
import { AlertsBell } from './AlertsBell'
import { SavedSearchesPage } from './SavedSearchesPage'

const create = vi.fn()
const setStatus = vi.fn()
const markRead = vi.fn()

const search: SavedSearch = {
  public_id: 'S1',
  name: 'Aseo',
  criteria: { q: 'detergente', open_only: true, use_catalog: false },
  regions: [],
  alerts_enabled: true,
  is_active: true,
  matches_count: 1,
  new_count: 1,
  last_run_at: '2026-11-21T12:00:00Z',
}

const match: OpportunityMatch = {
  public_id: 'M1',
  status: { code: 'NEW', label: 'Nueva' },
  reasons: { words: ['detergente'] },
  search: { public_id: 'S1', name: 'Aseo' },
  first_matched_at: '2026-11-21T12:00:00Z',
  opportunity: {
    source_type: 'compra_agil',
    has_detail: true,
    code: '1001-1-COT26',
    title: 'Compra de detergente industrial',
    status: { code: 'publicada', label: 'Publicada' },
    buyer: 'Organismo Ficticio',
    region: 'Biobío',
    closes_at: '2030-11-25T15:00:00Z',
    available_amount_clp: '500000.00',
    last_fetched_at: '2026-11-21T11:00:00Z',
    data_origin: 'official',
  },
}

vi.mock('../../app/AuthContext', () => ({ useAuth: () => ({ profile: null, can: () => true }) }))
vi.mock('./TenderDetailDrawer', () => ({ TenderDetailDrawer: ({ code }: { code: string | null }) => (code ? <div>Licitación {code}</div> : null) }))
vi.mock('./CompraAgilDetailDrawer', () => ({ CompraAgilDetailDrawer: ({ code }: { code: string | null }) => (code ? <div>Ficha {code}</div> : null) }))
vi.mock('../../services/publicProcurement', () => ({
  publicProcurementApi: { catalogs: () => Promise.resolve({ data: { regions: [{ code: 8, name: 'Biobío' }] } }) },
}))
vi.mock('../../services/savedSearches', () => ({
  savedSearchesApi: {
    list: () => Promise.resolve({ data: [search], meta: { request_id: 'x', max_searches: 20, scheduler_enabled: true } }),
    matches: () => Promise.resolve({ data: [match], meta: { request_id: 'x', pagination: { page: 1, per_page: 20, total: 1, last_page: 1 }, counts: { NEW: 1 } } }),
    create: (...a: unknown[]) => {
      create(...a)
      return Promise.resolve({ data: search, meta: { request_id: 'x', run: { new: 3, total: 3, catalog_empty: false } } })
    },
    setMatchStatus: (...a: unknown[]) => {
      setStatus(...a)
      return Promise.resolve({ data: match })
    },
    alerts: () =>
      Promise.resolve({
        data: [
          { public_id: 'A1', type: { code: 'NEW_MATCHES', label: 'Nuevas coincidencias' }, title: '1 oportunidad nueva para "Aseo"', body: 'Códigos: 1001-1-COT26.', codes: ['1001-1-COT26'], source_type: null, search: { public_id: 'S1', name: 'Aseo' }, read: false, created_at: '2026-11-21T12:00:00Z' },
        ],
        meta: { request_id: 'x', unread_count: 1 },
      }),
    markRead: (...a: unknown[]) => {
      markRead(...a)
      return Promise.resolve({ data: { marked: 1 } })
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

describe('búsquedas guardadas y alertas', () => {
  it('muestra la coincidencia con su motivo y al abrir la ficha la marca como vista', async () => {
    wrap(<SavedSearchesPage />)
    expect(await screen.findByText('Compra de detergente industrial', {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByText(/Coincide porque contiene "detergente"/)).toBeInTheDocument()
    expect(screen.getByText('1 nuevas')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Ver ficha' }))
    expect(await screen.findByText('Ficha 1001-1-COT26')).toBeInTheDocument()
    await waitFor(() => expect(setStatus).toHaveBeenCalledWith('M1', 'SEEN'))
  })

  it('crea una búsqueda solo con algún criterio', async () => {
    wrap(<SavedSearchesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Nueva búsqueda' }, { timeout: 5000 }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/Nombre/), { target: { value: 'Papel' } })
    const submit = within(dialog).getByRole('button', { name: 'Guardar y buscar' })
    expect(submit).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/Palabras clave/), { target: { value: 'papel carta' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)
    await waitFor(() => expect(create).toHaveBeenCalled())
    expect(create.mock.calls[0][0]).toMatchObject({ name: 'Papel', alerts_enabled: true, criteria: { q: 'papel carta', open_only: true, use_catalog: false, source_types: ['compra_agil'] } })
    expect(await screen.findByText('3 coincidencias nuevas.')).toBeInTheDocument()
  })

  it('incluye licitaciones y no permite guardar sin tipo de proceso', async () => {
    wrap(<SavedSearchesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Nueva búsqueda' }, { timeout: 5000 }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/Nombre/), { target: { value: 'Obras' } })
    fireEvent.change(within(dialog).getByLabelText(/Palabras clave/), { target: { value: 'tractor' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Compra Ágil' }))
    const submit = within(dialog).getByRole('button', { name: 'Guardar y buscar' })
    expect(submit).toBeDisabled()
    expect(within(dialog).getByText('Elige al menos un tipo de proceso.')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Licitaciones' }))
    expect(submit).toBeEnabled()
    fireEvent.click(submit)
    await waitFor(() => expect(create).toHaveBeenCalledTimes(2))
    expect(create.mock.calls[1][0].criteria.source_types).toEqual(['licitacion'])
  })

  it('la campana muestra las alertas sin leer y permite marcarlas', async () => {
    wrap(<AlertsBell />)
    const bell = await screen.findByRole('button', { name: 'Alertas, 1 sin leer' }, { timeout: 5000 })
    fireEvent.click(bell)
    expect(await screen.findByText('1 oportunidad nueva para "Aseo"')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Marcar todas como leídas' }))
    await waitFor(() => expect(markRead).toHaveBeenCalled())
  })
})
