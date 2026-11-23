import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Visibility from '@mui/icons-material/Visibility'
import VisibilityOff from '@mui/icons-material/VisibilityOff'
import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { ApiError } from '../../services/apiClient'
import { passwordApi } from '../../services/company'
import tiareImage from '../../assets/tiare.jpg'

export function LoginPage() {
  const { profile, login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [forgotOpen, setForgotOpen] = useState(false)
  const [forgotEmail, setForgotEmail] = useState('')
  const [forgotMsg, setForgotMsg] = useState<string | null>(null)
  const [forgotBusy, setForgotBusy] = useState(false)

  if (profile) return <Navigate to="/" replace />

  const requestReset = async (e: FormEvent) => {
    e.preventDefault()
    setForgotBusy(true)
    try {
      const r = await passwordApi.forgot(forgotEmail.trim())
      setForgotMsg(r.data.message)
    } catch (err) {
      setForgotMsg(err instanceof ApiError ? err.message : 'No fue posible enviar el enlace. Intenta en unos minutos.')
    } finally {
      setForgotBusy(false)
    }
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email, password)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No fue posible iniciar sesión.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1.1fr 0.9fr' } }}>
      <Box sx={{ bgcolor: tokens.onPrimaryContainer, color: '#FFD9E2', px: { xs: 3, md: 6 }, py: { xs: 2.5, md: 6 }, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: { xs: 1.5, md: 4 } }}>
        <Stack direction="row" spacing={1.25} alignItems="center">
          <Box sx={{ width: 40, height: 40, borderRadius: '10px', bgcolor: '#FFD9E2', color: tokens.onPrimaryContainer, display: 'grid', placeItems: 'center', fontWeight: 800 }}>T</Box>
          <Typography sx={{ fontWeight: 800, fontSize: '1.4rem', letterSpacing: '0.04em', color: '#fff' }}>TIARE</Typography>
        </Stack>
        <Box sx={{ display: { xs: 'none', md: 'block' } }}>
          <Box
            component="img"
            src={tiareImage}
            alt="TIARE"
            sx={{ display: 'block', width: 240, height: 240, objectFit: 'cover', borderRadius: '24px', border: '3px solid #F3A7C0', boxShadow: '0 12px 32px rgba(0,0,0,0.35)' }}
          />
        </Box>
        <Typography sx={{ maxWidth: '44ch', fontSize: { xs: '0.85rem', md: '0.95rem' }, color: '#F7D6DF' }}>
          Compras, abastecimiento y oportunidades públicas en un solo lugar. Datos oficiales de ChileCompra, con fuente y hora de actualización.
        </Typography>
      </Box>

      <Box sx={{ display: 'grid', placeItems: { xs: 'start center', md: 'center' }, p: 3 }}>
        <Card component="form" onSubmit={submit} noValidate sx={{ width: '100%', maxWidth: 400, p: { xs: 3, sm: 4 }, borderRadius: '24px' }}>
          <Typography variant="h2" component="h1">
            Iniciar sesión
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>
            Ingresa con tu correo de trabajo.
          </Typography>
          <Stack spacing={2}>
            <TextField label="Correo electrónico" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} size="medium" fullWidth />
            <TextField
              label="Contraseña"
              type={show ? 'text' : 'password'}
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              size="medium"
              fullWidth
              InputProps={{
                endAdornment: (
                  <InputAdornment position="end">
                    <IconButton aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'} onClick={() => setShow((v) => !v)} edge="end">
                      {show ? <VisibilityOff /> : <Visibility />}
                    </IconButton>
                  </InputAdornment>
                ),
              }}
            />
            {error && <Alert severity="error">{error}</Alert>}
            <Button type="submit" variant="contained" size="large" disabled={busy || !email || !password} fullWidth>
              {busy ? 'Ingresando…' : 'Ingresar'}
            </Button>
            <Button
              variant="text"
              onClick={() => {
                setForgotEmail(email)
                setForgotMsg(null)
                setForgotOpen(true)
              }}
            >
              ¿Olvidaste tu contraseña?
            </Button>
          </Stack>
        </Card>
      </Box>

      <Dialog open={forgotOpen} onClose={() => setForgotOpen(false)} fullWidth maxWidth="xs" PaperProps={{ component: 'form', onSubmit: requestReset, sx: { borderRadius: '24px' } }}>
        <DialogTitle sx={{ fontWeight: 700 }}>Recuperar contraseña</DialogTitle>
        <DialogContent>
          {forgotMsg ? (
            <Alert severity="info">{forgotMsg}</Alert>
          ) : (
            <>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                Te enviaremos un enlace para definir una nueva contraseña.
              </Typography>
              <TextField label="Correo electrónico" type="email" autoFocus fullWidth value={forgotEmail} onChange={(e) => setForgotEmail(e.target.value)} />
            </>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={() => setForgotOpen(false)}>{forgotMsg ? 'Cerrar' : 'Cancelar'}</Button>
          {!forgotMsg && (
            <Button type="submit" variant="contained" disabled={forgotBusy || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(forgotEmail.trim())}>
              {forgotBusy ? 'Enviando…' : 'Enviar enlace'}
            </Button>
          )}
        </DialogActions>
      </Dialog>
    </Box>
  )
}
