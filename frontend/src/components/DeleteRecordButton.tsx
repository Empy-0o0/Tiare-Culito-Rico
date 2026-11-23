import Alert from '@mui/material/Alert'
import Button from '@mui/material/Button'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import Typography from '@mui/material/Typography'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError } from '../services/apiClient'

interface Props {
  /** "cliente" | "proveedor" */
  noun: string
  name: string
  onDelete: () => Promise<unknown>
  onDeleted: () => void
  /** Texto extra sobre qué se elimina junto con el registro. */
  detail?: string
}

/**
 * Botón "Eliminar" con confirmación explícita. Si el backend rechaza por historial (409),
 * muestra el motivo y la alternativa sugerida en el mismo diálogo.
 */
export function DeleteRecordButton({ noun, name, onDelete, onDeleted, detail }: Props) {
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const del = useMutation({
    mutationFn: onDelete,
    onSuccess: () => {
      setOpen(false)
      onDeleted()
    },
    onError: (e) => setError(e instanceof ApiError ? e.message : `No fue posible eliminar el ${noun}.`),
  })

  return (
    <>
      <Button
        color="error"
        startIcon={<DeleteOutline />}
        onClick={() => {
          setError(null)
          setOpen(true)
        }}
      >
        Eliminar {noun}
      </Button>
      <Dialog open={open} onClose={() => setOpen(false)} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: '24px' } }}>
        <DialogTitle sx={{ fontWeight: 700 }}>¿Eliminar {noun}?</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Se eliminará <strong>{name}</strong>. Esta acción no se puede deshacer.
          </Typography>
          {detail && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              {detail}
            </Typography>
          )}
          {error && (
            <Alert severity="warning" sx={{ mt: 2 }}>
              {error}
            </Alert>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={() => setOpen(false)}>{error ? 'Cerrar' : 'Cancelar'}</Button>
          {!error && (
            <Button variant="contained" color="error" disabled={del.isPending} onClick={() => del.mutate()}>
              {del.isPending ? 'Eliminando…' : `Eliminar ${noun}`}
            </Button>
          )}
        </DialogActions>
      </Dialog>
    </>
  )
}
