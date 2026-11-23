import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import LinearProgress from '@mui/material/LinearProgress'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Step from '@mui/material/Step'
import StepLabel from '@mui/material/StepLabel'
import Stepper from '@mui/material/Stepper'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import ArrowBack from '@mui/icons-material/ArrowBack'
import BlockOutlined from '@mui/icons-material/BlockOutlined'
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline'
import EditOutlined from '@mui/icons-material/EditOutlined'
import HighlightOff from '@mui/icons-material/HighlightOff'
import PlayArrowOutlined from '@mui/icons-material/PlayArrowOutlined'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import SendOutlined from '@mui/icons-material/SendOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { DataOriginTag, StatusBadge } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Facts } from '../../components/Drawer'
import { ErrorState } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { procurementApi } from '../../services/procurement'
import type { PurchaseRequestDetail, RequestInput } from '../../types/procurement'
import type { FieldErrors } from '../../utils/fieldError'
import { formatDate, formatDateTime, formatMoney } from '../../utils/format'
import { ComparisonSection } from './ComparisonSection'
import { OrderDialog } from './OrderDialog'
import { QuotationsSection } from './QuotationsSection'
import { RequestFormDialog } from './RequestFormDialog'
import { FLOW_STEPS, ORDER_TONE, qty, REQUEST_TONE, stepIndex } from './status'

type TabKey = 'summary' | 'quotations' | 'comparison' | 'approval'

/** Pestaña inicial según la etapa: lleva al usuario a lo que hay que hacer. */
const defaultTab = (s: string): TabKey => (s === 'QUOTING' ? 'quotations' : s === 'PENDING_APPROVAL' ? 'approval' : 'summary')

const day = (d: string | null) => (d ? formatDate(`${d}T12:00:00`) : null)

