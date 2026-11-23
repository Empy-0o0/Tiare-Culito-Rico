import Box from '@mui/material/Box'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { FormDialog } from '../../components/FormDialog'
import { ApiError } from '../../services/apiClient'
import { companyApi } from '../../services/company'
import { procurementApi, type OrderInput } from '../../services/procurement'
import type { OrderDetail, PurchaseRequestDetail } from '../../types/procurement'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatMoney, todayInChile } from '../../utils/format'
import { isValidRut } from '../../utils/rut'

/**
 * Emitir la orden interna desde la cotización aprobada. Precios y condiciones se copian de la
 * cotización; aquí solo se definen los responsables (CLAUDE.md §5.4: TIARE no financia la compra).
 */
export function OrderDialog({ open, r, onClose, onIssued }: { open: boolean; r: PurchaseRequestDetail; onClose: () => void; onIssued: (o: OrderDetail) => void }) {
  const company = useQuery({ queryKey: ['company'], queryFn: companyApi.get, enabled: open })
  const [f, setF] = useState<OrderInput>({ payer_name: '', invoice_holder_name: '', contractual_buyer: '', receiver_name: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)

  const c = company.data?.data
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setErrors({})
      setError(null)
    }
  }
  // Valores sugeridos: la empresa activa (o el cliente final, si la solicitud es por encargo).
  if (open && c && !f.payer_name && !f.invoice_holder_name) {
    const name = r.customer ?? c.legal_name ?? c.name
    setF({ ...f, payer_name: name, invoice_holder_name: name, invoice_holder_rut: r.customer ? '' : (c.rut ?? ''), contractual_buyer: name, delivery_address: r.delivery_address ?? '' })
  }

  const issue = useMutation({
    mutationFn: () => procurementApi.issueOrder(r.public_id, Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== undefined)) as OrderInput),
    onSuccess: (d) => onIssued(d.data),
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible emitir la orden.')
    },
  })

  const selected = r.quotations.find((q) => q.public_id === r.selected_quotation_public_id)
  const rutBad = Boolean(f.invoice_holder_rut && !isValidRut(f.invoice_holder_rut))
  const ok = [f.payer_name, f.invoice_holder_name, f.contractual_buyer, f.receiver_name].every((v) => v.trim().length >= 2) && !rutBad
  const field = (k: keyof OrderInput, label: string, help?: string, required = true) => (
    <TextField
      label={label}
      required={required}
      value={f[k] ?? ''}
      onChange={(e) => setF({ ...f, [k]: e.target.value })}
      error={Boolean(fieldError(errors, k)) || (k === 'invoice_holder_rut' && rutBad)}
      helperText={fieldError(errors, k) ?? (k === 'invoice_holder_rut' && rutBad ? 'RUT no válido.' : help)}
    />
  )

  return (
    <FormDialog open={open} title="Emitir orden de compra interna" onClose={onClose} onSubmit={() => issue.mutate()} submitLabel="Emitir orden" submitting={issue.isPending} canSubmit={ok} error={error}>
      {selected && (
        <Typography variant="body1" sx={{ mb: 2 }}>
          A <strong>{selected.supplier.name}</strong> por <strong className="num">{formatMoney(selected.total_clp)}</strong> ({r.items.length} ítems, precios de la cotización aprobada).
        </Typography>
      )}
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        {field('payer_name', 'Quién paga', 'Responsable del pago al proveedor')}
        {field('contractual_buyer', 'Comprador contractual', 'A nombre de quién se contrata')}
        {field('invoice_holder_name', 'Factura a nombre de')}
        {field('invoice_holder_rut', 'RUT de facturación', undefined, false)}
        {field('receiver_name', 'Responsable de recepción', 'Persona que recibe y da conformidad')}
        <TextField label="Entrega esperada" type="date" value={f.expected_delivery_on ?? ''} onChange={(e) => setF({ ...f, expected_delivery_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ min: todayInChile() }} />
        <TextField label="Dirección de entrega" value={f.delivery_address ?? ''} onChange={(e) => setF({ ...f, delivery_address: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
        <TextField label="Notas para el proveedor" value={f.notes ?? ''} onChange={(e) => setF({ ...f, notes: e.target.value })} multiline minRows={2} sx={{ gridColumn: '1 / -1' }} />
      </Box>
      <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
        TIARE no financia la compra: estos responsables quedan registrados en la orden. Envía la orden al proveedor por tu canal habitual y márcala como enviada.
      </Typography>
    </FormDialog>
  )
}
