import Alert from '@mui/material/Alert'
import Button from '@mui/material/Button'
import FormControlLabel from '@mui/material/FormControlLabel'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { SourceAttribution } from '../../components/DataBadges'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { useDebounced } from '../../hooks/useDebounced'
import { publicProcurementApi, type CompraAgilFilters } from '../../services/publicProcurement'
import { CompraAgilDetailDrawer } from './CompraAgilDetailDrawer'
import { CompraAgilRow } from './CompraAgilRow'

const PER_PAGE = 25
// Formato observado en códigos de Compra Ágil (p. ej. 2281-2093-COT26).
const CODE_PATTERN = /^\d{1,10}-\d{1,10}-COT\d{2}$/i

const SORTS = [
  { value: 'closes_at', label: 'Cierre más próximo' },
  { value: 'source_updated_at', label: 'Último cambio' },
  { value: 'published_at', label: 'Publicación más reciente' },
  { value: 'available_amount_clp', label: 'Mayor monto' },
]

/** Compra Ágil (API V2): base sincronizada + consulta directa de cualquier código. */
export function CompraAgilMonitorPage() {
  const [params, setParams] = useSearchParams()
  const [q, setQ] = useState(params.get('q') ?? '')
  const [status, setStatus] = useState('')
  const [region, setRegion] = useState('')
  const [openOnly, setOpenOnly] = useState(true)
  const [sort, setSort] = useState<NonNullable<CompraAgilFilters['sort']>>('closes_at')
  const [page, setPage] = useState(1)
  const selected = params.get('ficha')
  const debouncedQ = useDebounced(q)

  const urlQ = params.get('q') ?? ''
  useEffect(() => setQ(urlQ), [urlQ]) // búsqueda global del encabezado
  useEffect(() => setPage(1), [debouncedQ, status, region, openOnly, sort])

  const openDetail = (code: string | null) => {
    const next = new URLSearchParams(params)
    if (code) next.set('ficha', code)
    else next.delete('ficha')
    setParams(next, { replace: true })
  }

  const catalogs = useQuery({ queryKey: ['pp-catalogs'], queryFn: publicProcurementApi.catalogs, staleTime: Infinity })
  const filters: CompraAgilFilters = {
    q: debouncedQ.trim() || undefined,
    status: status ? [status] : undefined,
    region: region ? [Number(region)] : undefined,
    open_only: openOnly,
    sort,
    direction: sort === 'closes_at' ? 'asc' : 'desc',
    page,
    per_page: PER_PAGE,
  }
  const list = useQuery({
    queryKey: ['compra-agil-list', filters],
    queryFn: () => publicProcurementApi.listCompraAgil(filters),
    placeholderData: keepPreviousData,
  })

  const meta = list.data?.meta
  const items = list.data?.data ?? []
  const pagination = meta?.pagination
  const typedCode = CODE_PATTERN.test(debouncedQ.trim()) ? debouncedQ.trim().toUpperCase() : null
  const c = catalogs.data?.data

  return (
    <>
      <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} resultados` : undefined}>
        <SearchField value={q} onChange={setQ} placeholder="Título, código u organismo" label="Buscar Compras Ágiles" />
        <SelectField label="Estado" value={status} onChange={setStatus} allLabel="Todos" options={(c?.compra_agil_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))} />
        <SelectField label="Región" value={region} onChange={setRegion} allLabel="Todas" options={(c?.regions ?? []).map((r) => ({ value: String(r.code), label: r.name }))} />
        <SelectField label="Ordenar por" value={sort} onChange={(v) => setSort(v as typeof sort)} options={SORTS} />
        <FormControlLabel control={<Switch checked={openOnly} onChange={(e) => setOpenOnly(e.target.checked)} />} label="Solo abiertas" />
      </FilterBar>

      {typedCode && (
        <Alert
          severity="info"
          sx={{ mb: 2 }}
          action={
            <Button color="inherit" size="small" onClick={() => openDetail(typedCode)}>
              Ver ficha oficial
            </Button>
          }
        >
          ¿Buscas la Compra Ágil {typedCode}? Abre su ficha aunque aún no esté sincronizada.
        </Alert>
      )}

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="las Compras Ágiles" />}
      {list.isLoading && <ListSkeleton />}
      {!list.isLoading && !list.error && items.length === 0 && (
        <EmptyState
          title="No hay Compras Ágiles con estos filtros"
          action={
            openOnly ? (
              <Button variant="outlined" onClick={() => setOpenOnly(false)}>
                Incluir cerradas
              </Button>
            ) : undefined
          }
        >
          {meta?.sync.last_success_at ? 'Quita algún filtro o incluye las cerradas.' : 'Aún no hay datos sincronizados. Usa "Sincronizar".'}
        </EmptyState>
      )}

      <Stack spacing={1.5} aria-busy={list.isFetching}>
        {items.map((item) => (
          <CompraAgilRow key={item.public_id} item={item} selected={item.code === selected} onOpen={openDetail} />
        ))}
      </Stack>

      {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={setPage} />}
      {meta && <SourceAttribution source={meta.source} updatedAt={meta.sync.last_item_fetched_at} />}

      <CompraAgilDetailDrawer code={selected} onClose={() => openDetail(null)} />
    </>
  )
}
