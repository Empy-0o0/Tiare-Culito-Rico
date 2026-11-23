import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Collapse from '@mui/material/Collapse'
import LinearProgress from '@mui/material/LinearProgress'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import ToggleButton from '@mui/material/ToggleButton'
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup'
import Typography from '@mui/material/Typography'
import CalculateOutlined from '@mui/icons-material/CalculateOutlined'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { EmptyState, ErrorState, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { procurementApi } from '../../services/procurement'
import type { IndicatorMetric, InvoiceDto } from '../../types/procurement'
import { formatAmount, formatDate, formatDateTime, formatMoney, todayInChile } from '../../utils/format'
import { InvoiceStatusChip, PaymentDialog } from '../procurement/InvoicesSection'

const shiftDays = (iso: string, days: number) => {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

const STATUS_LABEL: Record<string, string> = {
  DRAFT: 'Borrador',
  SUBMITTED: 'Enviadas',
  QUOTING: 'En cotización',
  PENDING_APPROVAL: 'Esperando aprobación',
  APPROVED: 'Aprobadas',
  ORDERED: 'Con orden',
  CLOSED: 'Recibidas',
  CANCELLED: 'Anuladas',
}

function formatValue(m: IndicatorMetric): string {
  if (m.value === null) return '—'
  switch (m.unit) {
    case 'CLP':
      return formatMoney(m.value)
    case '%':
      return `${Number(m.value).toLocaleString('es-CL', { maximumFractionDigits: 1 })} %`
    case 'days':
      return `${Number(m.value).toLocaleString('es-CL', { maximumFractionDigits: 1 })} días`
    default:
      return Number(m.value).toLocaleString('es-CL', { maximumFractionDigits: 1 })
  }
}

/** Tarjeta de indicador: valor, muestra y, a pedido, cómo se calcula (fuente, fórmula y supuestos). */
function MetricCard({ m }: { m: IndicatorMetric }) {
  const [open, setOpen] = useState(false)
  const positive = m.key.includes('savings') && m.value !== null && Number(m.value) > 0
  return (
    <Card sx={{ p: 2.25, display: 'flex', flexDirection: 'column' }}>
      <Typography variant="body2" color="text.secondary">
        {m.label}
      </Typography>
      <Typography className="num" sx={{ fontSize: '1.7rem', fontWeight: 700, lineHeight: 1.2, mt: 0.5, color: positive ? tokens.success : undefined }}>
        {formatValue(m)}
      </Typography>
      <Typography variant="caption" color="text.secondary">
        {m.value === null ? 'Sin datos en el período' : `Base: ${m.n} ${m.n === 1 ? 'caso' : 'casos'}`}
        {m.percent != null ? `, ${m.percent.toLocaleString('es-CL')} % del estimado` : ''}
        {m.average != null ? `, promedio ${m.average.toLocaleString('es-CL')} días` : ''}
      </Typography>
      <Box sx={{ flex: 1 }} />
      <Button size="small" startIcon={<CalculateOutlined />} onClick={() => setOpen((v) => !v)} sx={{ alignSelf: 'flex-start', mt: 1, ml: -0.75 }} aria-expanded={open}>
        {open ? 'Ocultar cálculo' : 'Cómo se calcula'}
      </Button>
      <Collapse in={open}>
        <Typography variant="caption" component="p" sx={{ mt: 0.5 }}>
          {m.formula}
        </Typography>
        {m.assumptions.length > 0 && (
          <Box component="ul" sx={{ m: 0, mt: 0.5, pl: 2 }}>
            {m.assumptions.map((a) => (
              <Typography key={a} component="li" variant="caption" color="text.secondary">
                {a}
              </Typography>
            ))}
          </Box>
        )}
      </Collapse>
    </Card>
  )
}

/** Indicadores verificables de compras y cuentas por pagar informadas. */
export function ReportsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const today = todayInChile()
  const [range, setRange] = useState<'30' | '90' | '365' | 'custom'>('90')
  const [custom, setCustom] = useState({ from: shiftDays(today, -89), to: today })
  const period = range === 'custom' ? custom : { from: shiftDays(today, -(Number(range) - 1)), to: today }
  const [invStatus, setInvStatus] = useState<'REGISTERED' | 'OVERDUE' | 'PAID'>('REGISTERED')
  const [invPage, setInvPage] = useState(1)
  const [paying, setPaying] = useState<InvoiceDto | null>(null)

  const indicators = useQuery({ queryKey: ['indicators', period], queryFn: () => procurementApi.indicators(period), placeholderData: keepPreviousData, enabled: can('reports.read') })
  const invoices = useQuery({ queryKey: ['invoices', invStatus, invPage], queryFn: () => procurementApi.invoices({ status: invStatus, page: invPage, per_page: 15 }), placeholderData: keepPreviousData })
  const d = indicators.data?.data

  if (!can('reports.read')) {
    return (
      <>
        <PageHeader title="Analítica e informes" />
        <Alert severity="info">Tu rol no incluye acceso a informes. Pídeselo a un administrador de la empresa.</Alert>
      </>
    )
  }

  return (
    <>
      <PageHeader title="Analítica e informes" subtitle="Indicadores calculados con los datos que registra tu empresa en TIARE. Cada cifra muestra su base y cómo se calcula." />

      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }} sx={{ mb: 2.5 }}>
        <ToggleButtonGroup exclusive size="small" value={range} onChange={(_, v) => v && setRange(v)} aria-label="Período" sx={{ '& .MuiToggleButton-root': { textTransform: 'none', px: 1.75, borderRadius: '10px' } }}>
          <ToggleButton value="30">30 días</ToggleButton>
          <ToggleButton value="90">90 días</ToggleButton>
          <ToggleButton value="365">12 meses</ToggleButton>
          <ToggleButton value="custom">Personalizado</ToggleButton>
        </ToggleButtonGroup>
        {range === 'custom' && (
          <Stack direction="row" spacing={1}>
            <TextField label="Desde" type="date" value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: custom.to }} />
            <TextField label="Hasta" type="date" value={custom.to} onChange={(e) => setCustom({ ...custom, to: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ min: custom.from, max: today }} />
          </Stack>
        )}
      </Stack>

      {indicators.isFetching && d && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {indicators.error && <ErrorState error={indicators.error} what="los indicadores" />}
      {!d && indicators.isLoading && (
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(3, 1fr)' } }}>
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} variant="rounded" height={130} sx={{ borderRadius: '16px' }} />
          ))}
        </Box>
      )}

      {d && (
        <>
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(3, 1fr)' }, alignItems: 'stretch' }}>
            {d.metrics.map((m) => (
              <MetricCard key={m.key} m={m} />
            ))}
          </Box>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
            Período {formatDate(`${d.period.from}T12:00:00`)} a {formatDate(`${d.period.to}T12:00:00`)} (hora de Chile). Moneda {d.currency}. Calculado el {formatDateTime(d.generated_at)}. Fuente: {d.source}
          </Typography>

          <Card sx={{ p: 2.5, mt: 3 }}>
            <Typography variant="h3" component="h2" sx={{ mb: 1.5 }}>
              Solicitudes por estado (hoy)
            </Typography>
            <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
              {Object.entries(STATUS_LABEL).map(([code, label]) => (
                <Chip key={code} onClick={() => navigate('/solicitudes')} label={`${label}: ${d.pipeline[code] ?? 0}`} variant="outlined" />
              ))}
            </Stack>
          </Card>
        </>
      )}

      <Card sx={{ p: 2.5, mt: 3 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1.5} sx={{ mb: 2 }}>
          <Box>
            <Typography variant="h3" component="h2">
              Facturas de proveedores
            </Typography>
            {d && (
              <Typography variant="body2" color="text.secondary">
                {d.invoices.pending_count} por pagar ({formatMoney(d.invoices.pending_amount_clp)}), {d.invoices.overdue_count} vencidas, {d.invoices.orders_with_difference} órdenes con diferencia de facturación.
              </Typography>
            )}
          </Box>
          <ToggleButtonGroup
            exclusive
            size="small"
            value={invStatus}
            onChange={(_, v) => {
              if (v) {
                setInvStatus(v)
                setInvPage(1)
              }
            }}
            sx={{ '& .MuiToggleButton-root': { textTransform: 'none', px: 1.5, borderRadius: '10px' } }}
          >
            <ToggleButton value="REGISTERED">Por pagar</ToggleButton>
            <ToggleButton value="OVERDUE">Vencidas</ToggleButton>
            <ToggleButton value="PAID">Pagadas</ToggleButton>
          </ToggleButtonGroup>
        </Stack>
        {invoices.error && <ErrorState error={invoices.error} what="las facturas" />}
        {invoices.data && invoices.data.data.length === 0 && <EmptyState title="No hay facturas en esta vista">Las facturas se registran en la ficha de cada orden de compra.</EmptyState>}
        {invoices.data && invoices.data.data.length > 0 && (
          <DataTable
            columns={[{ label: 'Documento' }, { label: 'Proveedor', hideOnMobile: true }, { label: 'Vence', hideOnMobile: true }, { label: 'Total', align: 'right' }, { label: 'Estado' }]}
            rows={invoices.data.data.map((i) => ({
              key: i.public_id,
              label: `Abrir orden ${i.order?.number ?? ''}`,
              onClick: () => i.order && navigate(`/ordenes?ficha=${i.order.public_id}`),
              cells: [
                <>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {i.document_type.label} N° <span className="code">{i.number}</span>
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Orden {i.order?.number}
                  </Typography>
                </>,
                i.supplier,
                i.due_on ? formatDate(`${i.due_on}T12:00:00`) : '—',
                <span className="num">{formatAmount(i.total, i.currency)}</span>,
                <Stack direction="row" spacing={1} alignItems="center" onClick={(e) => e.stopPropagation()}>
                  <InvoiceStatusChip i={i} />
                  {can('invoices.manage') && i.status === 'REGISTERED' && i.document_type.code !== 'CREDIT_NOTE' && (
                    <Button size="small" onClick={() => setPaying(i)}>
                      Informar pago
                    </Button>
                  )}
                </Stack>,
              ],
            }))}
          />
        )}
        {invoices.data?.meta.pagination && <Pager page={invoices.data.meta.pagination.page} lastPage={invoices.data.meta.pagination.last_page} onChange={setInvPage} />}
        {d && (
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
            {d.invoices.note}
          </Typography>
        )}
      </Card>

      <PaymentDialog
        invoice={paying}
        onClose={() => setPaying(null)}
        onSaved={() => {
          setPaying(null)
          void qc.invalidateQueries({ queryKey: ['invoices'] })
          void qc.invalidateQueries({ queryKey: ['indicators'] })
        }}
      />
    </>
  )
}

