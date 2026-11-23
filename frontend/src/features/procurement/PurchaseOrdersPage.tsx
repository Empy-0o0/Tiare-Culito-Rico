import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Checkbox from '@mui/material/Checkbox'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import LinearProgress from '@mui/material/LinearProgress'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import BlockOutlined from '@mui/icons-material/BlockOutlined'
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined'
import ReportProblemOutlined from '@mui/icons-material/ReportProblemOutlined'
import SendOutlined from '@mui/icons-material/SendOutlined'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import MenuItem from '@mui/material/MenuItem'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { inventoryApi } from '../../services/logistics'
import { DataOriginTag, StatusBadge } from '../../components/DataBadges'
import { DeleteRecordButton } from '../../components/DeleteRecordButton'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { procurementApi } from '../../services/procurement'
import type { OrderDetail } from '../../types/procurement'
import { formatAmount, formatDate, formatDateTime, todayInChile } from '../../utils/format'
import { InvoicesSection } from './InvoicesSection'
import { ORDER_TONE, qty } from './status'

const day = (d: string | null) => (d ? formatDate(`${d}T12:00:00`) : null)

/** Órdenes de compra internas: seguimiento, envío al proveedor y recepción. */
export function PurchaseOrdersPage() {
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [toast, setToast] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)
  const selected = params.get('ficha')
  const openDetail = (v: string | null) => {
    const next = new URLSearchParams(params)
    if (v) next.set('ficha', v)
    else next.delete('ficha')
    setParams(next, { replace: true })
  }

  const refs = useQuery({ queryKey: ['procurement-refs'], queryFn: procurementApi.references, staleTime: Infinity })
  const list = useQuery({
    queryKey: ['purchase-orders-internal', debouncedQ, status, page],
    queryFn: () => procurementApi.orders({ q: debouncedQ.trim() || undefined, status: status || undefined, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })
  const rows = list.data?.data ?? []

  return (
    <>
      <PageHeader title="Órdenes de compra" subtitle="Órdenes internas emitidas desde solicitudes aprobadas: envío al proveedor, recepción e incidencias." />

      <FilterBar count={list.data?.meta.pagination ? `${list.data.meta.pagination.total.toLocaleString('es-CL')} órdenes` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            setPage(1)
          }}
          placeholder="Número, proveedor o solicitud"
          label="Buscar órdenes"
        />
        <SelectField
          label="Estado"
          value={status}
          onChange={(v) => {
            setStatus(v)
            setPage(1)
          }}
          allLabel="Todos"
          options={(refs.data?.data.order_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))}
        />
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="las órdenes" />}
      {list.isLoading && <ListSkeleton rows={4} />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState
          title={q || status ? 'Ninguna orden coincide' : 'Aún no hay órdenes de compra'}
          action={
            !q && !status ? (
              <Button variant="outlined" component={Link} to="/solicitudes">
                Ir a solicitudes
              </Button>
            ) : undefined
          }
        >
          {q || status ? 'Prueba con otra búsqueda o estado.' : 'Las órdenes se emiten desde una solicitud aprobada.'}
        </EmptyState>
      )}

      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Orden' }, { label: 'Proveedor', hideOnMobile: true }, { label: 'Total', align: 'right' }, { label: 'Entrega', hideOnMobile: true }, { label: 'Estado' }]}
          rows={rows.map((o) => ({
            key: o.public_id,
            label: `Abrir ${o.number}`,
            onClick: () => openDetail(o.public_id),
            cells: [
              <>
                <Typography className="code" sx={{ color: 'primary.dark' }}>
                  {o.number}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {o.request.number}: {o.request.title}
                </Typography>
              </>,
              o.supplier.name,
              <span className="num">{formatAmount(o.total, o.currency)}</span>,
              day(o.expected_delivery_on) ?? '—',
              <StatusBadge label={o.status.label} className={ORDER_TONE[o.status.code] ?? 'draft'} />,
            ],
          }))}
        />
      )}
      {list.data?.meta.pagination && <Pager page={list.data.meta.pagination.page} lastPage={list.data.meta.pagination.last_page} onChange={setPage} />}

      <OrderDrawer
        orderId={selected}
        onClose={() => openDetail(null)}
        onChanged={(msg) => {
          setToast(msg)
          void qc.invalidateQueries({ queryKey: ['purchase-orders-internal'] })
          void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
        }}
      />
      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

