import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import IconButton from '@mui/material/IconButton'
import LinearProgress from '@mui/material/LinearProgress'
import List from '@mui/material/List'
import ListItem from '@mui/material/ListItem'
import ListItemIcon from '@mui/material/ListItemIcon'
import ListItemText from '@mui/material/ListItemText'
import Stack from '@mui/material/Stack'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline'
import ContentCopy from '@mui/icons-material/ContentCopy'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import DescriptionOutlined from '@mui/icons-material/DescriptionOutlined'
import ErrorOutline from '@mui/icons-material/ErrorOutline'
import FileDownloadOutlined from '@mui/icons-material/FileDownloadOutlined'
import FolderZipOutlined from '@mui/icons-material/FolderZipOutlined'
import InfoOutlined from '@mui/icons-material/InfoOutlined'
import OpenInNew from '@mui/icons-material/OpenInNew'
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState, type DragEvent } from 'react'
import { tokens } from '../../app/theme'
import { DetailSection } from '../../components/Drawer'
import { ApiError } from '../../services/apiClient'
import { opportunityFilesApi, type OpportunitySourceType } from '../../services/opportunityFiles'
import { formatDateTime } from '../../utils/format'

const sizeLabel = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png'

type UploadResult = { file: string; status: 'linked' | 'unlinked' | 'duplicate' | 'error'; detail: string }

/**
 * Documentos de un proceso de Mercado Público (TD-025, TD-030).
 * La API oficial solo informa el nombre de cada adjunto (no el archivo) y sus condiciones exigen usar
 * solo sus mecanismos, por lo que TIARE no descarga desde el portal. La persona descarga los anexos
 * en Mercado Público, los arrastra aquí todos juntos y TIARE asocia cada uno con su adjunto oficial;
 * el equipo los descarga después uno a uno o todos en un ZIP.
 */
