import Alert from '@mui/material/Alert'
import Autocomplete from '@mui/material/Autocomplete'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Checkbox from '@mui/material/Checkbox'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import InputAdornment from '@mui/material/InputAdornment'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import BlockOutlined from '@mui/icons-material/BlockOutlined'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import RequestQuoteOutlined from '@mui/icons-material/RequestQuoteOutlined'
import SendOutlined from '@mui/icons-material/SendOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { FormDialog } from '../../components/FormDialog'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import { procurementApi } from '../../services/procurement'
import type { SupplierDto } from '../../types/crm'
import type { PurchaseRequestDetail, Quotation, QuotationInput } from '../../types/procurement'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatAmount, formatDate, formatMoney, todayInChile } from '../../utils/format'
import { qty } from './status'

const day = (d: string | null) => (d ? formatDate(`${d}T12:00:00`) : '—')

/** Pedidos de cotización y cotizaciones recibidas de una solicitud. */
export function QuotationsSection({ r, onChange }: { r: PurchaseRequestDetail; onChange: (d: PurchaseRequestDetail) => void }) {
  const qc = useQueryClient()
  const can = r.actions.manage_quotations
  const suppliers = useQuery({ queryKey: ['suppliers', 'all'], queryFn: () => crmApi.suppliers({ per_page: 100 }), enabled: can })
  const [ask, setAsk] = useState<{ supplier: SupplierDto | null; due_on: string }>({ supplier: null, due_on: '' })
  const [dialog, setDialog] = useState<string | null>(null) // supplier public_id para registrar
  const [error, setError] = useState<string | null>(null)

  const done = (d: { data: PurchaseRequestDetail }) => {
    onChange(d.data)
    setError(null)
    void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
  }
  const fail = (e: unknown) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')
  const requestQuote = useMutation({
    mutationFn: () => procurementApi.requestQuote(r.public_id, { supplier_public_id: ask.supplier!.public_id, due_on: ask.due_on || undefined }),
    onSuccess: (d) => {
      done(d)
      setAsk({ supplier: null, due_on: '' })
    },
    onError: fail,
  })
  const discard = useMutation({ mutationFn: (qid: string) => procurementApi.discardQuotation(r.public_id, qid), onSuccess: done, onError: fail })
  const remove = useMutation({ mutationFn: (qid: string) => procurementApi.deleteQuotation(r.public_id, qid), onSuccess: done, onError: fail })

  const asked = new Set(r.quotation_requests.map((q) => q.supplier.public_id))
  const quoted = new Set(r.quotations.map((q) => q.supplier.public_id))

  return (
    <Stack spacing={2.5}>
      {error && <Alert severity="error" onClose={() => setError(null)}>{error}</Alert>}

      <Card sx={{ p: 2.5 }}>
        <Typography variant="h4" component="h3" sx={{ mb: 0.5 }}>
          Proveedores consultados
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Registra a quién le pediste cotización. TIARE no envía correos al proveedor: anota aquí el pedido que hiciste por tu canal habitual.
        </Typography>
        {can && (
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: '2fr 1fr auto' }, mb: 2 }}>
            <Autocomplete
              options={(suppliers.data?.data ?? []).filter((s) => !asked.has(s.public_id))}
              getOptionLabel={(s) => s.organization.legal_name}
              value={ask.supplier}
              onChange={(_, v) => setAsk({ ...ask, supplier: v })}
              renderInput={(p) => <TextField {...p} label="Proveedor" />}
              noOptionsText="No hay más proveedores. Regístralos en Proveedores."
            />
            <TextField label="Responder antes de" type="date" value={ask.due_on} onChange={(e) => setAsk({ ...ask, due_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ min: todayInChile() }} />
            <Button variant="outlined" startIcon={<SendOutlined />} disabled={!ask.supplier || requestQuote.isPending} onClick={() => requestQuote.mutate()} sx={{ height: 40 }}>
              Registrar pedido
            </Button>
          </Box>
        )}
        {r.quotation_requests.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Aún no se ha pedido cotización a ningún proveedor.
          </Typography>
        ) : (
          <Stack spacing={1}>
            {r.quotation_requests.map((q) => (
              <Stack key={q.public_id} direction="row" alignItems="center" spacing={1.5} sx={{ p: 1.25, borderRadius: '10px', bgcolor: tokens.surfaceLow }}>
                <Typography variant="body2" sx={{ fontWeight: 600, flex: 1 }}>
                  {q.supplier.name}
                </Typography>
                {q.due_on && (
                  <Typography variant="caption" color="text.secondary">
                    Plazo {day(q.due_on)}
                  </Typography>
                )}
                <Chip size="small" label={q.status === 'RECEIVED' ? 'Cotización recibida' : 'Esperando respuesta'} color={q.status === 'RECEIVED' ? 'success' : 'default'} variant="outlined" />
                {can && !quoted.has(q.supplier.public_id) && (
                  <Button size="small" startIcon={<RequestQuoteOutlined />} onClick={() => setDialog(q.supplier.public_id)}>
                    Registrar cotización
                  </Button>
                )}
              </Stack>
            ))}
          </Stack>
        )}
      </Card>

      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h4" component="h3">
          Cotizaciones recibidas ({r.quotations.length})
        </Typography>
        {can && (
          <Button variant="contained" startIcon={<Add />} onClick={() => setDialog('')}>
            Registrar cotización
          </Button>
        )}
      </Stack>
      {r.quotations.length === 0 && (
        <Typography variant="body2" color="text.secondary">
          {r.status.code === 'QUOTING' ? 'Registra cada cotización que recibas para poder compararlas.' : 'Las cotizaciones se registran cuando la solicitud está en cotización.'}
        </Typography>
      )}
      {r.quotations.map((q) => (
        <QuotationCard
          key={q.public_id}
          q={q}
          r={r}
          onDiscard={can && q.status !== 'SELECTED' && q.status !== 'DISCARDED' ? () => discard.mutate(q.public_id) : undefined}
          onDelete={
            r.actions.delete_quotations && q.status !== 'SELECTED'
              ? () =>
                  window.confirm(`¿Eliminar la cotización de ${q.supplier.name}? Úsalo solo si se registró por error: desaparece de esta solicitud. Las comparaciones ya generadas no cambian; vuelve a comparar.`) &&
                  remove.mutate(q.public_id)
              : undefined
          }
        />
      ))}

      <QuotationDialog
        open={dialog !== null}
        r={r}
        presetSupplier={dialog || null}
        suppliers={suppliers.data?.data ?? []}
        onClose={() => setDialog(null)}
        onSaved={(d) => {
          done(d)
          setDialog(null)
        }}
      />
    </Stack>
  )
}

