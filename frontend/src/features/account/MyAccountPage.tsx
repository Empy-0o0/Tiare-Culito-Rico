import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import List from '@mui/material/List'
import ListItem from '@mui/material/ListItem'
import ListItemIcon from '@mui/material/ListItemIcon'
import ListItemText from '@mui/material/ListItemText'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import CheckCircle from '@mui/icons-material/CheckCircle'
import KeyOutlined from '@mui/icons-material/KeyOutlined'
import PersonOutline from '@mui/icons-material/PersonOutline'
import RadioButtonUnchecked from '@mui/icons-material/RadioButtonUnchecked'
import Visibility from '@mui/icons-material/Visibility'
import VisibilityOff from '@mui/icons-material/VisibilityOff'
import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { PageHeader } from '../../components/PageHeader'
import { ApiError } from '../../services/apiClient'
import { authApi } from '../../services/publicProcurement'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDateTime } from '../../utils/format'
import { passwordChecks } from '../../utils/password'
import { SectionTitle } from '../settings/SectionTitle'

/** "Mi cuenta": datos de la persona y cambio de contraseña. Obligatorio si tiene una contraseña de inicio. */
export function MyAccountPage() {
  const { profile, refresh } = useAuth()
  const navigate = useNavigate()
  const forced = Boolean(profile?.must_change_password)
  const [form, setForm] = useState({ current: '', password: '', confirm: '' })
  const [show, setShow] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)

  const checks = passwordChecks(form.password, form.current, form.confirm)
  const valid = form.current.length > 0 && checks.every((c) => c.ok)

  const change = useMutation({
    mutationFn: () => authApi.changePassword({ current_password: form.current, password: form.password, password_confirmation: form.confirm }),
    onSuccess: async (r) => {
      setForm({ current: '', password: '', confirm: '' })
      setErrors({})
      setError(null)
      setToast(r.data.message)
      await refresh()
      if (forced) navigate('/', { replace: true })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? (e.status === 429 ? 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.' : e.message) : 'No fue posible cambiar la contraseña.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (valid && !change.isPending) change.mutate()
  }

  const eye = (
    <InputAdornment position="end">
      <IconButton aria-label={show ? 'Ocultar contraseñas' : 'Mostrar contraseñas'} onClick={() => setShow((v) => !v)} edge="end">
        {show ? <VisibilityOff /> : <Visibility />}
      </IconButton>
    </InputAdornment>
  )
  const type = show ? 'text' : 'password'

  return (
    <>
      <PageHeader title="Mi cuenta" subtitle="Tus datos de acceso y tu contraseña." />

      {forced && (
        <Alert severity="warning" sx={{ mb: 3 }}>
          Ingresaste con una <strong>contraseña de inicio</strong>. Por seguridad, debes cambiarla para continuar usando TIARE.
        </Alert>
      )}

      <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '1fr 1.4fr' }, alignItems: 'start' }}>
        <Card sx={{ p: { xs: 2, sm: 3 } }}>
          <SectionTitle icon={<PersonOutline />} title="Tu cuenta" subtitle="Para cambiar nombre o correo, pídeselo a un administrador." />
          <Stack spacing={1.5}>
            {[
              ['Nombre', profile?.user.name],
              ['Correo', profile?.user.email],
              ['Empresa activa', profile?.current_tenant?.name ?? 'Sin empresa asignada'],
              ['Último cambio de contraseña', profile?.password_changed_at ? formatDateTime(profile.password_changed_at) : 'Sin registro'],
            ].map(([k, v]) => (
              <Box key={k}>
                <Typography variant="caption" color="text.secondary">
                  {k}
                </Typography>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {v}
                </Typography>
              </Box>
            ))}
          </Stack>
        </Card>

        <Card component="form" onSubmit={submit} noValidate sx={{ p: { xs: 2, sm: 3 } }}>
          <SectionTitle icon={<KeyOutlined />} title="Cambiar contraseña" subtitle="Al cambiarla se cierran tus otras sesiones abiertas." />
          <Stack spacing={2}>
            <TextField
              label={forced ? 'Contraseña de inicio' : 'Contraseña actual'}
              type={type}
              autoComplete="current-password"
              required
              value={form.current}
              onChange={(e) => setForm({ ...form, current: e.target.value })}
              error={Boolean(fieldError(errors, 'current_password'))}
              helperText={fieldError(errors, 'current_password')}
              InputProps={{ endAdornment: eye }}
            />
            <TextField
              label="Nueva contraseña"
              type={type}
              autoComplete="new-password"
              required
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              error={Boolean(fieldError(errors, 'password'))}
              helperText={fieldError(errors, 'password')}
            />
            <TextField label="Repite la nueva contraseña" type={type} autoComplete="new-password" required value={form.confirm} onChange={(e) => setForm({ ...form, confirm: e.target.value })} />
            <List dense disablePadding aria-label="Requisitos de la contraseña">
              {checks.map((c) => (
                <ListItem key={c.label} disableGutters sx={{ py: 0 }}>
                  <ListItemIcon sx={{ minWidth: 30 }}>{c.ok ? <CheckCircle fontSize="small" color="success" /> : <RadioButtonUnchecked fontSize="small" color="disabled" />}</ListItemIcon>
                  <ListItemText primary={c.label} primaryTypographyProps={{ variant: 'body2', color: c.ok ? 'text.primary' : 'text.secondary' }} />
                </ListItem>
              ))}
            </List>
            {error && <Alert severity="error">{error}</Alert>}
            <Button type="submit" variant="contained" size="large" disabled={!valid || change.isPending}>
              {change.isPending ? 'Guardando…' : 'Cambiar contraseña'}
            </Button>
          </Stack>
        </Card>
      </Box>
      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
