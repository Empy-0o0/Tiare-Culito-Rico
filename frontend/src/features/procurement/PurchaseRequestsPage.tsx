import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { StatusBadge } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { FilterBar, SearchField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { procurementApi } from '../../services/procurement'
import type { RequestInput } from '../../types/procurement'
import type { FieldErrors } from '../../utils/fieldError'
import { formatDate, formatMoney } from '../../utils/format'
import { RequestFormDialog } from './RequestFormDialog'
import { REQUEST_TONE } from './status'

interface Props {
  /** "quoting": vista de Cotizaciones (solicitudes en cotización o esperando aprobación). */
  mode?: 'all' | 'quoting'
}

/** Solicitudes de compra con filtros por estado (con conteos) y creación. */
export function PurchaseRequestsPage({ mode = 'all' }: Props) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState(mode === 'quoting' ? 'QUOTING' : '')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)

  const refs = useQuery({ queryKey: ['procurement-refs'], queryFn: procurementApi.references, staleTime: Infinity })
  const list = useQuery({
    queryKey: ['purchase-requests', debouncedQ, status, page],
    queryFn: () => procurementApi.list({ q: debouncedQ.trim() || undefined, status: status || undefined, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })
  const create = useMutation({
    mutationFn: (body: RequestInput) => procurementApi.create(body),
    onSuccess: (r) => {
      setOpen(false)
      void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
      navigate(`/solicitudes/${r.data.public_id}`)
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setFormError(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })

  const rows = list.data?.data ?? []
  const counts = list.data?.meta.status_counts ?? {}
  const statuses = (refs.data?.data.statuses ?? []).filter((s) => (mode === 'quoting' ? ['QUOTING', 'PENDING_APPROVAL'].includes(s.code) : true))
  const filters = mode === 'quoting' ? statuses : [{ code: '', label: 'Todas' }, ...statuses]

  return (
    <>
      <PageHeader
        title={mode === 'quoting' ? 'Cotizaciones' : 'Solicitudes de compra'}
        subtitle={
          mode === 'quoting'
            ? 'Solicitudes en cotización o esperando aprobación: registra ofertas, compáralas y propone la compra.'
            : 'Desde la necesidad hasta la recepción: crea, cotiza, aprueba y emite órdenes con trazabilidad.'
        }
        actions={
          can('purchase_requests.create') && mode === 'all' ? (
            <Button
              variant="contained"
              startIcon={<Add />}
              onClick={() => {
                setErrors({})
                setFormError(null)
                setOpen(true)
              }}
            >
              Nueva solicitud
            </Button>
          ) : undefined
        }
      />

      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 2 }} role="group" aria-label="Filtrar por estado">
        {filters.map((s) => {
          const n = s.code ? (counts[s.code] ?? 0) : Object.values(counts).reduce((a, b) => a + b, 0)
          const active = status === s.code
          return (
            <Chip
              key={s.code || 'all'}
              label={`${s.label} (${n})`}
              onClick={() => {
                setStatus(s.code)
                setPage(1)
              }}
              color={active ? 'primary' : 'default'}
              variant={active ? 'filled' : 'outlined'}
              aria-pressed={active}
            />
          )
        })}
      </Stack>

      <FilterBar count={list.data?.meta.pagination ? `${list.data.meta.pagination.total.toLocaleString('es-CL')} solicitudes` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            setPage(1)
          }}
          placeholder="Número o título"
          label="Buscar solicitudes"
        />
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="las solicitudes" />}
      {list.isLoading && <ListSkeleton rows={5} />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState
          title={q || status ? 'Ninguna solicitud coincide' : 'Aún no hay solicitudes de compra'}
          action={
            !q && !status && can('purchase_requests.create') ? (
              <Button variant="contained" startIcon={<Add />} onClick={() => setOpen(true)}>
                Crear la primera solicitud
              </Button>
            ) : undefined
          }
        >
          {q || status ? 'Prueba con otro estado o búsqueda.' : 'Una solicitud describe qué se necesita; luego se cotiza, se aprueba y se emite la orden.'}
        </EmptyState>
      )}

      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Solicitud' }, { label: 'Ítems', align: 'right', hideOnMobile: true }, { label: 'Monto', align: 'right', hideOnMobile: true }, { label: 'Se necesita', hideOnMobile: true }, { label: 'Estado' }]}
          rows={rows.map((r) => ({
            key: r.public_id,
            label: `Abrir ${r.number}`,
            onClick: () => navigate(`/solicitudes/${r.public_id}`),
            cells: [
              <>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography className="code" sx={{ color: 'primary.dark' }}>
                    {r.number}
                  </Typography>
                  {['HIGH', 'URGENT'].includes(r.priority.code) && <Chip size="small" label={r.priority.label} sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.urgent, height: 20 }} />}
                </Stack>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {r.title}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {[r.requested_by, r.customer ? `para ${r.customer}` : null].filter(Boolean).join(', ')}
                </Typography>
              </>,
              <span className="num">{r.items_count}</span>,
              <Stack alignItems="flex-end">
                <span className="num">{formatMoney(r.selected_total_clp ?? r.estimated_total)}</span>
                <Typography variant="caption" color="text.secondary">
                  {r.selected_total_clp ? 'Cotización elegida' : r.estimated_total ? 'Estimado neto' : 'Sin estimado'}
                </Typography>
              </Stack>,
              r.needed_by ? formatDate(`${r.needed_by}T12:00:00`) : '—',
              <StatusBadge label={r.status.label} className={REQUEST_TONE[r.status.code] ?? 'draft'} />,
            ],
          }))}
        />
      )}

      {list.data?.meta.pagination && <Pager page={list.data.meta.pagination.page} lastPage={list.data.meta.pagination.last_page} onChange={setPage} />}

      <RequestFormDialog
        open={open}
        editing={null}
        priorities={refs.data?.data.priorities ?? []}
        onClose={() => setOpen(false)}
        onSubmit={(b) => create.mutate(b)}
        submitting={create.isPending}
        errors={errors}
        error={formError}
      />
    </>
  )
}
