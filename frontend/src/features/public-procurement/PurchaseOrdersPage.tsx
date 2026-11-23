import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { SourceAttribution, StatusBadge } from '../../components/DataBadges'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { ResultCard } from '../../components/ResultCard'
import { useDebounced } from '../../hooks/useDebounced'
import { useUrlFilters } from '../../hooks/useUrlFilters'
import { publicProcurementApi, type V1Filters } from '../../services/publicProcurement'
import { formatAmount, formatDateTime, orderStatusClass } from '../../utils/format'
import { PurchaseOrderDetailDrawer } from './PurchaseOrderDetailDrawer'
import { V1QueryBar } from './V1QueryBar'

const SORTS = [
  { value: 'issued_at', label: 'Emisión más reciente' },
  { value: 'total', label: 'Mayor total' },
  { value: 'last_fetched_at', label: 'Consultadas recientemente' },
]

/** Órdenes de compra públicas (API V1 ordenesdecompra.json). */
export function PurchaseOrdersPage() {
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
    sort: get('sort') || 'issued_at',
    page,
    per_page: 25,
    date: get('date') || undefined,
    source_status: get('source_status') || undefined,
    buyer_code: get('buyer_code') || undefined,
    supplier_code: get('supplier_code') || undefined,
  }
  const list = useQuery({
    queryKey: ['purchase-orders', filters],
    queryFn: () => publicProcurementApi.listPurchaseOrders(filters),
    placeholderData: keepPreviousData,
    retry: false,
  })
  const refresh = async () => {
    const fresh = await publicProcurementApi.listPurchaseOrders({ ...filters, refresh: true, page: 1 })
    qc.setQueryData(['purchase-orders', { ...filters, page: 1 }], fresh)
    update({ page: null })
  }

  const meta = list.data?.meta
  const rows = list.data?.data ?? []
  const pagination = meta?.pagination
  const c = catalogs.data?.data

  return (
    <>
      <V1QueryBar noun="órdenes de compra" statusFilters={c?.order_status_filters ?? []} search={meta?.search} fetching={list.isFetching} onRefresh={() => void refresh()} />

      <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} órdenes` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            update({ q: v || null })
          }}
          placeholder="Nombre, código, organismo o proveedor"
          label="Filtrar órdenes de compra"
        />
        <SelectField label="Estado" value={get('status')} onChange={(v) => update({ status: v || null })} allLabel="Todos" options={(c?.order_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))} />
        <SelectField label="Ordenar por" value={get('sort') || 'issued_at'} onChange={(v) => update({ sort: v })} options={SORTS} />
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="las órdenes de compra" />}
      {list.isLoading && <ListSkeleton />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState title={meta?.search ? 'La consulta no devolvió órdenes con estos filtros' : 'Aún no hay órdenes de compra guardadas'}>
          {meta?.search ? 'Prueba otra fecha o quita filtros.' : 'Elige una fecha y presiona "Consultar". Sin fecha se consulta el día de hoy.'}
        </EmptyState>
      )}

      <Stack spacing={1.5} aria-busy={list.isFetching}>
        {rows.map((o) => (
          <ResultCard
            key={o.public_id}
            code={o.code}
            status={<StatusBadge label={o.status.label} className={orderStatusClass(o.status.code)} />}
            title={o.title ?? 'Orden sin nombre informado'}
            subtitle={
              o.detail_loaded
                ? `${o.buyer.name ?? 'Organismo no informado'} a ${o.supplier.name ?? 'proveedor no informado'}`
                : 'Abre la ficha para ver comprador, proveedor e ítems'
            }
            amount={formatAmount(o.amounts.total, o.currency)}
            amountLabel="Total con impuestos"
            dateText={formatDateTime(o.dates.issued_at)}
            dateLabel="Emisión"
            selected={o.code === selected}
            onOpen={() => update({ ficha: o.code }, false)}
          />
        ))}
      </Stack>

      {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={(p) => update({ page: String(p) }, false)} />}
      {meta && <SourceAttribution source={meta.source} updatedAt={meta.search?.fetched_at ?? rows[0]?.freshness.last_fetched_at} />}

      <PurchaseOrderDetailDrawer code={selected} onClose={() => update({ ficha: null }, false)} />
    </>
  )
}
