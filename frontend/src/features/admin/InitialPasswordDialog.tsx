import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import ContentCopy from '@mui/icons-material/ContentCopy'
import { useState } from 'react'

/**
 * Muestra una contraseña de inicio UNA sola vez (no se guarda en el navegador ni se puede volver a consultar).
 * Al cerrar, el valor se descarta.
 */
export function InitialPasswordDialog({ value, name, email, onClose }: { value: string | null; name?: string; email?: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false)

  return (
    <Dialog
      open={Boolean(value)}
      onClose={(_, reason) => reason !== 'backdropClick' && onClose()}
      maxWidth="xs"
      fullWidth
      PaperProps={{ sx: { borderRadius: '24px' } }}
      TransitionProps={{ onExited: () => setCopied(false) }}
    >
      <DialogTitle sx={{ fontWeight: 700 }}>Contraseña de inicio</DialogTitle>
      <DialogContent>
        {(name || email) && (
          <Typography variant="body2" sx={{ mb: 1.5 }}>
            Para <strong>{name}</strong> {email ? `(${email})` : ''}
          </Typography>
        )}
        <Stack direction="row" spacing={1} alignItems="center">
          <Box component="code" aria-label="Contraseña de inicio" sx={{ flex: 1, p: 1.5, borderRadius: '12px', bgcolor: 'action.hover', fontSize: '1.15rem', letterSpacing: '0.04em', textAlign: 'center', wordBreak: 'break-all' }}>
            {value}
          </Box>
          <Button
            startIcon={<ContentCopy />}
            onClick={() =>
              void navigator.clipboard?.writeText(value ?? '').then(() => {
                setCopied(true)
              })
            }
          >
            {copied ? 'Copiada' : 'Copiar'}
          </Button>
        </Stack>
        <Alert severity="warning" sx={{ mt: 2 }}>
          Se muestra <strong>solo esta vez</strong>. Entrégala por un canal seguro (en persona o por teléfono, no por correo ni chat). Al ingresar, la persona deberá cambiarla.
        </Alert>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button variant="contained" onClick={onClose}>
          Ya la entregué, cerrar
        </Button>
      </DialogActions>
    </Dialog>
  )
}
