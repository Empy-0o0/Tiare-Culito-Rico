import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Collapse from '@mui/material/Collapse'
import IconButton from '@mui/material/IconButton'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import ArchiveOutlined from '@mui/icons-material/ArchiveOutlined'
import AttachFile from '@mui/icons-material/AttachFile'
import DescriptionOutlined from '@mui/icons-material/DescriptionOutlined'
import FileDownloadOutlined from '@mui/icons-material/FileDownloadOutlined'
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { tokens } from '../../app/theme'
import { ErrorState } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import type { DocumentValidity } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDate, todayInChile } from '../../utils/format'

const VALIDITY: Record<DocumentValidity, { label: string; bg: string; fg: string }> = {
  valid: { label: 'Vigente', bg: tokens.successContainer, fg: '#0F3D25' },
  expiring: { label: 'Por vencer', bg: tokens.tertiaryContainer, fg: tokens.urgent },
  expired: { label: 'Vencido', bg: tokens.errorContainer, fg: '#410002' },
  no_expiry: { label: 'Sin vencimiento', bg: tokens.surfaceHigh, fg: tokens.onSurfaceVariant },
}

const MAX_BYTES = 10 * 1024 * 1024
const ACCEPT = 'application/pdf,image/jpeg,image/png'

const kb = (b: number) => (b >= 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
const day = (iso: string | null) => (iso ? formatDate(`${iso}T12:00:00`) : null)

/** Documentos y certificaciones del proveedor con estado de vigencia. Subir/archivar requiere suppliers.update. */
export function SupplierDocumentsPanel({ supplierId, canManage }: { supplierId: string; canManage: boolean }) {
  const qc = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState(false)
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [form, setForm] = useState({ type: 'CERTIFICATION', name: '', issued_on: '', expires_on: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const query = useQuery({ queryKey: ['supplier-documents', supplierId], queryFn: () => crmApi.documents(supplierId) })

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['supplier-documents', supplierId] })
    void qc.invalidateQueries({ queryKey: ['suppliers'] })
  }
  const upload = useMutation({
    mutationFn: () => crmApi.uploadDocument(supplierId, { file: file as File, ...form }),
    onSuccess: () => {
      setOpen(false)
      setFile(null)
      setForm({ type: 'CERTIFICATION', name: '', issued_on: '', expires_on: '' })
      setErrors({})
      refresh()
    },
    onError: (e) => setErrors(e instanceof ApiError ? (e.details ?? {}) : {}),
  })
  const archive = useMutation({ mutationFn: (docId: string) => crmApi.archiveDocument(supplierId, docId), onSuccess: refresh })

  const pick = (f: File | undefined) => {
    setFileError(null)
    if (!f) return
    if (!ACCEPT.split(',').includes(f.type)) return setFileError('Solo se aceptan archivos PDF, JPG o PNG.')
    if (f.size > MAX_BYTES) return setFileError('El archivo supera los 10 MB.')
    setFile(f)
    if (!form.name) setForm((p) => ({ ...p, name: f.name.replace(/\.[^.]+$/, '') }))
  }

  const docs = query.data?.data ?? []
  const types = query.data?.meta.types ?? []
  const attention = docs.filter((d) => d.validity === 'expired' || d.validity === 'expiring').length

  return (
    <Box>
      {query.isLoading && <LinearProgress sx={{ borderRadius: '4px' }} />}
      {query.error && <ErrorState error={query.error} what="los documentos" />}
      {attention > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          {attention === 1 ? 'Un documento está vencido o vence' : `${attention} documentos están vencidos o vencen`} en los próximos {query.data?.meta.expiring_days ?? 30} días.
        </Alert>
      )}

      {canManage && !open && (
        <Button variant="outlined" startIcon={<UploadFileOutlined />} onClick={() => setOpen(true)} sx={{ mb: 2 }}>
          Subir documento
        </Button>
      )}

      <Collapse in={open} unmountOnExit>
        <Card component="form" sx={{ p: 2, mb: 2, bgcolor: '#fff' }} onSubmit={(e) => (e.preventDefault(), file && form.name.trim().length >= 2 && upload.mutate())}>
          <input ref={fileInput} type="file" accept={ACCEPT} hidden onChange={(e) => pick(e.target.files?.[0])} />
          <Box
            role="button"
            tabIndex={0}
            onClick={() => fileInput.current?.click()}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && fileInput.current?.click()}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
              e.preventDefault()
              pick(e.dataTransfer.files?.[0])
            }}
            sx={{ p: 2.5, mb: 2, borderRadius: '12px', border: `2px dashed ${fileError ? tokens.error : tokens.outlineVariant}`, bgcolor: tokens.surfaceLow, textAlign: 'center', cursor: 'pointer', '&:hover, &:focus-visible': { borderColor: 'primary.main', outline: 'none' } }}
          >
            <AttachFile color="primary" />
            <Typography variant="body2" sx={{ fontWeight: 600 }}>
              {file ? `${file.name} (${kb(file.size)})` : 'Arrastra el archivo aquí o haz clic para elegirlo'}
            </Typography>
            <Typography variant="caption" color={fileError || fieldError(errors, 'file') ? 'error' : 'text.secondary'}>
              {fileError ?? fieldError(errors, 'file') ?? 'PDF, JPG o PNG de hasta 10 MB'}
            </Typography>
          </Box>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
            <TextField select label="Tipo" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {types.map((t) => (
                <MenuItem key={t.code} value={t.code}>
                  {t.label}
                </MenuItem>
              ))}
            </TextField>
            <TextField label="Nombre" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 200 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name')} />
            <TextField label="Emisión" type="date" value={form.issued_on} onChange={(e) => setForm({ ...form, issued_on: e.target.value })} InputLabelProps={{ shrink: true }} inputProps={{ max: todayInChile() }} />
            <TextField
              label="Vencimiento"
              type="date"
              value={form.expires_on}
              onChange={(e) => setForm({ ...form, expires_on: e.target.value })}
              InputLabelProps={{ shrink: true }}
              inputProps={{ min: form.issued_on || undefined }}
              error={Boolean(fieldError(errors, 'expires_on'))}
              helperText={fieldError(errors, 'expires_on') ?? 'Déjalo vacío si no vence'}
            />
          </Box>
          {upload.error && !Object.keys(errors).length && (
            <Alert severity="error" sx={{ mt: 1.5 }}>
              {upload.error instanceof ApiError ? upload.error.message : 'No fue posible subir el archivo.'}
            </Alert>
          )}
          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 2 }}>
            <Button onClick={() => setOpen(false)}>Cancelar</Button>
            <Button type="submit" variant="contained" disabled={!file || form.name.trim().length < 2 || upload.isPending}>
              {upload.isPending ? 'Subiendo…' : 'Subir documento'}
            </Button>
          </Stack>
        </Card>
      </Collapse>

      {!query.isLoading && docs.length === 0 && (
        <Typography variant="body2" color="text.secondary">
          Sin documentos. Sube certificaciones, pólizas o permisos para controlar su vigencia.
        </Typography>
      )}
      <Stack spacing={1}>
        {docs.map((d) => {
          const v = VALIDITY[d.validity]
          return (
            <Card key={d.public_id} sx={{ p: 1.5, bgcolor: '#fff' }}>
              <Stack direction="row" spacing={1.5} alignItems="center">
                <DescriptionOutlined color="primary" />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {d.name}
                    </Typography>
                    <Chip size="small" label={v.label} sx={{ bgcolor: v.bg, color: v.fg }} />
                  </Stack>
                  <Typography variant="caption" color="text.secondary">
                    {[d.type.label, d.expires_on ? `vence ${day(d.expires_on)}` : null, kb(d.file.size_bytes), d.uploaded_by ? `subido por ${d.uploaded_by}` : null].filter(Boolean).join(', ')}
                  </Typography>
                </Box>
                <Tooltip title="Descargar">
                  <IconButton component="a" href={d.download_url} aria-label={`Descargar ${d.name}`}>
                    <FileDownloadOutlined />
                  </IconButton>
                </Tooltip>
                {canManage && (
                  <Tooltip title="Archivar (deja de mostrarse; el archivo se conserva)">
                    <IconButton aria-label={`Archivar ${d.name}`} onClick={() => archive.mutate(d.public_id)} disabled={archive.isPending}>
                      <ArchiveOutlined />
                    </IconButton>
                  </Tooltip>
                )}
              </Stack>
            </Card>
          )
        })}
      </Stack>
    </Box>
  )
}