function QuotationCard({ q, r, onDiscard, onDelete }: { q: Quotation; r: PurchaseRequestDetail; onDiscard?: () => void; onDelete?: () => void }) {
  const money = (v: string | null) => formatAmount(v, q.currency)
  const recommended = r.comparison?.recommended_quotation_public_id === q.public_id
  return (
    <Card sx={{ p: 2, opacity: q.status === 'DISCARDED' ? 0.6 : 1, borderColor: q.status === 'SELECTED' ? 'primary.main' : undefined, borderWidth: q.status === 'SELECTED' ? 2 : 1 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1}>
        <Box>
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <Typography variant="subtitle1">{q.supplier.name}</Typography>
            {q.status === 'SELECTED' && <Chip size="small" color="primary" label="Propuesta" />}
            {recommended && <Chip size="small" color="success" variant="outlined" label="Recomendada" />}
            {q.status === 'DISCARDED' && <Chip size="small" label="Descartada" />}
            {q.expired && <Chip size="small" color="error" variant="outlined" label="Vencida" />}
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {[q.reference ? `Ref. ${q.reference}` : null, `recibida ${day(q.received_on)}`, q.valid_until ? `válida hasta ${day(q.valid_until)}` : 'sin vigencia informada', q.delivery_days != null ? `entrega en ${q.delivery_days} días` : null, q.warranty_months ? `garantía ${q.warranty_months} meses` : null, q.payment_terms]
              .filter(Boolean)
              .join(', ')}
          </Typography>
        </Box>
        <Box sx={{ textAlign: { sm: 'right' } }}>
          <Typography className="num" sx={{ fontWeight: 700, fontSize: '1.2rem' }}>
            {money(q.total)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {q.currency !== 'CLP' ? `${formatMoney(q.total_clp)} a ${Number(q.exchange_rate_to_clp)} CLP (${q.exchange_rate_source ?? 'fuente no indicada'})` : 'Total con IVA y despacho'}
          </Typography>
        </Box>
      </Stack>
      <Box sx={{ mt: 1.5 }}>
        <DataTable
          columns={[{ label: 'Ítem' }, { label: 'Cantidad', align: 'right' }, { label: 'Precio unitario', align: 'right' }, { label: 'Total', align: 'right' }]}
          rows={q.items.map((i) => ({
            key: i.line_number,
            cells: [
              <>
                {r.items.find((x) => x.line_number === i.line_number)?.description ?? `Línea ${i.line_number}`}
                {i.offered_brand && (
                  <Typography variant="caption" color="text.secondary" component="div">
                    Marca ofrecida: {i.offered_brand}
                  </Typography>
                )}
              </>,
              <span className="num">{qty(i.quantity)}</span>,
              <span className="num">{money(i.unit_price)}</span>,
              <span className="num">{money(i.line_total)}</span>,
            ],
          }))}
        />
      </Box>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 1.5 }}>
        <Typography variant="caption" color="text.secondary" className="num">
          Neto {money(q.subtotal_net)} + despacho {money(q.shipping_cost)} + IVA {Number(q.tax_rate)} % {money(q.tax_amount)}
          {q.prices_include_tax ? ' (precios informados con IVA)' : ''}
        </Typography>
        <Stack direction="row" spacing={0.5}>
          {onDiscard && (
            <Button size="small" color="inherit" startIcon={<BlockOutlined />} onClick={onDiscard}>
              Descartar
            </Button>
          )}
          {onDelete && (
            <Button size="small" color="error" startIcon={<DeleteOutline />} onClick={onDelete}>
              Eliminar
            </Button>
          )}
        </Stack>
      </Stack>
    </Card>
  )
}