export function OpportunityDocumentsSection({ sourceType, code }: { sourceType: OpportunitySourceType; code: string }) {
  const qc = useQueryClient()
  const key = ['opportunity-files', sourceType, code]
  const q = useQuery({ queryKey: key, queryFn: () => opportunityFilesApi.list(sourceType, code), retry: false })
  const input = useRef<HTMLInputElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const [dragging, setDragging] = useState(false)
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null)
  const [results, setResults] = useState<UploadResult[]>([])

  const remove = useMutation({
    mutationFn: (id: string) => opportunityFilesApi.remove(id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
    onError: (e) => setError(e instanceof ApiError ? e.message : 'No fue posible eliminar el archivo.'),
  })

  const officialName = (id: string | null) => q.data?.data.official.find((d) => d.id === id)?.name ?? id

  /** Sube los archivos uno tras otro (cada uno se asocia solo con su adjunto oficial). */
  const uploadAll = async (list: FileList | File[]) => {
    const files = Array.from(list)
    if (files.length === 0) return
    setError(null)
    setResults([])
    setProgress({ done: 0, total: files.length })
    const out: UploadResult[] = []
    for (const [i, file] of files.entries()) {
      try {
        const r = await opportunityFilesApi.upload(sourceType, code, { file })
        out.push(
          r.data.duplicate
            ? { file: file.name, status: 'duplicate', detail: 'Ya estaba guardado' }
            : r.data.official_document_id
              ? { file: file.name, status: 'linked', detail: `Asociado a: ${officialName(r.data.official_document_id)}` }
              : { file: file.name, status: 'unlinked', detail: 'Guardado como otro documento (no coincide con un adjunto oficial)' },
        )
      } catch (e) {
        out.push({ file: file.name, status: 'error', detail: e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No se pudo subir' })
      }
      setProgress({ done: i + 1, total: files.length })
      setResults([...out])
    }
    setProgress(null)
    void qc.invalidateQueries({ queryKey: key })
  }

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    if (q.data?.meta.can_upload) void uploadAll(e.dataTransfer.files)
  }

  if (q.isLoading) return <LinearProgress />
  if (q.error || !q.data) return null

  const { official: docs, files } = q.data.data
  const meta = q.data.meta
  const hasCopy = (id: string) => files.some((f) => f.official_document_id === id)
  const missing = docs.filter((d) => !hasCopy(d.id))
  const uploading = progress !== null

  return (
    <DetailSection title="Documentación adjunta">
      <Alert severity="info" sx={{ mb: 1.5 }}>
        {meta.official_download_note} Puedes arrastrar todos los anexos juntos: TIARE asocia cada uno con su adjunto oficial.
      </Alert>
      <Stack direction="row" spacing={1} sx={{ mb: 1.5, flexWrap: 'wrap', rowGap: 1 }}>
        <Button size="small" variant="outlined" startIcon={<OpenInNew />} href={meta.portal_url} target="_blank" rel="noopener noreferrer">
          Abrir Mercado Público
        </Button>
        <Button
          size="small"
          startIcon={<ContentCopy />}
          onClick={() =>
            void navigator.clipboard?.writeText(code).then(() => {
              setCopied(true)
              setTimeout(() => setCopied(false), 2000)
            })
          }
        >
          {copied ? 'Código copiado' : `Copiar código ${code}`}
        </Button>
        {meta.archive_url && (
          <Button size="small" variant="contained" startIcon={<FolderZipOutlined />} href={meta.archive_url}>
            Descargar todo ({files.length})
          </Button>
        )}
      </Stack>

      <Typography variant="subtitle2" sx={{ mt: 1 }}>
        Adjuntos publicados ({docs.length})
      </Typography>
      {docs.length === 0 ? (
        <Typography variant="body2" color="text.secondary">
          La API no informa adjuntos para este proceso.
        </Typography>
      ) : (
        <List dense disablePadding>
          {docs.map((d) => (
            <ListItem key={d.id} disableGutters secondaryAction={hasCopy(d.id) ? <Chip size="small" color="success" variant="outlined" label="Copia en TIARE" /> : <Chip size="small" variant="outlined" label="Solo en el portal" />}>
              <ListItemIcon sx={{ minWidth: 36 }}>
                <DescriptionOutlined fontSize="small" color="primary" />
              </ListItemIcon>
              <ListItemText primary={d.name ?? `Documento ${d.id}`} secondary="Dato oficial · ChileCompra" sx={{ pr: 14 }} />
            </ListItem>
          ))}
        </List>
      )}
      {docs.length > 0 && missing.length > 0 && files.length > 0 && (
        <Alert severity="warning" sx={{ mt: 1 }}>
          Falta{missing.length === 1 ? '' : 'n'} {missing.length} de {docs.length} adjunto{docs.length === 1 ? '' : 's'} por guardar en TIARE.
        </Alert>
      )}
      {docs.length > 0 && missing.length === 0 && (
        <Alert severity="success" sx={{ mt: 1 }}>
          Todos los adjuntos oficiales tienen copia en TIARE.
        </Alert>
      )}

      <Typography variant="subtitle2" sx={{ mt: 2 }}>
        Copias guardadas por tu empresa ({files.length})
      </Typography>
      {files.length === 0 && (
        <Typography variant="body2" color="text.secondary">
          Aún no hay copias. Descarga los anexos en el portal y súbelos aquí.
        </Typography>
      )}
      <List dense disablePadding>
        {files.map((f) => (
          <ListItem
            key={f.public_id}
            disableGutters
            secondaryAction={
              <Stack direction="row">
                <Tooltip title="Descargar">
                  <IconButton component="a" href={f.download_url} aria-label={`Descargar ${f.name}`}>
                    <FileDownloadOutlined fontSize="small" />
                  </IconButton>
                </Tooltip>
                {meta.can_upload && (
                  <Tooltip title="Eliminar copia">
                    <IconButton aria-label={`Eliminar ${f.name}`} disabled={remove.isPending} onClick={() => window.confirm(`¿Eliminar la copia "${f.name}"? El documento oficial no se ve afectado.`) && remove.mutate(f.public_id)}>
                      <DeleteOutline fontSize="small" />
                    </IconButton>
                  </Tooltip>
                )}
              </Stack>
            }
          >
            <ListItemIcon sx={{ minWidth: 36 }}>
              <DescriptionOutlined fontSize="small" />
            </ListItemIcon>
            <ListItemText
              primary={f.name}
              secondary={`${f.official_document_id ? `Corresponde a: ${officialName(f.official_document_id)} · ` : ''}${f.file.name} · ${sizeLabel(f.file.size_bytes)} · ${f.uploaded_by ?? '—'} · ${formatDateTime(f.uploaded_at)}`}
              sx={{ pr: 10 }}
            />
          </ListItem>
        ))}
      </List>

      {meta.can_upload && (
        <Box
          role="button"
          tabIndex={0}
          aria-label="Subir copias descargadas: arrastra los archivos aquí o presiona para elegirlos"
          onClick={() => !uploading && input.current?.click()}
          onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && !uploading && input.current?.click()}
          onDragOver={(e) => {
            e.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          sx={{
            mt: 1.5,
            p: 2.5,
            textAlign: 'center',
            borderRadius: '16px',
            border: `2px dashed ${dragging ? tokens.primary : tokens.outlineVariant}`,
            bgcolor: dragging ? tokens.primaryContainer : 'transparent',
            cursor: uploading ? 'progress' : 'pointer',
            transition: 'background-color .15s, border-color .15s',
            '&:focus-visible': { outline: `2px solid ${tokens.primary}`, outlineOffset: 2 },
          }}
        >
          <UploadFileOutlined color="primary" />
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {uploading ? `Subiendo ${progress.done} de ${progress.total}…` : 'Arrastra aquí los anexos descargados (puedes soltar varios a la vez)'}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            o presiona para elegirlos · {meta.accepted} · Las copias son privadas de tu empresa
          </Typography>
          {uploading && <LinearProgress variant="determinate" value={(progress.done / progress.total) * 100} sx={{ mt: 1, borderRadius: '4px' }} />}
          <input
            ref={input}
            type="file"
            hidden
            multiple
            aria-label="Archivos a subir"
            accept={ACCEPT}
            onChange={(e) => {
              if (e.target.files) void uploadAll(e.target.files)
              e.target.value = ''
            }}
          />
        </Box>
      )}

      {results.length > 0 && (
        <List dense disablePadding sx={{ mt: 1 }} aria-label="Resultado de la subida">
          {results.map((r, i) => (
            <ListItem key={i} disableGutters>
              <ListItemIcon sx={{ minWidth: 32 }}>
                {r.status === 'error' ? <ErrorOutline fontSize="small" color="error" /> : r.status === 'linked' ? <CheckCircleOutline fontSize="small" color="success" /> : <InfoOutlined fontSize="small" color="action" />}
              </ListItemIcon>
              <ListItemText primary={r.file} secondary={r.detail} primaryTypographyProps={{ variant: 'body2' }} />
            </ListItem>
          ))}
        </List>
      )}
      {error && (
        <Alert severity="error" sx={{ mt: 1 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
    </DetailSection>
  )
}
