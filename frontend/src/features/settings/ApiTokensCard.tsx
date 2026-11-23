import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Checkbox from '@mui/material/Checkbox'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import IconButton from '@mui/material/IconButton'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import ContentCopy from '@mui/icons-material/ContentCopy'
import KeyOutlined from '@mui/icons-material/KeyOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { FormDialog } from '../../components/FormDialog'
import { ErrorState } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { tokensApi } from '../../services/company'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDateTime } from '../../utils/format'
import { SectionTitle } from './SectionTitle'

/**
 * Tokens de acceso API para integraciones de la empresa (Authorization: Bearer).
 * El valor completo se muestra una sola vez y nunca se guarda en el navegador.
 */
export function ApiTokensCard({ onToast }: { onToast: (msg: string) => void }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['api-tokens'], queryFn: tokensApi.list })
  const available = list.data?.meta.available_abilities ?? []
  const expiryDays = list.data?.meta.expiry_days ?? [30, 90]

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<{ name: string; abilities: string[]; expires_in_days: number }>({ name: '', abilities: [], expires_in_days: 90 })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [plain, setPlain] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: () => tokensApi.create({ name: form.name.trim(), abilities: form.abilities, expires_in_days: form.expires_in_days }),
    onSuccess: (r) => {
      setOpen(false)
      setPlain(r.data.plain_text_token)
      setForm({ name: '', abilities: [], expires_in_days: 90 })
      void qc.invalidateQueries({ queryKey: ['api-tokens'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible crear el token.')
    },
  })
  const revoke = useMutation({
    mutationFn: (id: string) => tokensApi.revoke(id),
    onSuccess: () => {
      onToast('Token revocado: deja de funcionar de inmediato.')
      void qc.invalidateQueries({ queryKey: ['api-tokens'] })
    },
    onError: (e) => onToast(e instanceof ApiError ? e.message : 'No fue posible revocar el token.'),
  })

  const toggle = (perm: string) => setForm((f) => ({ ...f, abilities: f.abilities.includes(perm) ? f.abilities.filter((a) => a !== perm) : [...f.abilities, perm] }))
  const readOnly = available.filter((p) => p.endsWith('.read'))

  return (
    <Card sx={{ p: { xs: 2, sm: 3 }, mt: 3 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1}>
        <SectionTitle icon={<KeyOutlined />} title="Tokens de acceso API" subtitle="Para conectar sistemas de tu empresa con TIARE. Cada token tiene permisos limitados y vence." />
        <Button
          variant="contained"
          startIcon={<KeyOutlined />}
          sx={{ mb: 2 }}
          onClick={() => {
            setErrors({})
            setError(null)
            setOpen(true)
          }}
        >
          Crear token
        </Button>
      </Stack>

      {plain && (
        <Alert severity="warning" sx={{ mb: 2 }} onClose={() => setPlain(null)}>
          <Typography variant="body2" sx={{ fontWeight: 700, mb: 1 }}>
            Copia este token ahora: no se volverá a mostrar.
          </Typography>
          <Stack direction="row" spacing={1} alignItems="center">
            <Box component="code" sx={{ p: 1, bgcolor: '#fff', borderRadius: '8px', wordBreak: 'break-all', fontSize: '0.8rem', flex: 1 }}>
              {plain}
            </Box>
            <Tooltip title="Copiar">
              <IconButton aria-label="Copiar token" onClick={() => void navigator.clipboard?.writeText(plain).then(() => onToast('Token copiado.'))}>
                <ContentCopy fontSize="small" />
              </IconButton>
            </Tooltip>
          </Stack>
          <Typography variant="caption" component="p" sx={{ mt: 1 }}>
            Úsalo en el encabezado <code>Authorization: Bearer &lt;token&gt;</code>. Guárdalo en un gestor de secretos; no lo envíes por correo ni chat.
          </Typography>
        </Alert>
      )}

      {(list.isLoading || revoke.isPending) && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="los tokens" />}
      {list.data && list.data.data.length === 0 && <Alert severity="info">No has creado tokens para esta empresa.</Alert>}
      {list.data && list.data.data.length > 0 && (
        <DataTable
          columns={[{ label: 'Nombre' }, { label: 'Permisos', hideOnMobile: true }, { label: 'Último uso', hideOnMobile: true }, { label: 'Vence' }, { label: '', align: 'right' }]}
          rows={list.data.data.map((t) => ({
            key: t.id,
            cells: [
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {t.name}
              </Typography>,
              <Typography variant="caption" color="text.secondary">
                {t.abilities.join(', ')}
              </Typography>,
              <Typography variant="caption" color="text.secondary">
                {t.last_used_at ? formatDateTime(t.last_used_at) : 'Sin uso'}
              </Typography>,
              t.expired ? <Chip label="Vencido" size="small" color="error" variant="outlined" /> : <Typography variant="caption">{formatDateTime(t.expires_at)}</Typography>,
              <Button size="small" color="error" disabled={revoke.isPending} onClick={() => window.confirm(`¿Revocar el token "${t.name}"? Las integraciones que lo usen dejarán de funcionar.`) && revoke.mutate(t.id)}>
                Revocar
              </Button>,
            ],
          }))}
        />
      )}

      <FormDialog
        open={open}
        title="Crear token de acceso API"
        onClose={() => setOpen(false)}
        onSubmit={() => create.mutate()}
        submitLabel="Crear token"
        submitting={create.isPending}
        canSubmit={form.name.trim().length >= 3 && form.abilities.length > 0}
        error={error}
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr' } }}>
          <TextField label="Nombre" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 80 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name') ?? 'Ej.: ERP contabilidad'} />
          <TextField select label="Vence en" value={form.expires_in_days} onChange={(e) => setForm({ ...form, expires_in_days: Number(e.target.value) })}>
            {expiryDays.map((d) => (
              <MenuItem key={d} value={d}>
                {d} días
              </MenuItem>
            ))}
          </TextField>
        </Box>
        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 2 }}>
          <Typography variant="subtitle2">Permisos del token</Typography>
          <Button size="small" onClick={() => setForm({ ...form, abilities: readOnly })}>
            Solo lectura
          </Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" component="p">
          Solo puedes otorgar permisos que tú tienes en esta empresa y que tu plan incluye. Los tokens nunca pueden administrar usuarios ni otros tokens.
        </Typography>
        {fieldError(errors, 'abilities') && (
          <Alert severity="error" sx={{ mt: 1 }}>
            {fieldError(errors, 'abilities')}
          </Alert>
        )}
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, mt: 1 }}>
          {available.map((p) => (
            <FormControlLabel key={p} control={<Checkbox size="small" checked={form.abilities.includes(p)} onChange={() => toggle(p)} />} label={<Typography variant="body2">{p}</Typography>} />
          ))}
        </Box>
      </FormDialog>
    </Card>
  )
}