interface DialogProps {
  open: boolean
  r: PurchaseRequestDetail
  presetSupplier: string | null
  suppliers: SupplierDto[]
  onClose: () => void
  onSaved: (d: { data: PurchaseRequestDetail }) => void
}

/** Registrar una cotización: condiciones y precio unitario por ítem. Los totales los calcula el servidor. */
function QuotationDialog({ open, r, presetSupplier, suppliers, onClose, onSaved }: DialogProps) {
  const [f, setF] = useState<Omit<QuotationInput, 'items'>>({ supplier_public_id: '', currency: 'CLP', tax_rate: '19', prices_include_tax: false, shipping_cost: '0' })
  const [prices, setPrices] = useState<Record<number, { unit_price: string; offered_brand: string }>>({})
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setF({ supplier_public_id: presetSupplier ?? '', currency: 'CLP', tax_rate: '19', prices_include_tax: false, shipping_cost: '0', valid_until: '', delivery_days: '', reference: '', payment_terms: '' })
      setPrices({})
      setErrors({})
      setError(null)
    }
  }
  const save = useMutation({
    mutationFn: () =>
      procurementApi.registerQuotation(r.public_id, {
        ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== undefined)),
        supplier_public_id: f.supplier_public_id,
        items: Object.entries(prices)
          .filter(([, v]) => v.unit_price !== '')
          .map(([line, v]) => ({ line_number: Number(line), unit_price: v.unit_price, offered_brand: v.offered_brand || undefined })),
      } as QuotationInput),
    onSuccess: onSaved,
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })

  const quotedLines = Object.values(prices).filter((p) => p.unit_price !== '').length
  const quotedSuppliers = new Set(r.quotations.map((q) => q.supplier.public_id))
  const foreign = f.currency !== 'CLP'

  return (
    <FormDialog
      open={open}
      title="Registrar cotización"
      onClose={onClose}
      onSubmit={() => save.mutate()}
      submitLabel="Guardar cotización"
      submitting={save.isPending}
      canSubmit={Boolean(f.supplier_public_id) && quotedLines > 0 && (!foreign || Number(f.exchange_rate_to_clp) > 0)}
      error={error}
    >
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr 1fr' } }}>
        <TextField select label="Proveedor" required value={f.supplier_public_id} onChange={(e) => setF({ ...f, supplier_public_id: e.target.value })} sx={{ gridColumn: { sm: '1 / 3' } }}>
          {suppliers
            .filter((s) => !quotedSuppliers.has(s.public_id) || s.public_id === presetSupplier)
            .map((s) => (
              <MenuItem key={s.public_id} value={s.public_id}>
                {s.organization.legal_name}
              </MenuItem>
            ))}
        </TextField>
        <TextField label="N° de cotización del proveedor" value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} />
        <TextField select label="Moneda" value={f.currency} onChange={(e) => setF({ ...f, currency: e.target.value })}>
          {['CLP', 'USD', 'EUR', 'CLF'].map((c) => (
            <MenuItem key={c} value={c}>
              {c === 'CLF' ? 'UF (CLF)' : c}
            </MenuItem>
          ))}
        </TextField>
        {foreign && (
          <>
            <TextField
              label="Tipo de cambio a CLP"
              required
              type="number"
              value={f.exchange_rate_to_clp ?? ''}
              onChange={(e) => setF({ ...f, exchange_rate_to_clp: e.target.value })}
              error={Boolean(fieldError(errors, 'exchange_rate_to_clp'))}
              helperText={fieldError(errors, 'exchange_rate_to_clp') ?? 'Necesario para comparar en pesos'}
            />
            <TextField label="Fuente del tipo de cambio" placeholder="Ej. dólar observado del día" value={f.exchange_rate_source ?? ''} onChange={(e) => setF({ ...f, exchange_rate_source: e.target.value })} />
          </>
        )}
        <TextField label="IVA" type="number" value={f.tax_rate} onChange={(e) => setF({ ...f, tax_rate: e.target.value })} InputProps={{ endAdornment: <InputAdornment position="end">%</InputAdornment> }} />
        <TextField label="Despacho (neto)" type="number" value={f.shipping_cost} onChange={(e) => setF({ ...f, shipping_cost: e.target.value })} />
        <TextField label="Plazo de entrega" type="number" value={f.delivery_days ?? ''} onChange={(e) => setF({ ...f, delivery_days: e.target.value })} InputProps={{ endAdornment: <InputAdornment position="end">días</InputAdornment> }} />
        <TextField label="Válida hasta" type="date" value={f.valid_until ?? ''} onChange={(e) => setF({ ...f, valid_until: e.target.value })} InputLabelProps={{ shrink: true }} />
        <TextField label="Garantía" type="number" value={f.warranty_months ?? ''} onChange={(e) => setF({ ...f, warranty_months: e.target.value })} InputProps={{ endAdornment: <InputAdornment position="end">meses</InputAdornment> }} />
        <TextField label="Condiciones de pago" value={f.payment_terms ?? ''} onChange={(e) => setF({ ...f, payment_terms: e.target.value })} />
        <FormControlLabel
          control={<Checkbox checked={Boolean(f.prices_include_tax)} onChange={(e) => setF({ ...f, prices_include_tax: e.target.checked })} />}
          label="Los precios informados incluyen IVA"
          sx={{ gridColumn: '1 / -1' }}
        />
      </Box>

      <Typography variant="subtitle2" color="primary" sx={{ mt: 2, mb: 1 }}>
        Precio unitario por ítem ({quotedLines} de {r.items.length})
      </Typography>
      <Stack spacing={1}>
        {r.items.map((i) => (
          <Box key={i.line_number} sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr 1fr' }, alignItems: 'center' }}>
            <Typography variant="body2">
              <strong>{i.line_number}.</strong> {i.description}{' '}
              <Typography component="span" variant="caption" color="text.secondary">
                ({qty(i.quantity)} {i.unit_code})
              </Typography>
            </Typography>
            <TextField
              label="Precio unitario"
              type="number"
              value={prices[i.line_number]?.unit_price ?? ''}
              onChange={(e) => setPrices({ ...prices, [i.line_number]: { offered_brand: prices[i.line_number]?.offered_brand ?? '', unit_price: e.target.value } })}
              inputProps={{ min: 0, step: 'any', 'aria-label': `Precio unitario de ${i.description}` }}
            />
            <TextField label="Marca ofrecida" value={prices[i.line_number]?.offered_brand ?? ''} onChange={(e) => setPrices({ ...prices, [i.line_number]: { unit_price: prices[i.line_number]?.unit_price ?? '', offered_brand: e.target.value } })} />
          </Box>
        ))}
      </Stack>
      {quotedLines < r.items.length && quotedLines > 0 && (
        <Alert severity="info" sx={{ mt: 1.5 }}>
          Una cotización que no cubre todos los ítems se guarda, pero no puede ser recomendada ni propuesta.
        </Alert>
      )}
    </FormDialog>
  )
}