/** Ficha de una solicitud: etapa, próxima acción y todo su historial. */
export function PurchaseRequestDetailPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const query = useQuery({ queryKey: ['purchase-request', id], queryFn: () => procurementApi.get(id) })
  const refs = useQuery({ queryKey: ['procurement-refs'], queryFn: procurementApi.references, staleTime: Infinity })
  const r = query.data?.data
  const [tab, setTab] = useState<TabKey>('summary')
  const [toast, setToast] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editOpen, setEditOpen] = useState(false)
  const [editErrors, setEditErrors] = useState<FieldErrors>({})
  const [cancelOpen, setCancelOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [orderOpen, setOrderOpen] = useState(false)
  const [comment, setComment] = useState('')

  const status = r?.status.code
  useEffect(() => {
    if (status) setTab(defaultTab(status))
  }, [status])

  const apply = (d: PurchaseRequestDetail, msg?: string) => {
    qc.setQueryData(['purchase-request', id], { data: d, meta: query.data?.meta })
    void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
    setError(null)
    if (msg) setToast(msg)
  }
  const fail = (e: unknown) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')
  const act = useMutation({
    mutationFn: (fn: () => Promise<{ data: PurchaseRequestDetail }>) => fn(),
    onSuccess: (d) => apply(d.data, `Solicitud ${d.data.number}: ${d.data.status.label.toLowerCase()}.`),
    onError: fail,
  })
  const edit = useMutation({
    mutationFn: (b: RequestInput) => procurementApi.update(id, b),
    onSuccess: (d) => {
      setEditOpen(false)
      apply(d.data, 'Cambios guardados.')
    },
    onError: (e) => {
      setEditErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      fail(e)
    },
  })

  if (query.isLoading) return <LinearProgress sx={{ borderRadius: '4px' }} />
  if (query.error || !r) return <ErrorState error={query.error} what="la solicitud" />

  const a = r.actions
  const selected = r.quotations.find((q) => q.public_id === r.selected_quotation_public_id)
  const cancelled = r.status.code === 'CANCELLED'

  return (
    <>
      <Button component={Link} to="/solicitudes" startIcon={<ArrowBack />} sx={{ mb: 1, ml: -1 }}>
        Solicitudes
      </Button>

      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={2} sx={{ mb: 2 }}>
        <Box>
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <Typography className="code" sx={{ fontSize: '1.4rem', color: 'primary.dark' }}>
              {r.number}
            </Typography>
            <StatusBadge label={r.status.label} className={REQUEST_TONE[r.status.code] ?? 'draft'} />
            <Chip size="small" variant="outlined" label={`Prioridad ${r.priority.label.toLowerCase()}`} />
            <DataOriginTag origin="client" />
          </Stack>
          <Typography variant="h1" sx={{ mt: 0.5 }}>
            {r.title}
          </Typography>
          <Typography color="text.secondary">
            {[r.requested_by ? `Solicitada por ${r.requested_by}` : null, r.customer ? `para ${r.customer}` : null, r.needed_by ? `se necesita el ${day(r.needed_by)}` : null, `versión ${r.version}`].filter(Boolean).join(', ')}
          </Typography>
        </Box>
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="flex-start">
          {a.edit && (
            <Button variant="outlined" startIcon={<EditOutlined />} onClick={() => setEditOpen(true)}>
              Editar
            </Button>
          )}
          {a.submit && (
            <Button variant="contained" startIcon={<SendOutlined />} onClick={() => act.mutate(() => procurementApi.submit(id))} disabled={act.isPending}>
              Enviar a compras
            </Button>
          )}
          {a.start_quoting && (
            <Button variant="contained" startIcon={<PlayArrowOutlined />} onClick={() => act.mutate(() => procurementApi.startQuoting(id))} disabled={act.isPending}>
              Empezar a cotizar
            </Button>
          )}
          {a.issue_order && (
            <Button variant="contained" startIcon={<ReceiptLongOutlined />} onClick={() => setOrderOpen(true)}>
              Emitir orden
            </Button>
          )}
          {a.cancel && (
            <Button color="inherit" startIcon={<BlockOutlined />} onClick={() => setCancelOpen(true)}>
              Anular
            </Button>
          )}
        </Stack>
      </Stack>

      {error && (
        <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}
      {cancelled && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Anulada el {formatDateTime(r.dates.cancelled_at)}. Motivo: {r.cancel_reason}
        </Alert>
      )}

      {!cancelled && (
        <Card sx={{ p: { xs: 1.5, sm: 2.5 }, mb: 2.5, overflowX: 'auto' }}>
          <Stepper activeStep={r.status.code === 'CLOSED' ? FLOW_STEPS.length : stepIndex(r.status.code)} alternativeLabel>
            {FLOW_STEPS.map((s) => (
              <Step key={s.code}>
                <StepLabel>{s.label}</StepLabel>
              </Step>
            ))}
          </Stepper>
        </Card>
      )}

      <Box sx={{ borderBottom: `1px solid ${tokens.outlineVariant}`, mb: 2.5 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" allowScrollButtonsMobile>
          <Tab value="summary" label="Resumen" />
          <Tab value="quotations" label={`Cotizaciones (${r.quotations.filter((q) => q.status !== 'DISCARDED').length})`} />
          <Tab value="comparison" label="Comparación" />
          <Tab value="approval" label={r.status.code === 'PENDING_APPROVAL' ? 'Aprobación (pendiente)' : 'Aprobación'} />
        </Tabs>
      </Box>

      {tab === 'summary' && (
        <Stack spacing={2.5}>
          {r.order && (
            <Card sx={{ p: 2, borderColor: 'primary.main' }}>
              <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1}>
                <Box>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Typography className="code" sx={{ color: 'primary.dark' }}>
                      {r.order.number}
                    </Typography>
                    <StatusBadge label={r.order.status.label} className={ORDER_TONE[r.order.status.code] ?? 'draft'} />
                  </Stack>
                  <Typography variant="body2" color="text.secondary">
                    Orden a {r.order.supplier.name} por <span className="num">{formatMoney(r.order.total)}</span>
                  </Typography>
                </Box>
                <Button variant="outlined" onClick={() => navigate(`/ordenes?ficha=${r.order!.public_id}`)}>
                  Ver orden y recepción
                </Button>
              </Stack>
            </Card>
          )}
          <Facts
            rows={[
              ['Monto estimado (neto)', r.estimated_total ? <span className="num">{formatMoney(r.estimated_total)}</span> : 'Sin precio de referencia en todos los ítems'],
              ['Compra propuesta', selected ? `${selected.supplier.name}, ${formatMoney(selected.total_clp)}` : null],
              ['Centro de costo', r.cost_center],
              ['Dirección de entrega', r.delivery_address],
              ['Enviada', r.dates.submitted_at ? formatDateTime(r.dates.submitted_at) : null],
              ['Propuesta', r.dates.proposed_at ? `${formatDateTime(r.dates.proposed_at)} por ${r.proposed_by}` : null],
              ['Decisión', r.dates.decided_at ? formatDateTime(r.dates.decided_at) : null],
            ]}
          />
          {r.description && (
            <DetailSection title="Descripción">
              <Typography variant="body2" sx={{ whiteSpace: 'pre-line' }}>
                {r.description}
              </Typography>
            </DetailSection>
          )}
          <DetailSection title={`Ítems (${r.items.length})`}>
            <DataTable
              columns={[{ label: '#' }, { label: 'Descripción' }, { label: 'Cantidad', align: 'right' }, { label: 'Precio ref. neto', align: 'right' }]}
              rows={r.items.map((i) => ({
                key: i.line_number,
                cells: [
                  i.line_number,
                  <>
                    {i.description}
                    {i.product_public_id && (
                      <Typography variant="caption" color="text.secondary" component="div">
                        Del catálogo
                      </Typography>
                    )}
                  </>,
                  <span className="num">
                    {qty(i.quantity)} {i.unit_code}
                  </span>,
                  <span className="num">{i.estimated_unit_price ? formatMoney(i.estimated_unit_price) : '—'}</span>,
                ],
              }))}
            />
          </DetailSection>
        </Stack>
      )}

      {tab === 'quotations' && <QuotationsSection r={r} onChange={(d) => apply(d)} />}
      {tab === 'comparison' && <ComparisonSection r={r} onChange={(d) => apply(d, d.status.code === 'PENDING_APPROVAL' ? 'Compra enviada a aprobación.' : undefined)} />}

      {tab === 'approval' && (
        <Stack spacing={2.5}>
          {r.status.code === 'PENDING_APPROVAL' && selected && (
            <Card sx={{ p: 2.5, borderColor: 'primary.main', borderWidth: 2 }}>
              <Typography variant="h4" component="h3">
                Compra propuesta
              </Typography>
              <Typography variant="body1" sx={{ mt: 1 }}>
                <strong>{selected.supplier.name}</strong> por <strong className="num">{formatMoney(selected.total_clp)}</strong>
                {selected.delivery_days != null ? `, entrega en ${selected.delivery_days} días` : ''}.
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Propuesta por {r.proposed_by} el {formatDateTime(r.dates.proposed_at)}.
                {r.proposal_justification ? ` Justificación: ${r.proposal_justification}` : ''}
              </Typography>
              {a.decide ? (
                <>
                  <TextField label="Comentario (obligatorio si rechazas)" value={comment} onChange={(e) => setComment(e.target.value)} multiline minRows={2} fullWidth sx={{ mt: 2 }} inputProps={{ maxLength: 2000 }} />
                  <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                    <Button variant="contained" color="success" startIcon={<CheckCircleOutline />} disabled={act.isPending} onClick={() => act.mutate(() => procurementApi.decide(id, { decision: 'APPROVED', comment: comment || undefined }))}>
                      Aprobar compra
                    </Button>
                    <Button variant="outlined" color="error" startIcon={<HighlightOff />} disabled={act.isPending || comment.trim().length < 3} onClick={() => act.mutate(() => procurementApi.decide(id, { decision: 'REJECTED', comment }))}>
                      Rechazar y volver a cotizar
                    </Button>
                  </Stack>
                  <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
                    Tu decisión queda registrada con la versión {r.version} de la solicitud y el monto, y no se puede modificar.
                  </Typography>
                </>
              ) : (
                <Alert severity="info" sx={{ mt: 2 }}>
                  {a.decide_blocked_reason ?? 'Esperando la decisión de una persona con permiso de aprobación.'}
                </Alert>
              )}
            </Card>
          )}
          <Typography variant="h4" component="h3">
            Historial de decisiones
          </Typography>
          {r.approvals.length === 0 ? (
            <Typography variant="body2" color="text.secondary">
              Sin decisiones registradas.
            </Typography>
          ) : (
            r.approvals.map((ap) => (
              <Card key={ap.public_id} sx={{ p: 1.5 }}>
                <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                  <Chip size="small" label={ap.decision === 'APPROVED' ? 'Aprobada' : 'Rechazada'} color={ap.decision === 'APPROVED' ? 'success' : 'error'} />
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {ap.user}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {formatDateTime(ap.created_at)}, versión {ap.request_version}, monto {formatMoney(ap.amount_clp)}
                  </Typography>
                </Stack>
                {ap.comment && (
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                    {ap.comment}
                  </Typography>
                )}
              </Card>
            ))
          )}
        </Stack>
      )}

      <RequestFormDialog
        open={editOpen}
        editing={r}
        priorities={refs.data?.data.priorities ?? []}
        onClose={() => setEditOpen(false)}
        onSubmit={(b) => edit.mutate(b)}
        submitting={edit.isPending}
        errors={editErrors}
        error={null}
      />

      <OrderDialog
        open={orderOpen}
        r={r}
        onClose={() => setOrderOpen(false)}
        onIssued={(o) => {
          setOrderOpen(false)
          void qc.invalidateQueries({ queryKey: ['purchase-request', id] })
          void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
          navigate(`/ordenes?ficha=${o.public_id}`)
        }}
      />

      <Dialog open={cancelOpen} onClose={() => setCancelOpen(false)} fullWidth maxWidth="xs" PaperProps={{ sx: { borderRadius: '24px' } }}>
        <DialogTitle sx={{ fontWeight: 700 }}>Anular {r.number}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            La solicitud queda anulada con su historial. Para volver a comprar, crea una nueva.
          </Typography>
          <TextField label="Motivo" required autoFocus fullWidth value={reason} onChange={(e) => setReason(e.target.value)} multiline minRows={2} />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={() => setCancelOpen(false)}>Volver</Button>
          <Button
            color="error"
            variant="contained"
            disabled={reason.trim().length < 3 || act.isPending}
            onClick={() => {
              setCancelOpen(false)
              act.mutate(() => procurementApi.cancel(id, reason.trim()))
            }}
          >
            Anular solicitud
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