function OrderDrawer({ orderId, onClose, onChanged }: { orderId: string | null; onClose: () => void; onChanged: (msg: string) => void }) {
  const qc = useQueryClient()
  const query = useQuery({ queryKey: ['purchase-order-internal', orderId], queryFn: () => procurementApi.order(orderId!), enabled: Boolean(orderId) })
  const [receiving, setReceiving] = useState(false)
  const [cancelReason, setCancelReason] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const o = query.data?.data

  const set = (d: OrderDetail, msg: string) => {
    qc.setQueryData(['purchase-order-internal', orderId], { data: d, meta: query.data?.meta })
    setError(null)
    onChanged(msg)
  }
  const fail = (e: unknown) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')
  const sent = useMutation({ mutationFn: () => procurementApi.markSent(orderId!), onSuccess: (d) => set(d.data, 'Orden marcada como enviada.'), onError: fail })
  const cancel = useMutation({
    mutationFn: () => procurementApi.cancelOrder(orderId!, (cancelReason ?? '').trim()),
    onSuccess: (d) => {
      setCancelReason(null)
      set(d.data, 'Orden anulada.')
    },
    onError: fail,
  })
  const money = (v: string | null) => formatAmount(v, o?.currency)

  return (
    <Drawer
      open={Boolean(orderId)}
      onClose={onClose}
      code={o?.number ?? ''}
      title={o ? `${o.supplier.name}` : undefined}
      footer={
        o && (
          <>
            {o.actions.delete && (
              <DeleteRecordButton
                noun="orden"
                name={`${o.number} (${o.supplier.name})`}
                detail={o.status.code === 'CANCELLED' ? 'La solicitud sigue anulada.' : `La solicitud ${o.request.number} vuelve a "Aprobada" para emitir una orden nueva. Si la orden ya se envió al proveedor, avísale. El número ${o.number} no se reutiliza.`}
                onDelete={() => procurementApi.deleteOrder(o.public_id)}
                onDeleted={() => {
                  onChanged(`Orden ${o.number} eliminada.`)
                  onClose()
                }}
              />
            )}
            {o.actions.cancel && (
              <Button color="inherit" startIcon={<BlockOutlined />} onClick={() => setCancelReason('')}>
                Anular
              </Button>
            )}
            {o.actions.mark_sent && (
              <Button variant="outlined" startIcon={<SendOutlined />} onClick={() => sent.mutate()} disabled={sent.isPending}>
                Marcar como enviada
              </Button>
            )}
            {o.actions.receive && (
              <Button variant="contained" startIcon={<Inventory2Outlined />} onClick={() => setReceiving(true)}>
                Registrar recepción
              </Button>
            )}
          </>
        )
      }
    >
      {query.isLoading && <LinearProgress sx={{ borderRadius: '4px' }} />}
      {query.error && <ErrorState error={query.error} what="la orden" />}
      {error && (
        <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}
      {o && (
        <>
          <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
            <StatusBadge label={o.status.label} className={ORDER_TONE[o.status.code] ?? 'draft'} />
            <DataOriginTag origin="client" />
            <Chip size="small" variant="outlined" component={Link} to={`/solicitudes/${o.request.public_id}`} clickable label={`Solicitud ${o.request.number}`} />
          </Stack>
          {o.status.code === 'CANCELLED' && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              Anulada el {formatDateTime(o.cancelled_at)}: {o.cancel_reason}
            </Alert>
          )}
          <Facts
            rows={[
              ['Total', <span className="num">{money(o.total)}</span>],
              ['Neto + despacho + IVA', <span className="num">{`${money(o.subtotal_net)} + ${money(o.shipping_cost)} + ${money(o.tax_amount)}`}</span>],
              ['Emitida', `${formatDateTime(o.issued_at)}${o.issued_by ? ` por ${o.issued_by}` : ''}`],
              ['Enviada al proveedor', o.sent_at ? formatDateTime(o.sent_at) : 'Aún no'],
              ['Entrega esperada', day(o.expected_delivery_on)],
              ['Condiciones de pago', o.payment_terms],
            ]}
          />
          <DetailSection title="Responsables">
            <Facts
              rows={[
                ['Quién paga', o.responsibles.payer_name],
                ['Comprador contractual', o.responsibles.contractual_buyer],
                ['Factura a nombre de', `${o.responsibles.invoice_holder_name}${o.responsibles.invoice_holder_rut ? ` (${o.responsibles.invoice_holder_rut})` : ''}`],
                ['Recibe', o.responsibles.receiver_name],
                ['Dirección de entrega', o.delivery_address],
              ]}
            />
          </DetailSection>
          <DetailSection title="Ítems y avance de recepción">
            <DataTable
              columns={[{ label: 'Ítem' }, { label: 'Pedido', align: 'right' }, { label: 'Recibido', align: 'right' }, { label: 'Total', align: 'right' }]}
              rows={o.items.map((i) => {
                const done = Number(i.received_quantity) >= Number(i.quantity)
                return {
                  key: i.line_number,
                  cells: [
                    i.description,
                    <span className="num">
                      {qty(i.quantity)} {i.unit_code}
                    </span>,
                    <Typography variant="body2" className="num" sx={{ color: done ? tokens.success : undefined, fontWeight: done ? 700 : 400 }}>
                      {qty(i.received_quantity)}
                    </Typography>,
                    <span className="num">{money(i.line_total)}</span>,
                  ],
                }
              })}
            />
          </DetailSection>
          <DetailSection title={`Recepciones (${o.receipts.length})`}>
            {o.receipts.length === 0 ? (
              <Typography variant="body2" color="text.secondary">
                Sin recepciones registradas.
              </Typography>
            ) : (
              <Stack spacing={1}>
                {o.receipts.map((g) => (
                  <Card key={g.public_id} sx={{ p: 1.5, bgcolor: '#fff' }}>
                    <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                      <Chip size="small" label={g.has_incident ? 'Con incidencia' : 'Conforme'} color={g.has_incident ? 'error' : 'success'} icon={g.has_incident ? <ReportProblemOutlined /> : undefined} variant="outlined" />
                      <Typography variant="caption" color="text.secondary">
                        {[day(g.received_on), g.document_reference ? `guía ${g.document_reference}` : null, g.received_by ? `por ${g.received_by}` : null].filter(Boolean).join(', ')}
                      </Typography>
                    </Stack>
                    <Typography variant="body2" sx={{ mt: 0.5 }}>
                      {g.items
                        .map((i) => `Línea ${i.line_number}: ${qty(i.quantity_received)} recibidas${Number(i.quantity_rejected) > 0 ? `, ${qty(i.quantity_rejected)} rechazadas` : ''}${i.incident_note ? ` (${i.incident_note})` : ''}`)
                        .join('; ')}
                    </Typography>
                    {g.notes && (
                      <Typography variant="caption" color="text.secondary">
                        {g.notes}
                      </Typography>
                    )}
                  </Card>
                ))}
              </Stack>
            )}
          </DetailSection>

          <DetailSection title={`Facturas (${o.invoices.length})`}>
            <InvoicesSection order={o} onChange={(d, msg) => set(d, msg)} />
          </DetailSection>

          <ReceiptDialog
            open={receiving}
            order={o}
            onClose={() => setReceiving(false)}
            onSaved={(d) => {
              setReceiving(false)
              set(d, d.status.code === 'RECEIVED' ? 'Recepción completa: la orden quedó recibida.' : 'Recepción parcial registrada.')
            }}
          />
          <FormDialog
            open={cancelReason !== null}
            title={`Anular ${o.number}`}
            onClose={() => setCancelReason(null)}
            onSubmit={() => cancel.mutate()}
            submitLabel="Anular orden"
            submitting={cancel.isPending}
            canSubmit={(cancelReason ?? '').trim().length >= 3}
          >
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              Anular la orden también anula la solicitud {o.request.number}. Para volver a comprar se crea una solicitud nueva.
            </Typography>
            <TextField label="Motivo" required fullWidth autoFocus value={cancelReason ?? ''} onChange={(e) => setCancelReason(e.target.value)} multiline minRows={2} />
          </FormDialog>
        </>
      )}
    </Drawer>
  )
}

