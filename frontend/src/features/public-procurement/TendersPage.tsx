import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { SourceAttribution, StatusBadge } from '../../components/DataBadges'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { ResultCard } from '../../components/ResultCard'
import { useDebounced } from '../../hooks/useDebounced'
import { useUrlFilters } from '../../hooks/useUrlFilters'
import { publicProcurementApi, type V1Filters } from '../../services/publicProcurement'
import { closingInfo, formatAmount, formatDateTime, tenderStatusClass } from '../../utils/format'
import { TenderDetailDrawer } from './TenderDetailDrawer'
import { V1QueryBar } from './V1QueryBar'

const SORTS = [
  { value: 'closes_at', label: 'Cierre más próximo' },
  { value: 'published_at', label: 'Publicación más reciente' },
  { value: 'estimated_budget', label: 'Mayor monto estimado' },
  { value: 'last_fetched_at', label: 'Consultadas recientemente' },
]

/** Licitaciones públicas y privadas (API V1 licitaciones.json). */
export function TendersPage() {
  const qc = useQueryClient()
  const { get, update } = useUrlFilters()
  const [q, setQ] = useState(get('q'))
  const debouncedQ = useDebounced(q)
  const selected = get('ficha') || null
  const page = Number(get('page') || 1)
  const catalogs = useQuery({ queryKey: ['pp-catalogs'], queryFn: publicProcurementApi.catalogs, staleTime: Infinity })

  const filters: V1Filters = {
    q: debouncedQ.trim() || undefined,
    status: get('status') ? Number(get('status')) : undefined,
    type: get('type') || undefined,
    region: get('region') ? Number(get('region')) : undefined,
    sort: get('sort') || 'closes_at',
    page,
    per_page: 25,
    date: get('date') || undefined,
    source_status: get('source_status') || undefined,
    buyer_code: get('buyer_code') || undefined,
    supplier_code: get('supplier_code') || undefined,
  }
  const list = useQuery({
    queryKey: ['tenders', filters],
    queryFn: () => publicProcurementApi.listTenders(filters),
    placeholderData: keepPreviousData,
    retry: false,
  })

  const refresh = async () => {
    const fresh = await publicProcurementApi.listTenders({ ...filters, refresh: true, page: 1 })
    qc.setQueryData(['tenders', { ...filters, page: 1 }], fresh)
    update({ page: null })
  }

  const meta = list.data?.meta
  const rows = list.data?.data ?? []
  const pagination = meta?.pagination
  const c = catalogs.data?.data

  return (
    <>
      <V1QueryBar noun="licitaciones" statusFilters={c?.tender_status_filters ?? []} search={meta?.search} fetching={list.isFetching} onRefresh={() => void refresh()} />

      <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} licitaciones` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            update({ q: v || null })
          }}
          placeholder="Nombre, código u organismo"
          label="Filtrar licitaciones"
        />
        <SelectField label="Estado" value={get('status')} onChange={(v) => update({ status: v || null })} allLabel="Todos" options={(c?.tender_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))} />
        <SelectField label="Tipo" value={get('type')} onChange={(v) => update({ type: v || null })} allLabel="Todos" options={(c?.tender_types ?? []).map((s) => ({ value: s.code, label: `${s.code}: ${s.label}` }))} />
        <SelectField label="Región" value={get('region')} onChange={(v) => update({ region: v || null })} allLabel="Todas" options={(c?.regions ?? []).map((r) => ({ value: String(r.code), label: r.name }))} />
        <SelectField label="Ordenar por" value={get('sort') || 'closes_at'} onChange={(v) => update({ sort: v })} options={SORTS} />
      </FilterBar>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: -1, mb: 2 }}>
        Tipo, región y organismo se completan al abrir cada ficha: los listados de Mercado Público solo informan código, nombre, estado y cierre.
      </Typography>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="las licitaciones" />}
      {list.isLoading && <ListSkeleton />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState
          title={meta?.search ? 'La consulta no devolvió licitaciones con estos filtros' : 'Aún no hay licitaciones guardadas'}
          action={
            !meta?.search && (
              <Button variant="contained" onClick={() => update({ source_status: 'activas', date: null, buyer_code: null, supplier_code: null })}>
                Consultar licitaciones activas
              </Button>
            )
          }
        >
          {meta?.search ? 'Prueba otra fecha o quita filtros.' : 'Consulta las activas o las de una fecha específica con el formulario de arriba.'}
        </EmptyState>
      )}

      <Stack spacing={1.5} aria-busy={list.isFetching}>
        {rows.map((t) => (
          <ResultCard
            key={t.public_id}
            code={t.code}
            status={<StatusBadge label={t.status.label} className={tenderStatusClass(t.status.code)} />}
            tags={t.type.code ? <Chip size="small" variant="outlined" label={t.type.code} title={t.type.label ?? undefined} /> : undefined}
            title={t.title}
            subtitle={t.buyer.name ?? (t.detail_loaded ? 'Organismo no informado' : 'Abre la ficha para ver organismo, monto e ítems')}
            amount={formatAmount(t.budget.estimated, t.budget.currency)}
            amountLabel={t.budget.estimation ?? 'Monto estimado'}
            closing={t.status.code === 5 ? closingInfo(t.dates.closes_at) : null}
            dateText={formatDateTime(t.dates.closes_at)}
            dateLabel="Cierre"
            selected={t.code === selected}
            onOpen={() => update({ ficha: t.code }, false)}
          />
        ))}
      </Stack>

      {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={(p) => update({ page: String(p) }, false)} />}
      {meta && <SourceAttribution source={meta.source} updatedAt={meta.search?.fetched_at ?? rows[0]?.freshness.last_fetched_at} />}

      <TenderDetailDrawer code={selected} onClose={() => update({ ficha: null }, false)} />
    </>
  )
}
