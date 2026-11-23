import Alert from '@mui/material/Alert'
import Button from '@mui/material/Button'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import IconButton from '@mui/material/IconButton'
import useMediaQuery from '@mui/material/useMediaQuery'
import { useTheme } from '@mui/material/styles'
import Close from '@mui/icons-material/Close'
import type { FormEvent, ReactNode } from 'react'

interface Props {
  open: boolean
  title: string
  onClose: () => void
  onSubmit: () => void
  submitLabel: string
  submitting: boolean
  canSubmit: boolean
  error?: string | null
  children: ReactNode
}

/**
 * Diálogo de formulario (MUI): pantalla completa en móvil, Enter envía, Esc cierra.
 * El botón principal dice exactamente lo que hace ("Guardar cliente").
 */
export function FormDialog({ open, title, onClose, onSubmit, submitLabel, submitting, canSubmit, error, children }: Props) {
  const theme = useTheme()
  const fullScreen = useMediaQuery(theme.breakpoints.down('sm'))
  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (canSubmit && !submitting) onSubmit()
  }

  return (
    <Dialog open={open} onClose={onClose} fullScreen={fullScreen} fullWidth maxWidth="md" PaperProps={{ component: 'form', onSubmit: submit, noValidate: true, sx: { borderRadius: fullScreen ? 0 : '24px' } }}>
      <DialogTitle sx={{ pr: 7, fontWeight: 700 }}>{title}</DialogTitle>
      <IconButton aria-label="Cerrar" onClick={onClose} sx={{ position: 'absolute', right: 12, top: 12 }}>
        <Close />
      </IconButton>
      <DialogContent dividers>
        {children}
        {error && (
          <Alert severity="error" sx={{ mt: 2 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancelar</Button>
        <Button type="submit" variant="contained" disabled={!canSubmit || submitting}>
          {submitting ? 'Guardando…' : submitLabel}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
