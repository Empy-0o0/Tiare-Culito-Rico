import Box from '@mui/material/Box'
import Divider from '@mui/material/Divider'
import MuiDrawer from '@mui/material/Drawer'
import IconButton from '@mui/material/IconButton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import Close from '@mui/icons-material/Close'
import type { ReactNode } from 'react'
import { tokens } from '../app/theme'

interface DrawerProps {
  open: boolean
  onClose: () => void
  /** Código del proceso: protagonista de la ficha. */
  code: string
  title?: string
  children: ReactNode
  footer?: ReactNode
  /** "name": el encabezado es un nombre (cliente, proveedor) y no un código. */
  headingVariant?: 'code' | 'name'
}

/** Ficha en panel lateral (la lista sigue visible). Esc o clic fuera para cerrar; foco gestionado por MUI. */
export function Drawer({ open, onClose, code, title, children, footer, headingVariant = 'code' }: DrawerProps) {
  return (
    <MuiDrawer
      anchor="right"
      open={open}
      onClose={onClose}
      PaperProps={{ sx: { width: { xs: '100%', sm: 640 }, borderTopLeftRadius: { sm: '24px' }, borderBottomLeftRadius: { sm: '24px' }, bgcolor: tokens.surface } }}
      slotProps={{ backdrop: { sx: { bgcolor: 'rgba(34,25,28,0.32)' } } }}
    >
      <Box role="dialog" aria-labelledby="drawerCode" sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
        <Stack direction="row" alignItems="flex-start" spacing={2} sx={{ px: 3, pt: 3, pb: 2, bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer }}>
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Typography id="drawerCode" component="h2" className={headingVariant === 'code' ? 'code' : undefined} sx={headingVariant === 'code' ? { fontSize: '1.6rem', lineHeight: 1.1 } : { fontSize: '1.35rem', fontWeight: 700, lineHeight: 1.2 }}>
              {code}
            </Typography>
            {title && (
              <Typography variant="subtitle1" sx={{ mt: 0.75, fontWeight: 600 }}>
                {title}
              </Typography>
            )}
          </Box>
          <IconButton aria-label="Cerrar ficha" onClick={onClose} sx={{ color: 'inherit' }}>
            <Close />
          </IconButton>
        </Stack>
        <Box sx={{ flex: 1, overflowY: 'auto', px: 3, py: 2.5 }}>{children}</Box>
        {footer && (
          <>
            <Divider />
            <Stack direction="row" justifyContent="flex-end" spacing={1} sx={{ px: 3, py: 2 }}>
              {footer}
            </Stack>
          </>
        )}
      </Box>
    </MuiDrawer>
  )
}

/** Datos clave de una ficha en dos columnas (una en móvil). Oculta los vacíos. */
export function Facts({ rows }: { rows: Array<[string, ReactNode]> }) {
  const visible = rows.filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '—' && v !== false)
  if (!visible.length) return null
  return (
    <Box component="dl" sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 1.25, m: 0, mb: 2 }}>
      {visible.map(([k, v]) => (
        <Box key={k} sx={{ p: 1.5, borderRadius: '12px', bgcolor: '#FFFFFF', border: `1px solid ${tokens.outlineVariant}`, minWidth: 0 }}>
          <Typography component="dt" variant="caption" color="text.secondary">
            {k}
          </Typography>
          <Typography component="dd" variant="body2" sx={{ m: 0, mt: 0.25, fontWeight: 600, overflowWrap: 'anywhere' }}>
            {v}
          </Typography>
        </Box>
      ))}
    </Box>
  )
}

/** Sección de ficha con título. */
export function DetailSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Box component="section" sx={{ mt: 3 }}>
      <Typography variant="h4" component="h3" sx={{ mb: 1.25 }}>
        {title}
      </Typography>
      {children}
    </Box>
  )
}
