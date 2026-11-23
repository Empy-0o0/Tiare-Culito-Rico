import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import PaidOutlined from '@mui/icons-material/PaidOutlined'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { FormDialog } from '../../components/FormDialog'
import { ApiError } from '../../services/apiClient'
import { procurementApi, type InvoiceInput } from '../../services/procurement'
import type { InvoiceDto, OrderDetail } from '../../types/procurement'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatAmount, formatDate, todayInChile } from '../../utils/format'

const day = (d: string | null) => (d ? formatDate(`${d}T12:00:00`) : null)

/** Estado de una factura en una etiqueta. */
export function InvoiceStatusChip({ i }: { i: InvoiceDto }) {
  if (i.document_type.code === 'CREDIT_NOTE') return <Chip size="small" variant="outlined" label="Nota de crédito" />
  if (i.status === 'PAID') return <Chip size="small" color="success" variant="outlined" label={`Pagada ${day(i.paid_on) ?? ''}`} />
  if (i.overdue) return <Chip size="small" color="error" label="Vencida" />
  return <Chip size="small" variant="outlined" label="Por pagar" />
}

/** Facturas de una orden: registro, conciliación con el total y pago informado. */
export function InvoicesSection({ order, onChange }: { order: OrderDetail; onChange: (o: OrderDetail, msg: string) => void }) {
  const [open, setOpen] = useState(false)
  const [paying, setPaying] = useState<InvoiceDto | null>(null)
  const money = (v: string) => formatAmount(v, order.currency)
  const s = order.invoicing

  return (
    <Box>
      {order.invoices.length > 0 && (
        <Alert severity={s.matches ? 'success' : 'warning'} sx={{ mb: 1.5 }}>
          {s.matches
            ? `Facturado ${money(s.invoiced_total)}: coincide con el total de la orden.`
            : `Facturado ${money(s.invoiced_total)} de ${money(s.order_total)} (diferencia ${money(s.difference)}). Revisa si falta una factura o una nota de crédito.`}
        </Alert>
      )}
      {order.invoices.length === 0 && (
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
          Sin facturas registradas.
        </Typography>
      )}
      <Stack spacing={1}>
        {order.invoices.map((i) => (
          <Card key={i.public_id} sx={{ p: 1.5, bgcolor: '#fff' }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {i.document_type.label} N° <span className="code">{i.number}</span>
              </Typography>
              <InvoiceStatusChip i={i} />
              <Box sx={{ flex: 1 }} />
              <Typography className="num" sx={{ fontWeight: 700 }}>
                {i.document_type.code === 'CREDIT_NOTE' ? '−' : ''}
                {money(i.total)}
              </Typography>
            </Stack>
            <Typography variant="caption" color="text.secondary">
              {[`emitida ${day(i.issued_on)}`, i.due_on ? `vence ${day(i.due_on)}` : null, i.status === 'PAID' ? `pago informado por ${i.paid_by_label}${i.payment_reference ? ` (${i.payment_reference})` : ''}` : null]
                .filter(Boolean)
                .join(', ')}
            </Typography>
            {order.actions.inform_payment && i.status !== 'PAID' && i.document_type.code !== 'CREDIT_NOTE' && (
              <Box>
                <Button size="small" startIcon={<PaidOutlined />} onClick={() => setPaying(i)} sx={{ mt: 0.5 }}>
                  Informar pago
                </Button>
              </Box>
            )}
          </Card>
        ))}
      </Stack>
      {order.actions.register_invoice && (
        <Button variant="outlined" startIcon={<Add />} onClick={() => setOpen(true)} sx={{ mt: 1.5 }}>
          Registrar factura
        </Button>
      )}
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
        Registro administrativo: no reemplaza la contabilidad ni el registro en el SII.
      </Typography>

      <InvoiceDialog open={open} order={order} onClose={() => setOpen(false)} onSaved={(o) => (setOpen(false), onChange(o, 'Factura registrada.'))} />
      <PaymentDialog
        invoice={paying}
        defaultPayer={order.responsibles.payer_name}
        onClose={() => setPaying(null)}
        onSaved={() => {
          setPaying(null)
          void procurementApi.order(order.public_id).then((r) => onChange(r.data, 'Pago informado.'))
        }}
      />
    </Box>
  )
}

function InvoiceDialog({ open, order, onClose, onSaved }: { open: boolean; order: OrderDetail; onClose: () => void; onSaved: (o: OrderDetail) => void }) {
  const blank = (): InvoiceInput & { taxEdited?: boolean } => ({ number: '', document_type: 'INVOICE', issued_on: todayInChile(), due_on: '', net_amount: '', tax_amount: '' })
  const [f, setF] = useState(blank)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setF(blank())
      setErrors({})
      setError(null)
    }
  }
  // IVA sugerido (19 %) en facturas afectas y notas de crédito; editable.
  const suggestedTax = (net: string, type?: string) => (type === 'EXEMPT_INVOICE' || net === '' ? '0' : String(Math.round(Number(net) * 0.19)))
  const save = useMutation({
    mutationFn: () => procurementApi.registerInvoice(order.public_id, { number: f.number.trim(), document_type: f.document_type, issued_on: f.issued_on, due_on: f.due_on || undefined, net_amount: f.net_amount, tax_amount: f.tax_amount || '0', notes: f.notes || undefined }),
    onSuccess: (r) => onSaved(r.data),
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible registrar la factura.')
    },
  })
  const total = Number(f.net_amount || 0) + Number(f.tax_amount || 0)

  return (
    <FormDialog open={open} title={`Registrar factura de ${order.supplier.name}`} onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Registrar factura" submitting={save.isPending} canSubmit={f.number.trim().length > 0 && Number(f.net_amount) >= 0 && f.net_amount !== ''} error={error}>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField
          select
          label="Tipo de documento"
          value={f.document_type}
          onChange={(e) => setF({ ...f, document_type: e.target.value, tax_amount: f.taxEdited ? f.tax_amount : suggestedTax(f.net_amount, e.target.value) })}
        >
          <MenuItem value="INVOICE">Factura afecta</MenuItem>
          <MenuItem value="EXEMPT_INVOICE">Factura exenta</MenuItem>
          <MenuItem value="CREDIT_NOTE">Nota de crédito</MenuItem>
        </TextField>
        <TextField label="Folio" required value={f.number} onChange={(e) => setF({ ...f, number: e.target.value })} error={Boolean(fieldError(errors, 'number'))} helperText={fieldError(errors, 'number')} />
        <TextField label="Emisión" type="date" value={f.issued_on} onChange={(e) => setF({ ...f, issued_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} />
        <TextField label="Vencimiento" type="date" value={f.due_on} onChange={(e) => setF({ ...f, due_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ min: f.issued_on }} error={Boolean(fieldError(errors, 'due_on'))} helperText={fieldError(errors, 'due_on')} />
        <TextField
          label={`Monto neto (${order.currency})`}
          type="number"
          required
          value={f.net_amount}
          onChange={(e) => setF({ ...f, net_amount: e.target.value, tax_amount: f.taxEdited ? f.tax_amount : suggestedTax(e.target.value, f.document_type) })}
        />
        <TextField label="IVA" type="number" value={f.tax_amount} onChange={(e) => setF({ ...f, tax_amount: e.target.value, taxEdited: true })} helperText="Sugerido: 19 % del neto (exenta: 0)" />
      </Box>
      <Typography variant="body2" sx={{ mt: 2 }}>
        Total del documento: <strong className="num">{formatAmount(String(total), order.currency)}</strong>. Total de la orden: <span className="num">{formatAmount(order.total, order.currency)}</span>.
      </Typography>
    </FormDialog>
  )
}

/** Informar el pago de una factura (lo informa la empresa o el cliente; TIARE no paga ni verifica). */
export function PaymentDialog({ invoice, defaultPayer, onClose, onSaved }: { invoice: InvoiceDto | null; defaultPayer?: string; onClose: () => void; onSaved: (i: InvoiceDto) => void }) {
  const [f, setF] = useState({ paid_on: todayInChile(), paid_by_label: '', payment_reference: '' })
  const [error, setError] = useState<string | null>(null)
  const [last, setLast] = useState<string | null>(null)
  if ((invoice?.public_id ?? null) !== last) {
    setLast(invoice?.public_id ?? null)
    if (invoice) {
      setF({ paid_on: todayInChile(), paid_by_label: defaultPayer ?? '', payment_reference: '' })
      setError(null)
    }
  }
  const save = useMutation({
    mutationFn: () => procurementApi.informPayment(invoice!.public_id, { paid_on: f.paid_on, paid_by_label: f.paid_by_label.trim(), payment_reference: f.payment_reference || undefined }),
    onSuccess: (r) => onSaved(r.data),
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible informar el pago.'),
  })

  return (
    <FormDialog open={Boolean(invoice)} title={`Informar pago de la factura ${invoice?.number ?? ''}`} onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Informar pago" submitting={save.isPending} canSubmit={f.paid_by_label.trim().length >= 2 && Boolean(f.paid_on)} error={error}>
      {invoice && (
        <Typography variant="body1" sx={{ mb: 2 }}>
          {invoice.supplier ? `${invoice.supplier}, ` : ''}
          <strong className="num">{formatAmount(invoice.total, invoice.currency)}</strong>
        </Typography>
      )}
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField label="Fecha de pago" type="date" value={f.paid_on} onChange={(e) => setF({ ...f, paid_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} />
        <TextField label="Quién informa el pago" required value={f.paid_by_label} onChange={(e) => setF({ ...f, paid_by_label: e.target.value })} helperText="Empresa o cliente que pagó" />
        <TextField label="Referencia (transferencia, comprobante)" value={f.payment_reference} onChange={(e) => setF({ ...f, payment_reference: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
      </Box>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
        TIARE registra el pago tal como se informa; no lo verifica con el banco.
      </Typography>
    </FormDialog>
  )
}
