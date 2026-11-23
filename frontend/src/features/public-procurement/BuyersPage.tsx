import Button from '@mui/material/Button'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import GavelOutlined from '@mui/icons-material/GavelOutlined'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import SyncOutlined from '@mui/icons-material/SyncOutlined'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { SourceAttribution } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { FilterBar, SearchField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { useDebounced } from '../../hooks/useDebounced'
import { publicProcurementApi } from '../../services/publicProcurement'

/** Directorio de organismos compradores (BuscarComprador), guardado localmente y renovado una vez al día. */
export function BuyersPage() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const debouncedQ = useDebounced(q)
  const filters = { q: debouncedQ.trim() || undefined, page, per_page: 30 }

  const list = useQuery({
    queryKey: ['buyers', filters],
    queryFn: () => publicProcurementApi.buyers(filters),
    placeholderData: keepPreviousData,
    retry: false,
  })
  const refresh = async () => {
    await publicProcurementApi.buyers({ refresh: true, per_page: 1 })
    void qc.invalidateQueries({ queryKey: ['buyers'] })
  }

  const meta = list.data?.meta
  const rows = list.data?.data ?? []

  return (
    <>
      <FilterBar count={meta?.pagination ? `${meta.pagination.total.toLocaleString('es-CL')} organismos` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            setPage(1)
          }}
          placeholder="Nombre o código del organismo"
          label="Buscar organismos"
        />
        {meta?.stale && (
          <Button variant="outlined" startIcon={<SyncOutlined />} onClick={() => void refresh()} disabled={list.isFetching}>
            Renovar directorio
          </Button>
        )}
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="el directorio de organismos" />}
      {list.isLoading && <ListSkeleton rows={6} />}
      {!list.isLoading && !list.error && rows.length === 0 && <EmptyState title="Ningún organismo coincide con la búsqueda">Prueba con otra palabra del nombre.</EmptyState>}

      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Código' }, { label: 'Organismo' }, { label: 'Ver actividad de hoy', align: 'right' }]}
          rows={rows.map((b) => ({
            key: b.code,
            cells: [
              <span className="code">{b.code}</span>,
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {b.name}
              </Typography>,
              <Stack direction="row" spacing={1} justifyContent="flex-end" flexWrap="wrap" useFlexGap>
                <Button size="small" startIcon={<GavelOutlined />} component={Link} to={`/mercado-publico/licitaciones?buyer_code=${b.code}`}>
                  Licitaciones
                </Button>
                <Button size="small" startIcon={<ReceiptLongOutlined />} component={Link} to={`/mercado-publico/ordenes-de-compra?buyer_code=${b.code}`}>
                  Órdenes
                </Button>
              </Stack>,
            ],
          }))}
        />
      )}

      {meta?.pagination && <Pager page={meta.pagination.page} lastPage={meta.pagination.last_page} onChange={setPage} />}
      {meta && <SourceAttribution source={meta.source} updatedAt={meta.last_fetched_at} />}
    </>
  )
}
