import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import CloudDownloadOutlined from '@mui/icons-material/CloudDownloadOutlined'
import { useEffect, useState, type FormEvent } from 'react'
import { tokens } from '../../app/theme'
import { SOURCE_KEYS, useUrlFilters } from '../../hooks/useUrlFilters'
import type { CatalogOption, V1SearchInfo } from '../../types/api'
import { formatDate, formatDateTime, todayInChile } from '../../utils/format'

interface Props {
  noun: string
  statusFilters: CatalogOption[]
  search: V1SearchInfo | null | undefined
  fetching: boolean
  onRefresh: () => void
}

/**
 * Consulta a Mercado Público (API V1). Combinaciones documentadas por ChileCompra:
 * fecha, fecha + estado, estado "activas" (licitaciones), fecha + organismo, fecha + proveedor.
 */
export function V1QueryBar({ noun, statusFilters, search, fetching, onRefresh }: Props) {
  const { get, update, hasSourceQuery } = useUrlFilters()
  const [date, setDate] = useState(get('date'))
  const [status, setStatus] = useState(get('source_status'))
  const [buyer, setBuyer] = useState(get('buyer_code'))
  const [supplier, setSupplier] = useState(get('supplier_code'))
  const [error, setError] = useState<string | null>(null)

  // Mantiene el formulario alineado con la URL (p. ej. al llegar desde Organismos).
  const urlKey = SOURCE_KEYS.map((k) => get(k)).join('|')
  useEffect(() => {
    setDate(get('date'))
    setStatus(get('source_status'))
    setBuyer(get('buyer_code'))
    setSupplier(get('supplier_code'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [urlKey])

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (status === 'activas' && (date || buyer || supplier)) {
      setError('"Activas" se consulta sola: quita la fecha, el organismo y el proveedor.')
      return
    }
    if (buyer && supplier) {
      setError('Consulta por organismo o por proveedor, no ambos a la vez.')
      return
    }
    if (!date && !status && !buyer && !supplier) {
      setDate(todayInChile())
      update({ date: todayInChile(), source_status: null, buyer_code: null, supplier_code: null })
      return
    }
    update({ date: date || null, source_status: status || null, buyer_code: buyer.trim() || null, supplier_code: supplier.trim() || null })
  }

  const clear = () => update({ date: null, source_status: null, buyer_code: null, supplier_code: null })
  const digits = (v: string) => v.replace(/\D/g, '').slice(0, 12)

  return (
    <Card component="form" onSubmit={submit} aria-label={`Consultar ${noun} en Mercado Público`} sx={{ p: 2.5, mb: 2.5 }}>
      <Typography variant="h4" component="h2">
        Consultar {noun} en Mercado Público
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
        Elige una fecha y, si quieres, un estado, un organismo o un proveedor. Sin datos se consulta el día de hoy.
      </Typography>
      <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(4, minmax(0,1fr)) auto' }, alignItems: 'start' }}>
        <TextField label="Fecha" type="date" value={date} onChange={(e) => setDate(e.target.value)} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} />
        <TextField select label="Estado" value={status} onChange={(e) => setStatus(e.target.value)}>
          <MenuItem value="">Cualquiera</MenuItem>
          {statusFilters.map((s) => (
            <MenuItem key={s.code} value={s.code}>
              {s.label}
            </MenuItem>
          ))}
        </TextField>
        <TextField label="Código de organismo" value={buyer} onChange={(e) => setBuyer(digits(e.target.value))} inputProps={{ inputMode: 'numeric' }} helperText="Búscalo en Organismos" />
        <TextField label="Código de proveedor" value={supplier} onChange={(e) => setSupplier(digits(e.target.value))} inputProps={{ inputMode: 'numeric' }} helperText="Búscalo en Proveedores" />
        <Button type="submit" variant="contained" startIcon={<CloudDownloadOutlined />} disabled={fetching} sx={{ height: 40 }}>
          {fetching ? 'Consultando…' : 'Consultar'}
        </Button>
      </Box>
      {error && (
        <Alert severity="error" sx={{ mt: 1.5 }}>
          {error}
        </Alert>
      )}
      <Box sx={{ mt: 1.5, p: 1.5, borderRadius: '10px', bgcolor: tokens.surfaceLow }}>
        {search ? (
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'center' }}>
            <Typography variant="body2" sx={{ flex: 1 }}>
              Consulta {describe(search)}: <strong className="num">{search.received.toLocaleString('es-CL')}</strong> {noun} recibidas el {formatDateTime(search.fetched_at)}
              {search.from_cache ? '. Reutilizada, no consumió cuota.' : '.'}
            </Typography>
            <Button size="small" onClick={onRefresh} disabled={fetching}>
              Volver a consultar
            </Button>
            <Button size="small" onClick={clear}>
              Ver todo lo guardado
            </Button>
          </Stack>
        ) : (
          <Typography variant="body2" color="text.secondary">
            {hasSourceQuery
              ? 'Consultando…'
              : `Mostrando ${noun} ya guardadas en TIARE. Cada consulta nueva usa una solicitud de la cuota diaria; el resultado se reutiliza 30 minutos.`}
          </Typography>
        )}
      </Box>
    </Card>
  )
}

function describe(s: V1SearchInfo): string {
  const parts: string[] = []
  if (s.query.status) parts.push(`estado "${s.query.status}"`)
  if (s.query.date) parts.push(`del ${formatDate(`${s.query.date}T12:00:00`)}`)
  if (s.query.buyer_code) parts.push(`organismo ${s.query.buyer_code}`)
  if (s.query.supplier_code) parts.push(`proveedor ${s.query.supplier_code}`)
  return parts.join(', ') || 'del día'
}
