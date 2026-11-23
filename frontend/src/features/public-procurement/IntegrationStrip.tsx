import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import LinearProgress from '@mui/material/LinearProgress'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import SyncOutlined from '@mui/icons-material/SyncOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { ApiError } from '../../services/apiClient'
import { publicProcurementApi } from '../../services/publicProcurement'
import type { QuotaUsage } from '../../types/api'
import { formatDateTime } from '../../utils/format'

const RUN_STATUS: Record<string, string> = {
  succeeded: 'completada',
  partial: 'parcial',
  failed: 'fallida',
  quota_exhausted: 'detenida por cuota',
  running: 'en curso',
  skipped: 'omitida',
}

function Quota({ label, q }: { label: string; q: QuotaUsage }) {
  const pct = q.budget > 0 ? Math.min(100, Math.round((q.used / q.budget) * 100)) : 0
  const high = pct >= 80 || Boolean(q.blocked_until)
  return (
    <Tooltip
      title={`${q.errors} errores hoy. La cuota se reinicia a las 00:00 de Chile.${
        q.interactive_reserve ? ` La sincronización automática usa hasta ${q.background_limit}; ${q.interactive_reserve} quedan reservadas para abrir fichas y consultas.` : ''
      }`}
    >
      <Box sx={{ minWidth: 170 }}>
        <Stack direction="row" justifyContent="space-between">
          <Typography variant="caption" color="text.secondary">
            {label}
          </Typography>
          <Typography variant="caption" className="num" sx={{ fontWeight: 700 }}>
            {q.used}/{q.budget < 0 ? '∞' : q.budget}
          </Typography>
        </Stack>
        <LinearProgress
          variant="determinate"
          value={pct}
          aria-label={label}
          sx={{ mt: 0.5, height: 6, borderRadius: '12px', bgcolor: tokens.surfaceHigh, '& .MuiLinearProgress-bar': { bgcolor: high ? tokens.urgent : 'primary.main' } }}
        />
        {Boolean(q.interactive_reserve) && (
          <Typography variant="caption" color="text.secondary" component="p">
            Automático hasta {q.background_limit} · {q.interactive_reserve} reservadas para ti
          </Typography>
        )}
        {q.blocked_until && (
          <Typography variant="caption" sx={{ color: tokens.urgent }}>
            Bloqueada hasta {formatDateTime(q.blocked_until)}
          </Typography>
        )}
      </Box>
    </Tooltip>
  )
}

/** Estado de la integración: sincronización de Compra Ágil y consumo de cuota diaria (sin exponer el ticket). */
export function IntegrationStrip() {
  const { can } = useAuth()
  const { pathname } = useLocation()
  const qc = useQueryClient()
  const [toast, setToast] = useState<string | null>(null)
  const status = useQuery({ queryKey: ['sync-status'], queryFn: publicProcurementApi.syncStatus, refetchInterval: 60_000 })
  const trigger = useMutation({
    mutationFn: publicProcurementApi.triggerSync,
    onSuccess: (r) => {
      setToast(r.data.message)
      void qc.invalidateQueries({ queryKey: ['sync-status'] })
    },
    onError: (e) => setToast(e instanceof ApiError ? e.message : 'No se pudo solicitar la sincronización.'),
  })

  const s = status.data?.data
  if (!s) return null
  const lastRun = s.runs[0]
  const onCompraAgil = pathname === '/mercado-publico'
  const live = s.integration.enabled && s.integration.configured

  return (
    <>
      {!s.integration.configured && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          El ticket de Mercado Público no está configurado en el servidor. Agrégalo en backend/.env (MERCADO_PUBLICO_TICKET) para consultar datos oficiales.
        </Alert>
      )}
      <Card sx={{ mb: 2.5, px: 2.5, py: 1.75, bgcolor: tokens.surfaceLow }}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={{ xs: 1.5, md: 4 }} alignItems={{ md: 'center' }}>
          <Stack direction="row" spacing={1.25} alignItems="center" sx={{ flex: 1, minWidth: 0 }}>
            <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: live ? 'success.main' : 'text.disabled', flex: 'none' }} />
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {live ? 'Conectado a Mercado Público' : 'Integración desactivada'}
              </Typography>
              <Typography variant="caption" color="text.secondary" component="p">
                {s.compra_agil.mode === 'changes' ? 'Sigue todos los cambios' : 'Busca publicaciones nuevas'}
                {s.compra_agil.last_success_at ? ` · al día ${formatDateTime(s.compra_agil.last_success_at)}` : ' · aún sin un recorrido completo'}
                {lastRun ? ` · última ejecución ${RUN_STATUS[lastRun.status] ?? lastRun.status}${lastRun.requests_used ? ` (${lastRun.requests_used} solicitudes)` : ''}` : ''}
              </Typography>
              {s.compra_agil.known_until && (
                <Typography variant="caption" color="text.secondary" component="p">
                  Publicaciones conocidas hasta {formatDateTime(s.compra_agil.known_until)}
                </Typography>
              )}
            </Box>
          </Stack>
          <Quota label="Cuota Compra Ágil (V2)" q={s.quota.v2} />
          {s.quota.v1 && <Quota label="Cuota licitaciones y OC (V1)" q={s.quota.v1} />}
          {onCompraAgil && can('public_procurement.sync') && (
            <Button variant="outlined" startIcon={<SyncOutlined />} onClick={() => trigger.mutate()} disabled={trigger.isPending || !s.integration.configured}>
              Sincronizar
            </Button>
          )}
        </Stack>
        {onCompraAgil && lastRun?.error_summary && (
          // Una ejecución parcial ya no es un error: la siguiente continúa donde quedó (TD-029).
          <Alert severity={lastRun.status === 'partial' || lastRun.status === 'skipped' ? 'info' : 'warning'} sx={{ mt: 1.5 }}>
            {lastRun.error_summary}
          </Alert>
        )}
      </Card>
      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
