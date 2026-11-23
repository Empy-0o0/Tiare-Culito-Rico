import Alert from '@mui/material/Alert'
import AlertTitle from '@mui/material/AlertTitle'
import Box from '@mui/material/Box'
import Card from '@mui/material/Card'
import Pagination from '@mui/material/Pagination'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import SearchOff from '@mui/icons-material/SearchOff'
import type { ReactNode } from 'react'
import { tokens } from '../app/theme'
import { ApiError } from '../services/apiClient'
import { formatDateTime } from '../utils/format'

/** Error de carga con instrucción concreta (cuota agotada, ticket, sin conexión). */
export function ErrorState({ error, what }: { error: unknown; what: string }) {
  let hint = 'Intenta nuevamente en unos minutos.'
  let message = 'Error inesperado.'
  let severity: 'error' | 'warning' = 'error'
  if (error instanceof ApiError) {
    message = error.message
    if (error.code === 'MP_QUOTA_EXHAUSTED') {
      severity = 'warning'
      hint = 'La cuota diaria del ticket se agotó y se reanuda automáticamente. Mientras tanto puedes revisar lo ya guardado.'
    } else if (error.code === 'MP_TICKET_REJECTED' || error.code === 'MP_NOT_CONFIGURED') hint = 'Revisa el ticket configurado en el servidor (backend/.env).'
    else if (error.code === 'NETWORK_ERROR') hint = 'Verifica que el backend de TIARE esté en ejecución.'
    else if (error.code === 'VALIDATION_FAILED') hint = Object.values(error.details ?? {}).flat().join(' ') || 'Revisa los filtros.'
    else if (error.code === 'MP_NOT_FOUND') {
      severity = 'warning'
      hint = 'Revisa el código o búscalo directamente en mercadopublico.cl.'
    } else if (error.retryAfterSeconds)
      hint = `Puedes reintentar después de ${formatDateTime(new Date(Date.now() + error.retryAfterSeconds * 1000).toISOString())}.`
  }
  return (
    <Alert severity={severity} role="alert" sx={{ my: 2 }}>
      <AlertTitle>No fue posible cargar {what}</AlertTitle>
      {message} {hint}
    </Alert>
  )
}

/** Estado vacío: explica qué pasó y ofrece la siguiente acción. */
export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <Card sx={{ my: 2, p: { xs: 3, sm: 5 }, textAlign: 'center', bgcolor: tokens.surfaceLow, borderStyle: 'dashed' }}>
      <Box sx={{ width: 56, height: 56, mx: 'auto', mb: 2, borderRadius: '50%', display: 'grid', placeItems: 'center', bgcolor: tokens.primaryContainer, color: tokens.primary }}>
        <SearchOff />
      </Box>
      <Typography variant="h3" gutterBottom>
        {title}
      </Typography>
      {children && (
        <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 520, mx: 'auto' }}>
          {children}
        </Typography>
      )}
      {action && <Box sx={{ mt: 2 }}>{action}</Box>}
    </Card>
  )
}

/** Carga inicial de listas: esqueletos con la forma de las tarjetas de resultado. */
export function ListSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <Stack spacing={1.5} sx={{ my: 2 }} aria-label="Cargando">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} variant="rounded" height={92} sx={{ borderRadius: '16px' }} />
      ))}
    </Stack>
  )
}

export function Pager({ page, lastPage, onChange }: { page: number; lastPage: number; onChange: (p: number) => void }) {
  if (lastPage <= 1) return null
  return (
    <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
      <Pagination
        count={lastPage}
        page={page}
        onChange={(_, p) => onChange(p)}
        color="primary"
        shape="rounded"
        siblingCount={1}
        getItemAriaLabel={(type, p) => (type === 'page' ? `Página ${p}` : type === 'next' ? 'Página siguiente' : type === 'previous' ? 'Página anterior' : String(type))}
      />
    </Box>
  )
}
