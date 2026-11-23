import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import AccessTime from '@mui/icons-material/AccessTime'
import ArrowForward from '@mui/icons-material/ArrowForward'
import BoltOutlined from '@mui/icons-material/BoltOutlined'
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined'
import { useQuery } from '@tanstack/react-query'
import { useMemo, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DemoBanner, SourceAttribution } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { ErrorState } from '../../components/ListStates'
import { publicProcurementApi } from '../../services/publicProcurement'
import { closingInfo, formatMoney } from '../../utils/format'
import { ClosingTimeline } from './ClosingTimeline'

function Stat({ icon, label, value, hint, tone }: { icon: ReactNode; label: string; value: string; hint: string; tone: { bg: string; fg: string } }) {
  return (
    <Card sx={{ p: 2.5, flex: 1, minWidth: 220 }}>
      <Stack direction="row" spacing={1.5} alignItems="center">
        <Avatar sx={{ bgcolor: tone.bg, color: tone.fg, width: 44, height: 44 }}>{icon}</Avatar>
        <Box>
          <Typography variant="body2" color="text.secondary">
            {label}
          </Typography>
          <Typography className="num" sx={{ fontSize: '1.75rem', fontWeight: 700, lineHeight: 1.15 }}>
            {value}
          </Typography>
        </Box>
      </Stack>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.25 }}>
        {hint}
      </Typography>
    </Card>
  )
}

/**
 * Resumen. Solo cifras verificables (Compra Ágil sincronizada). Los indicadores de compras
 * privadas, ahorro y entregas del prototipo eran ficticios y no se muestran.
 */
export function DashboardPage() {
  const { profile } = useAuth()
  const navigate = useNavigate()
  const now = useMemo(() => new Date(), [])

  const closing = useQuery({
    queryKey: ['dash-closing'],
    queryFn: () => publicProcurementApi.listCompraAgil({ open_only: true, sort: 'closes_at', direction: 'asc', per_page: 100 }),
    refetchInterval: 5 * 60_000,
  })
  const total = useQuery({ queryKey: ['dash-total'], queryFn: () => publicProcurementApi.listCompraAgil({ per_page: 1 }) })

  const items = closing.data?.data ?? []
  const next24 = items.filter((i) => closingInfo(i.dates.closes_at, now)?.tone === 'soon')
  const open = (code: string) => navigate(`/mercado-publico?ficha=${encodeURIComponent(code)}`)
  const firstName = profile?.user.name.split(' ')[0]
  const fmt = (n?: number) => (n === undefined ? '…' : n.toLocaleString('es-CL'))

  return (
    <>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'flex-end' }} spacing={2} sx={{ mb: 3 }}>
        <Box>
          <Typography variant="h1">{firstName ? `Hola, ${firstName}` : 'Hola'}</Typography>
          <Typography color="text.secondary" sx={{ mt: 0.5 }}>
            Esto es lo que cierra pronto en Compra Ágil.
          </Typography>
        </Box>
        <Button variant="contained" endIcon={<ArrowForward />} component={Link} to="/mercado-publico">
          Ver oportunidades
        </Button>
      </Stack>

      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 3 }}>
        <Stat
          icon={<BoltOutlined />}
          label="Abiertas ahora"
          value={fmt(closing.data?.meta.pagination?.total)}
          hint="Compras Ágiles publicadas con cierre futuro"
          tone={{ bg: tokens.primaryContainer, fg: tokens.primary }}
        />
        <Stat
          icon={<AccessTime />}
          label="Cierran en 24 horas"
          value={closing.data ? String(next24.length) : '…'}
          hint="Entre las 100 con cierre más próximo"
          tone={{ bg: tokens.tertiaryContainer, fg: tokens.urgent }}
        />
        <Stat
          icon={<Inventory2Outlined />}
          label="Registradas en TIARE"
          value={fmt(total.data?.meta.pagination?.total)}
          hint="Total sincronizado desde la API oficial"
          tone={{ bg: tokens.successContainer, fg: tokens.success }}
        />
      </Stack>

      <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <Typography variant="h2" component="h2">
          Próximas 72 horas
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
          Cada barra es una Compra Ágil ubicada en su hora de cierre; su altura indica el monto disponible. Haz clic para abrir la ficha.
        </Typography>
        {closing.error ? (
          <ErrorState error={closing.error} what="los próximos cierres" />
        ) : closing.isLoading ? (
          <Skeleton variant="rounded" height={220} />
        ) : (
          <ClosingTimeline items={items} now={now} onOpen={open} />
        )}
      </Card>

      <Card sx={{ p: { xs: 2, sm: 3 } }}>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 2 }}>
          <Typography variant="h3" component="h2" sx={{ flex: 1 }}>
            Cierran en menos de 24 horas
          </Typography>
          {next24.length > 0 && <Chip label={next24.length} size="small" sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.urgent }} />}
        </Stack>
        {next24.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Ninguna Compra Ágil abierta cierra en las próximas 24 horas.
          </Typography>
        ) : (
          <DataTable
            columns={[{ label: 'Código' }, { label: 'Compra' }, { label: 'Monto', align: 'right' }, { label: 'Cierre', align: 'right' }]}
            rows={next24.slice(0, 12).map((c) => ({
              key: c.public_id,
              cells: [
                <Button size="small" className="code" onClick={() => open(c.code)} sx={{ px: 1, minHeight: 0 }}>
                  {c.code}
                </Button>,
                <>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {c.title}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {c.buyer.name}
                  </Typography>
                </>,
                <span className="num">{formatMoney(c.budget.available_clp ?? c.budget.available)}</span>,
                <Typography variant="body2" sx={{ color: tokens.urgent, fontWeight: 700, whiteSpace: 'nowrap' }}>
                  {closingInfo(c.dates.closes_at, now)?.text}
                </Typography>,
              ],
            }))}
          />
        )}
        {closing.data && <SourceAttribution source={closing.data.meta.source} updatedAt={closing.data.meta.sync.last_item_fetched_at} />}
      </Card>

      <Box sx={{ mt: 3 }}>
        <DemoBanner>
          Los indicadores de gasto, ahorro, solicitudes y entregas se habilitarán con los módulos de compras privadas y logística. No se muestran cifras inventadas.
        </DemoBanner>
      </Box>
    </>
  )
}
