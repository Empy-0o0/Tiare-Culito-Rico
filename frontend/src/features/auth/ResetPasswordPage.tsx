import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { ApiError } from '../../services/apiClient'
import { passwordApi } from '../../services/company'

/** Lee token y correo del fragmento (#token=…&email=…): el fragmento nunca se envía al servidor. */
function readFragment(): { token: string; email: string } {
  const params = new URLSearchParams(window.location.hash.replace(/^#/, ''))
  return { token: params.get('token') ?? '', email: params.get('email') ?? '' }
}

const strong = (p: string) => p.length >= 10 && /[A-Za-z]/.test(p) && /\d/.test(p)

/** Definir contraseña (invitación) o restablecerla con el enlace recibido por correo. */
export function ResetPasswordPage() {
  const { token, email } = useMemo(readFragment, [])
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // Quita el token de la barra de direcciones apenas se lee.
  useEffect(() => window.history.replaceState(null, '', window.location.pathname), [])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const r = await passwordApi.reset({ token, email, password, password_confirmation: confirm })
      setDone(r.data.message)
    } catch (err) {
      setError(err instanceof ApiError ? (Object.values(err.details ?? {}).flat()[0] ?? err.message) : 'No fue posible guardar la contraseña.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2, bgcolor: tokens.surfaceLow }}>
      <Card component="form" onSubmit={submit} sx={{ width: '100%', maxWidth: 420, p: { xs: 3, sm: 4 }, borderRadius: '24px' }}>
        <Typography variant="h2" component="h1">
          Define tu contraseña
        </Typography>
        {!token || !email ? (
          <Alert severity="warning" sx={{ mt: 2 }}>
            El enlace está incompleto. Ábrelo directamente desde el correo o solicita uno nuevo en la pantalla de acceso.
          </Alert>
        ) : done ? (
          <>
            <Alert severity="success" sx={{ mt: 2 }}>
              {done}
            </Alert>
            <Button component={Link} to="/login" variant="contained" fullWidth sx={{ mt: 2 }}>
              Ir a iniciar sesión
            </Button>
          </>
        ) : (
          <Stack spacing={2} sx={{ mt: 2 }}>
            <Typography variant="body2" color="text.secondary">
              Cuenta: <strong>{email}</strong>
            </Typography>
            <TextField
              label="Nueva contraseña"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              size="medium"
              helperText="Al menos 10 caracteres, con letras y números"
              error={Boolean(password) && !strong(password)}
            />
            <TextField
              label="Repite la contraseña"
              type="password"
              autoComplete="new-password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              size="medium"
              error={Boolean(confirm) && confirm !== password}
              helperText={confirm && confirm !== password ? 'Las contraseñas no coinciden' : ' '}
            />
            {error && <Alert severity="error">{error}</Alert>}
            <Button type="submit" variant="contained" size="large" disabled={busy || !strong(password) || confirm !== password}>
              {busy ? 'Guardando…' : 'Guardar contraseña'}
            </Button>
          </Stack>
        )}
      </Card>
    </Box>
  )
}
