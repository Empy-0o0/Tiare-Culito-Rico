import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import IconButton from '@mui/material/IconButton'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import EditOutlined from '@mui/icons-material/EditOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { tokens } from '../../app/theme'
import { FormDialog } from '../../components/FormDialog'
import { ErrorState } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import type { SupplierOfferingDto } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDate, todayInChile } from '../../utils/format'
import { useCrmReferences } from './useCrmReferences'

const qty = (v: string | null) => (v === null ? null : Number(v).toLocaleString('es-CL', { maximumFractionDigits: 3 }))

const EMPTY = { kind: 'PRODUCT' as 'PRODUCT' | 'SERVICE', name: '', description: '', category_public_id: '', unit_code: '', capacity_quantity: '', capacity_period: 'MONTH', min_order_quantity: '', lead_time_days: '', capacity_notes: '', capacity_confirmed_on: '' }

/** Insumos y servicios que entrega el proveedor, con su capacidad declarada (TD-026). */
export function SupplierOfferingsPanel({ supplierId, canManage }: { supplierId: string; canManage: boolean }) {
  const qc = useQueryClient()
  const key = ['supplier-offerings', supplierId]
  const q = useQuery({ queryKey: key, queryFn: () => crmApi.offerings(supplierId) })
  const cats = useQuery({ queryKey: ['categories'], queryFn: crmApi.categories, staleTime: 60_000, enabled: canManage })
  const { references } = useCrmReferences()
  const [editing, setEditing] = useState<SupplierOfferingDto | 'new' | null>(null)
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)

  const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')))
  const body = () => ({
    kind: form.kind,
    name: form.name.trim(),
    description: form.description.trim() || null,
    category_public_id: form.category_public_id || null,
    unit_code: form.unit_code || null,
    capacity_quantity: num(form.capacity_quantity),
    capacity_period: form.capacity_quantity.trim() ? form.capacity_period : null,
    min_order_quantity: num(form.min_order_quantity),
    lead_time_days: form.lead_time_days.trim() === '' ? null : parseInt(form.lead_time_days, 10),
    capacity_notes: form.capacity_notes.trim() || null,
    capacity_confirmed_on: form.capacity_confirmed_on || null,
  })
  const save = useMutation({
    mutationFn: () => (editing === 'new' ? crmApi.createOffering(supplierId, body()) : crmApi.updateOffering(supplierId, (editing as SupplierOfferingDto).public_id, body())),
    onSuccess: () => {
      setEditing(null)
      void qc.invalidateQueries({ queryKey: key })
      void qc.invalidateQueries({ queryKey: ['suppliers'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })
  const remove = useMutation({
    mutationFn: (offeringId: string) => crmApi.deleteOffering(supplierId, offeringId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: key })
      void qc.invalidateQueries({ queryKey: ['suppliers'] })
    },
  })

  const open = (o: SupplierOfferingDto | 'new') => {
    setErrors({})
    setError(null)
    setEditing(o)
    setForm(
      o === 'new'
        ? EMPTY
        : {
            kind: o.kind.code,
            name: o.name,
            description: o.description ?? '',
            category_public_id: o.category?.public_id ?? '',
            unit_code: o.unit?.code ?? '',
            capacity_quantity: o.capacity.quantity !== null ? String(Number(o.capacity.quantity)) : '',
            capacity_period: o.capacity.period?.code ?? 'MONTH',
            min_order_quantity: o.capacity.min_order_quantity !== null ? String(Number(o.capacity.min_order_quantity)) : '',
            lead_time_days: o.capacity.lead_time_days !== null ? String(o.capacity.lead_time_days) : '',
            capacity_notes: o.capacity.notes ?? '',
            capacity_confirmed_on: o.capacity.confirmed_on ?? '',
          },
    )
  }

  if (q.isLoading) return <LinearProgress />
  if (q.error || !q.data) return <ErrorState error={q.error} what="la oferta del proveedor" />
  const items = q.data.data
  const periods = q.data.meta.periods

  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
        <Typography variant="body2" color="text.secondary">
          {items.length === 0 ? 'Sin insumos ni servicios registrados.' : `${items.length} ${items.length === 1 ? 'registro' : 'registros'}`}
        </Typography>
        {canManage && (
          <Button size="small" variant="contained" startIcon={<Add />} onClick={() => open('new')}>
            Agregar insumo o servicio
          </Button>
        )}
      </Stack>

      <Stack spacing={1.5}>
        {items.map((o) => {
          const unit = o.unit?.name?.toLowerCase() ?? 'unidades'
          return (
            <Card key={o.public_id} variant="outlined" sx={{ p: 1.75, borderColor: tokens.outlineVariant }}>
              <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
                <Box sx={{ minWidth: 0 }}>
                  <Stack direction="row" spacing={0.75} alignItems="center" sx={{ flexWrap: 'wrap', rowGap: 0.5 }}>
                    <Chip size="small" label={o.kind.code === 'SERVICE' ? 'Servicio' : 'Insumo'} color={o.kind.code === 'SERVICE' ? 'secondary' : 'primary'} variant="outlined" />
                    <Typography sx={{ fontWeight: 700 }}>{o.name}</Typography>
                    {o.category && <Chip size="small" label={o.category.name} />}
                  </Stack>
                  {o.description && (
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                      {o.description}
                    </Typography>
                  )}
                </Box>
                {canManage && (
                  <Stack direction="row">
                    <Tooltip title="Editar">
                      <IconButton size="small" aria-label={`Editar ${o.name}`} onClick={() => open(o)}>
                        <EditOutlined fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    <Tooltip title="Eliminar">
                      <IconButton size="small" aria-label={`Eliminar ${o.name}`} disabled={remove.isPending} onClick={() => window.confirm(`¿Eliminar "${o.name}" de la oferta del proveedor?`) && remove.mutate(o.public_id)}>
                        <DeleteOutline fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Stack>
                )}
              </Stack>
              <Box sx={{ display: 'grid', gap: 1, gridTemplateColumns: { xs: '1fr 1fr', sm: 'repeat(4, 1fr)' }, mt: 1.25 }}>
                {[
                  ['Capacidad', o.capacity.quantity ? `${qty(o.capacity.quantity)} ${unit} ${o.capacity.period?.label ?? ''}` : 'No informada'],
                  ['Pedido mínimo', o.capacity.min_order_quantity ? `${qty(o.capacity.min_order_quantity)} ${unit}` : '—'],
                  ['Plazo de entrega', o.capacity.lead_time_days !== null ? `${o.capacity.lead_time_days} días` : '—'],
                  ['Confirmada', o.capacity.confirmed_on ? formatDate(o.capacity.confirmed_on) : 'Sin confirmar'],
                ].map(([k, v]) => (
                  <Box key={k}>
                    <Typography variant="caption" color="text.secondary">
                      {k}
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {v}
                    </Typography>
                  </Box>
                ))}
              </Box>
              {o.capacity.notes && (
                <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.75 }}>
                  {o.capacity.notes}
                </Typography>
              )}
            </Card>
          )
        })}
      </Stack>
      <Alert severity="info" sx={{ mt: 2 }}>
        {q.data.meta.note}
      </Alert>

      <FormDialog
        open={Boolean(editing)}
        title={editing === 'new' ? 'Agregar insumo o servicio' : 'Editar insumo o servicio'}
        onClose={() => setEditing(null)}
        onSubmit={() => save.mutate()}
        submitLabel="Guardar"
        submitting={save.isPending}
        canSubmit={form.name.trim().length >= 2}
        error={error}
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '160px 1fr' } }}>
          <TextField select label="Tipo" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as 'PRODUCT' | 'SERVICE' })}>
            <MenuItem value="PRODUCT">Insumo / producto</MenuItem>
            <MenuItem value="SERVICE">Servicio</MenuItem>
          </TextField>
          <TextField label="Nombre" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 200 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name') ?? 'Ej.: Papel higiénico doble hoja, Limpieza de oficinas'} />
          <TextField label="Descripción" multiline minRows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
          <TextField select label="Categoría" value={form.category_public_id} onChange={(e) => setForm({ ...form, category_public_id: e.target.value })} error={Boolean(fieldError(errors, 'category_public_id'))} helperText={fieldError(errors, 'category_public_id')}>
            <MenuItem value="">Sin categoría</MenuItem>
            {(cats.data?.data ?? []).map((c) => (
              <MenuItem key={c.public_id} value={c.public_id}>
                {c.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField select label="Unidad de medida" value={form.unit_code} onChange={(e) => setForm({ ...form, unit_code: e.target.value })}>
            <MenuItem value="">Sin unidad</MenuItem>
            {(references?.units_of_measure ?? []).map((u) => (
              <MenuItem key={u.code} value={u.code}>
                {u.name}
              </MenuItem>
            ))}
          </TextField>
        </Box>
        <Typography variant="subtitle2" sx={{ mt: 2.5, mb: 1 }}>
          Capacidad declarada por el proveedor
        </Typography>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField label="Cantidad" inputMode="decimal" value={form.capacity_quantity} onChange={(e) => setForm({ ...form, capacity_quantity: e.target.value })} error={Boolean(fieldError(errors, 'capacity_quantity'))} helperText={fieldError(errors, 'capacity_quantity') ?? 'Cuánto puede entregar'} />
          <TextField select label="Período" value={form.capacity_period} onChange={(e) => setForm({ ...form, capacity_period: e.target.value })} error={Boolean(fieldError(errors, 'capacity_period'))} helperText={fieldError(errors, 'capacity_period')}>
            {periods.map((p) => (
              <MenuItem key={p.code} value={p.code}>
                {p.label}
              </MenuItem>
            ))}
          </TextField>
          <TextField label="Pedido mínimo" inputMode="decimal" value={form.min_order_quantity} onChange={(e) => setForm({ ...form, min_order_quantity: e.target.value })} error={Boolean(fieldError(errors, 'min_order_quantity'))} helperText={fieldError(errors, 'min_order_quantity')} />
          <TextField label="Plazo de entrega (días)" inputMode="numeric" value={form.lead_time_days} onChange={(e) => setForm({ ...form, lead_time_days: e.target.value.replace(/\D/g, '') })} error={Boolean(fieldError(errors, 'lead_time_days'))} helperText={fieldError(errors, 'lead_time_days')} />
          <TextField label="Confirmada con el proveedor el" type="date" value={form.capacity_confirmed_on} onChange={(e) => setForm({ ...form, capacity_confirmed_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} error={Boolean(fieldError(errors, 'capacity_confirmed_on'))} helperText={fieldError(errors, 'capacity_confirmed_on')} />
          <TextField label="Notas de capacidad" value={form.capacity_notes} onChange={(e) => setForm({ ...form, capacity_notes: e.target.value })} inputProps={{ maxLength: 500 }} helperText="Ej.: stock en bodega Santiago; temporada alta reduce 30 %" />
        </Box>
      </FormDialog>
    </Box>
  )
}