/** Registrar lo recibido (parcial o total), con rechazos e incidencias por línea. */
function ReceiptDialog({ open, order, onClose, onSaved }: { open: boolean; order: OrderDetail; onClose: () => void; onSaved: (o: OrderDetail) => void }) {
  const pending = (i: OrderDetail['items'][number]) => Math.max(0, Number(i.quantity) - Number(i.received_quantity))
  const init = () => Object.fromEntries(order.items.map((i) => [i.line_number, { received: String(pending(i)), rejected: '0', note: '' }]))
  const [lines, setLines] = useState<Record<number, { received: string; rejected: string; note: string }>>(init)
  const [head, setHead] = useState({ received_on: todayInChile(), document_reference: '', has_incident: false, notes: '', warehouse: '' })
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)
  const { can } = useAuth()
  const canStock = can('inventory.manage')
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: inventoryApi.warehouses, enabled: open && canStock })
  const activeWarehouses = (warehouses.data?.data ?? []).filter((w) => w.is_active)
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setLines(init())
      setHead({ received_on: todayInChile(), document_reference: '', has_incident: false, notes: '', warehouse: '' })
      setError(null)
    }
  }

  const save = useMutation({
    mutationFn: () =>
      procurementApi.receive(order.public_id, {
        received_on: head.received_on,
        document_reference: head.document_reference || undefined,
        has_incident: head.has_incident,
        notes: head.notes || undefined,
        warehouse_public_id: head.warehouse || undefined,
        items: Object.entries(lines)
          .filter(([, l]) => Number(l.received) > 0 || Number(l.rejected) > 0)
          .map(([n, l]) => ({ line_number: Number(n), quantity_received: l.received || '0', quantity_rejected: l.rejected || '0', incident_note: l.note || undefined })),
      }),
    onSuccess: (d) => onSaved(d.data),
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible registrar la recepción.'),
  })

  const over = order.items.some((i) => Number(lines[i.line_number]?.received ?? 0) > pending(i))
  const any = Object.values(lines).some((l) => Number(l.received) > 0 || Number(l.rejected) > 0)
  const incidentNeedsNote = head.has_incident && head.notes.trim().length === 0

  return (
    <FormDialog open={open} title={`Registrar recepción de ${order.number}`} onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Registrar recepción" submitting={save.isPending} canSubmit={any && !over && !incidentNeedsNote} error={error}>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, mb: 2 }}>
        <TextField label="Fecha de recepción" type="date" value={head.received_on} onChange={(e) => setHead({ ...head, received_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} />
        <TextField label="Guía de despacho" value={head.document_reference} onChange={(e) => setHead({ ...head, document_reference: e.target.value })} />
        {canStock && activeWarehouses.length > 0 && (
          <TextField
            select
            label="Ingresar a bodega"
            value={head.warehouse}
            onChange={(e) => setHead({ ...head, warehouse: e.target.value })}
            helperText="Lo recibido conforme de productos del catálogo suma stock en esa bodega."
            sx={{ gridColumn: '1 / -1' }}
          >
            <MenuItem value="">No ingresar a inventario</MenuItem>
            {activeWarehouses.map((w) => (
              <MenuItem key={w.public_id} value={w.public_id}>
                {w.name}
              </MenuItem>
            ))}
          </TextField>
        )}
      </Box>
      <Stack spacing={1.5}>
        {order.items.map((i) => {
          const l = lines[i.line_number] ?? { received: '0', rejected: '0', note: '' }
          const p = pending(i)
          return (
            <Box key={i.line_number} sx={{ p: 1.5, borderRadius: '12px', border: `1px solid ${tokens.outlineVariant}`, bgcolor: p === 0 ? tokens.surfaceHigh : tokens.surfaceLow }}>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {i.line_number}. {i.description}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                Pedido {qty(i.quantity)} {i.unit_code}, recibido {qty(i.received_quantity)}, pendiente {qty(p)}
              </Typography>
              {p > 0 && (
                <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr 1fr', sm: '1fr 1fr 2fr' }, mt: 1 }}>
                  <TextField
                    label="Recibido conforme"
                    type="number"
                    value={l.received}
                    onChange={(e) => setLines({ ...lines, [i.line_number]: { ...l, received: e.target.value } })}
                    inputProps={{ min: 0, max: p, step: 'any' }}
                    error={Number(l.received) > p}
                    helperText={Number(l.received) > p ? `Máximo ${qty(p)}` : undefined}
                  />
                  <TextField label="Rechazado" type="number" value={l.rejected} onChange={(e) => setLines({ ...lines, [i.line_number]: { ...l, rejected: e.target.value } })} inputProps={{ min: 0, step: 'any' }} />
                  <TextField label="Nota de incidencia" value={l.note} onChange={(e) => setLines({ ...lines, [i.line_number]: { ...l, note: e.target.value } })} sx={{ gridColumn: { xs: '1 / -1', sm: 'auto' } }} />
                </Box>
              )}
            </Box>
          )
        })}
      </Stack>
      <FormControlLabel control={<Checkbox checked={head.has_incident} onChange={(e) => setHead({ ...head, has_incident: e.target.checked })} />} label="Hubo una incidencia general (atraso, embalaje, documentación)" sx={{ mt: 1 }} />
      <TextField
        label={head.has_incident ? 'Describe la incidencia' : 'Observaciones (opcional)'}
        required={head.has_incident}
        value={head.notes}
        onChange={(e) => setHead({ ...head, notes: e.target.value })}
        multiline
        minRows={2}
        fullWidth
        error={incidentNeedsNote}
      />
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
        Las recepciones no se modifican. Lo rechazado no cuenta como recibido y queda pendiente.
      </Typography>
    </FormDialog>
  )
}
