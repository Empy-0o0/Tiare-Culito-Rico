import Autocomplete from '@mui/material/Autocomplete'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { tokens } from '../../app/theme'
import { FormDialog } from '../../components/FormDialog'
import { crmApi } from '../../services/crm'
import type { ProductDto } from '../../types/crm'
import type { PurchaseRequestDetail, RequestInput } from '../../types/procurement'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatMoney, todayInChile } from '../../utils/format'
import { FormGrid, FormSectionTitle } from '../crm/OrganizationFields'
import { useCrmReferences } from '../crm/useCrmReferences'

interface Line {
  key: number
  product: ProductDto | null
  description: string
  quantity: string
  unit_code: string
  estimated_unit_price: string
}

let seq = 0
const blankLine = (): Line => ({ key: ++seq, product: null, description: '', quantity: '1', unit_code: 'EA', estimated_unit_price: '' })

interface Props {
  open: boolean
  editing: PurchaseRequestDetail | null
  priorities: Array<{ code: string; label: string }>
  onClose: () => void
  onSubmit: (body: RequestInput) => void
  submitting: boolean
  errors: FieldErrors
  error: string | null
}

/** Crear o editar una solicitud de compra: datos generales e ítems (del catálogo o texto libre). */
export function RequestFormDialog({ open, editing, priorities, onClose, onSubmit, submitting, errors, error }: Props) {
  const { references } = useCrmReferences()
  const products = useQuery({ queryKey: ['products', 'all-active'], queryFn: () => crmApi.products({ per_page: 100 }), enabled: open })
  const customers = useQuery({ queryKey: ['customers', 'all'], queryFn: () => crmApi.customers({ per_page: 100 }), enabled: open })
  const [form, setForm] = useState({ title: '', description: '', priority: 'NORMAL', needed_by: '', cost_center: '', delivery_address: '', customer_public_id: '' })
  const [lines, setLines] = useState<Line[]>([blankLine()])

  useEffect(() => {
    if (!open) return
    if (editing) {
      setForm({
        title: editing.title,
        description: editing.description ?? '',
        priority: editing.priority.code,
        needed_by: editing.needed_by ?? '',
        cost_center: editing.cost_center ?? '',
        delivery_address: editing.delivery_address ?? '',
        customer_public_id: editing.customer_public_id ?? '',
      })
      setLines(
        editing.items.map((i) => ({
          key: ++seq,
          product: (products.data?.data ?? []).find((p) => p.public_id === i.product_public_id) ?? null,
          description: i.description,
          quantity: String(Number(i.quantity)),
          unit_code: i.unit_code,
          estimated_unit_price: i.estimated_unit_price ? String(Number(i.estimated_unit_price)) : '',
        })),
      )
    } else {
      setForm({ title: '', description: '', priority: 'NORMAL', needed_by: '', cost_center: '', delivery_address: '', customer_public_id: '' })
      setLines([blankLine()])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing])

  const setLine = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const validLines = lines.filter((l) => (l.product || l.description.trim().length >= 2) && Number(l.quantity) > 0)
  const estimate = lines.every((l) => l.estimated_unit_price !== '') ? lines.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.estimated_unit_price || 0), 0) : null
  const canSubmit = form.title.trim().length >= 3 && validLines.length === lines.length && lines.length > 0

  const submit = () =>
    onSubmit({
      title: form.title.trim(),
      description: form.description || undefined,
      priority: form.priority,
      needed_by: form.needed_by || undefined,
      cost_center: form.cost_center || undefined,
      delivery_address: form.delivery_address || undefined,
      customer_public_id: form.customer_public_id || null,
      items: lines.map((l) => ({
        product_public_id: l.product?.public_id ?? null,
        description: l.description || l.product?.name,
        quantity: l.quantity,
        unit_code: l.unit_code,
        estimated_unit_price: l.estimated_unit_price === '' ? null : l.estimated_unit_price,
      })),
    })

  return (
    <FormDialog
      open={open}
      title={editing ? `Editar ${editing.number}` : 'Nueva solicitud de compra'}
      onClose={onClose}
      onSubmit={submit}
      submitLabel={editing ? 'Guardar cambios' : 'Guardar borrador'}
      submitting={submitting}
      canSubmit={canSubmit}
      error={error}
    >
      <FormGrid>
        <FormSectionTitle>¿Qué necesitas?</FormSectionTitle>
        <TextField
          label="Título"
          required
          autoFocus
          placeholder="Ej. Insumos de aseo para bodega central"
          value={form.title}
          onChange={(e) => setForm({ ...form, title: e.target.value })}
          inputProps={{ maxLength: 200 }}
          error={Boolean(fieldError(errors, 'title'))}
          helperText={fieldError(errors, 'title')}
          sx={{ gridColumn: '1 / -1' }}
        />
        <TextField select label="Prioridad" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
          {priorities.map((p) => (
            <MenuItem key={p.code} value={p.code}>
              {p.label}
            </MenuItem>
          ))}
        </TextField>
        <TextField
          label="Se necesita para"
          type="date"
          value={form.needed_by}
          onChange={(e) => setForm({ ...form, needed_by: e.target.value })}
          InputLabelProps={{ shrink: true }}
          inputProps={{ min: todayInChile() }}
          error={Boolean(fieldError(errors, 'needed_by'))}
          helperText={fieldError(errors, 'needed_by')}
        />
        <TextField label="Centro de costo" value={form.cost_center} onChange={(e) => setForm({ ...form, cost_center: e.target.value })} inputProps={{ maxLength: 80 }} />
        <TextField select label="Cliente (si compras por encargo)" value={form.customer_public_id} onChange={(e) => setForm({ ...form, customer_public_id: e.target.value })}>
          <MenuItem value="">Para la propia empresa</MenuItem>
          {(customers.data?.data ?? []).map((c) => (
            <MenuItem key={c.public_id} value={c.public_id}>
              {c.organization.legal_name}
            </MenuItem>
          ))}
        </TextField>
        <TextField label="Dirección de entrega" value={form.delivery_address} onChange={(e) => setForm({ ...form, delivery_address: e.target.value })} inputProps={{ maxLength: 255 }} sx={{ gridColumn: '1 / -1' }} />
        <TextField label="Descripción o justificación" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} multiline minRows={2} inputProps={{ maxLength: 5000 }} sx={{ gridColumn: '1 / -1' }} />

        <FormSectionTitle>Ítems</FormSectionTitle>
      </FormGrid>

      <Stack spacing={1.5} sx={{ mt: 1 }}>
        {lines.map((l, idx) => (
          <Box key={l.key} sx={{ p: 1.5, borderRadius: '12px', border: `1px solid ${tokens.outlineVariant}`, bgcolor: tokens.surfaceLow }}>
            <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr 1fr', md: '2.2fr 0.8fr 0.9fr 1fr auto' }, alignItems: 'start' }}>
              <Autocomplete
                freeSolo
                options={products.data?.data ?? []}
                getOptionLabel={(o) => (typeof o === 'string' ? o : o.name)}
                value={l.product ?? l.description}
                onChange={(_, v) =>
                  typeof v === 'string' || v === null
                    ? setLine(l.key, { product: null, description: v ?? '' })
                    : setLine(l.key, { product: v, description: v.name, unit_code: v.unit?.code ?? l.unit_code })
                }
                onInputChange={(_, v, reason) => reason === 'input' && setLine(l.key, { product: null, description: v })}
                renderInput={(p) => (
                  <TextField
                    {...p}
                    label={`Ítem ${idx + 1}`}
                    placeholder="Del catálogo o descripción libre"
                    error={Boolean(fieldError(errors, `items.${idx}.description`))}
                    helperText={fieldError(errors, `items.${idx}.description`) ?? (l.product ? 'Del catálogo' : undefined)}
                  />
                )}
                sx={{ gridColumn: { xs: '1 / -1', md: 'auto' } }}
              />
              <TextField label="Cantidad" type="number" value={l.quantity} onChange={(e) => setLine(l.key, { quantity: e.target.value })} inputProps={{ min: 0, step: 'any' }} error={Number(l.quantity) <= 0} />
              <TextField select label="Unidad" value={l.unit_code} onChange={(e) => setLine(l.key, { unit_code: e.target.value })}>
                {(references?.units_of_measure ?? [{ code: 'EA', name: 'Unidad' }]).map((u) => (
                  <MenuItem key={u.code} value={u.code}>
                    {u.name}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                label="Precio ref. (neto)"
                type="number"
                value={l.estimated_unit_price}
                onChange={(e) => setLine(l.key, { estimated_unit_price: e.target.value })}
                inputProps={{ min: 0, step: 'any' }}
                InputProps={{ startAdornment: <InputAdornment position="start">$</InputAdornment> }}
                helperText="Opcional"
              />
              <Tooltip title="Quitar ítem">
                <span>
                  <IconButton aria-label={`Quitar ítem ${idx + 1}`} onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))} disabled={lines.length === 1} sx={{ mt: 0.5 }}>
                    <DeleteOutline />
                  </IconButton>
                </span>
              </Tooltip>
            </Box>
          </Box>
        ))}
      </Stack>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1} sx={{ mt: 1.5 }}>
        <Button startIcon={<Add />} onClick={() => setLines((ls) => [...ls, blankLine()])}>
          Agregar ítem
        </Button>
        <Typography variant="body2" color="text.secondary">
          {estimate !== null ? (
            <>
              Estimado neto: <strong className="num">{formatMoney(estimate)}</strong>
            </>
          ) : (
            'El estimado aparece cuando todos los ítems tienen precio de referencia.'
          )}
        </Typography>
      </Stack>
    </FormDialog>
  )
}
