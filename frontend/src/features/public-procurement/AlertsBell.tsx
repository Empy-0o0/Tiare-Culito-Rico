import Badge from '@mui/material/Badge'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Divider from '@mui/material/Divider'
import IconButton from '@mui/material/IconButton'
import Menu from '@mui/material/Menu'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import NotificationsOutlined from '@mui/icons-material/NotificationsOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { savedSearchesApi, type PublicAlertItem } from '../../services/savedSearches'
import { formatDateTime } from '../../utils/format'

/** Alertas internas de Mercado Público (coincidencias nuevas y cierres próximos) en el encabezado. */
export function AlertsBell() {
  const { can } = useAuth()
  const enabled = can('public_procurement.read')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [anchor, setAnchor] = useState<HTMLElement | null>(null)
  const alerts = useQuery({
    queryKey: ['public-alerts'],
    queryFn: savedSearchesApi.alerts,
    enabled,
    refetchInterval: 120_000,
  })
  const markRead = useMutation({
    mutationFn: (ids?: string[]) => savedSearchesApi.markRead(ids),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['public-alerts'] }),
  })
  if (!enabled) return null

  const unread = alerts.data?.meta.unread_count ?? 0
  const items = alerts.data?.data ?? []
  const open = (a: PublicAlertItem) => {
    setAnchor(null)
    if (!a.read) markRead.mutate([a.public_id])
    navigate(
      a.type.code === 'CLOSING_SOON' && a.codes[0]
        ? `/mercado-publico${a.source_type === 'licitacion' ? '/licitaciones' : ''}?ficha=${encodeURIComponent(a.codes[0])}`
        : `/mercado-publico/busquedas${a.search ? `?busqueda=${a.search.public_id}` : ''}`,
    )
  }

  return (
    <>
      <Tooltip title="Alertas de Mercado Público">
        <IconButton onClick={(e) => setAnchor(e.currentTarget)} aria-label={unread > 0 ? `Alertas, ${unread} sin leer` : 'Alertas'}>
          <Badge badgeContent={unread} color="primary" max={99}>
            <NotificationsOutlined />
          </Badge>
        </IconButton>
      </Tooltip>
      <Menu
        anchorEl={anchor}
        open={Boolean(anchor)}
        onClose={() => setAnchor(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{
          paper: {
            sx: { width: 360, maxWidth: 'calc(100vw - 32px)', maxHeight: 480 },
          },
        }}
      >
        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ px: 2, py: 1 }}>
          <Typography variant="subtitle2">Alertas</Typography>
          {unread > 0 && (
            <Button size="small" onClick={() => markRead.mutate(undefined)}>
              Marcar todas como leídas
            </Button>
          )}
        </Stack>
        <Divider />
        {items.length === 0 && (
          <Box sx={{ px: 2, py: 2 }}>
            <Typography variant="body2" color="text.secondary">
              Sin alertas. Guarda una búsqueda en Mercado Público para recibirlas.
            </Typography>
          </Box>
        )}
        {items.map((a) => (
          <MenuItem
            key={a.public_id}
            onClick={() => open(a)}
            sx={{
              whiteSpace: 'normal',
              alignItems: 'flex-start',
              borderLeft: a.read ? '3px solid transparent' : `3px solid ${tokens.primary}`,
            }}
          >
            <Box>
              <Typography variant="body2" sx={{ fontWeight: a.read ? 400 : 700 }}>
                {a.title}
              </Typography>
              {a.body && (
                <Typography variant="caption" color="text.secondary" component="div">
                  {a.body}
                </Typography>
              )}
              <Typography variant="caption" color="text.secondary">
                {a.type.label}, {formatDateTime(a.created_at)}
              </Typography>
            </Box>
          </MenuItem>
        ))}
        <Divider />
        <MenuItem onClick={() => (setAnchor(null), navigate('/mercado-publico/busquedas'))}>
          <Typography variant="body2" color="primary">
            Ver mis búsquedas
          </Typography>
        </MenuItem>
      </Menu>
    </>
  )
}
