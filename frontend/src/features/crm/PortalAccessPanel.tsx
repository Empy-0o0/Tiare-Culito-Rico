import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Checkbox from '@mui/material/Checkbox'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError } from '../../services/apiClient'
import { portalApi, type PartyType } from '../../services/portal'
import { formatDateTime } from '../../utils/format'
import { emailOk, PORTAL_SCOPE } from '../../utils/portalAccess'
import { InitialPasswordDialog } from '../admin/InitialPasswordDialog'

const errText = (e: unknown, fallback: string) => (e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : fallback)

/** Campos del formulario de alta: crear el usuario del portal junto con el cliente/proveedor (TD-028). */
export function PortalAccessFields({ type, value, onChange, error }: { type: PartyType; value: { enabled: boolean; email: string }; onChange: (v: { enabled: boolean; email: string }) => void; error?: string }) {
  return (
    <Box sx={{ mt: 2.5, p: 2, borderRadius: '12px', bgcolor: 'action.hover' }}>
      <FormControlLabel
        control={<Checkbox checked={value.enabled} onChange={(e) => onChange({ ...value, enabled: e.target.checked })} />}
        label={<Typography sx={{ fontWeight: 600 }}>Crear usuario y contraseña de acceso al portal</Typography>}
      />
      <Typography variant="body2" color="text.secondary" sx={{ ml: 4, mb: value.enabled ? 1.5 : 0 }}>
        {PORTAL_SCOPE[type]} Se genera una contraseña de inicio que se muestra una sola vez.
      </Typography>
      {value.enabled && (
        <TextField
          label="Correo de acceso"
          type="email"
          required
          fullWidth
          value={value.email}
          onChange={(e) => onChange({ ...value, email: e.target.value })}
          error={Boolean(error) || (value.email.length > 0 && !emailOk(value.email))}
          helperText={error ?? 'Será su usuario para ingresar a TIARE'}
        />
      )}
    </Box>
  )
}

/** Sección "Acceso al portal" en la ficha del cliente/proveedor. */
export function PortalAccessPanel({ type, partyId, partyName, canManage, defaultEmail }: { type: PartyType; partyId: string; partyName: string; canManage: boolean; defaultEmail?: string | null }) {
  const qc = useQueryClient()
  const key = ['portal-access', type, partyId]
  const q = useQuery({ queryKey: key, queryFn: () => portalApi.access(type, partyId) })
  const [email, setEmail] = useState(defaultEmail ?? '')
  const [error, setError] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [issued, setIssued] = useState<string | null>(null)
  const refresh = () => void qc.invalidateQueries({ queryKey: key })
  const fail = (e: unknown) => setError(errText(e, 'No fue posible completar la acción.'))

  const grant = useMutation({
    mutationFn: () => portalApi.grant(type, partyId, { email: email.trim() }),
    onSuccess: (r) => {
      setError(null)
      setMsg(r.data.message)
      if (r.data.initial_password) setIssued(r.data.initial_password)
      refresh()
    },
    onError: fail,
  })
  const password = useMutation({
    mutationFn: () => portalApi.password(type, partyId),
    onSuccess: (r) => {
      setError(null)
      setMsg(r.data.message)
      setIssued(r.data.initial_password)
      refresh()
    },
    onError: fail,
  })
  const toggle = useMutation({ mutationFn: (v: boolean) => portalApi.setActive(type, partyId, v), onSuccess: () => { setError(null); refresh() }, onError: fail })
  const revoke = useMutation({ mutationFn: () => portalApi.revoke(type, partyId), onSuccess: () => { setError(null); setMsg('Acceso revocado.'); refresh() }, onError: fail })
  const busy = grant.isPending || password.isPending || toggle.isPending || revoke.isPending

  if (q.isLoading) return <LinearProgress />
  const a = q.data?.data ?? null

  return (
    <Box>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
        {PORTAL_SCOPE[type]}
      </Typography>
      {error && (
        <Alert severity="error" sx={{ mb: 1.5 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      {msg && !error && (
        <Alert severity="success" sx={{ mb: 1.5 }} onClose={() => setMsg(null)}>
          {msg}
        </Alert>
      )}

      {!a ? (
        canManage ? (
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'flex-start' }}>
            <TextField size="small" label="Correo de acceso" type="email" value={email} onChange={(e) => setEmail(e.target.value)} sx={{ flex: 1 }} />
            <Button variant="contained" disabled={!emailOk(email) || busy} onClick={() => grant.mutate()}>
              Crear usuario y contraseña
            </Button>
          </Stack>
        ) : (
          <Alert severity="info">Este registro aún no tiene acceso al portal.</Alert>
        )
      ) : (
        <Stack spacing={1.5}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap', rowGap: 1 }}>
            <Typography sx={{ fontWeight: 700 }}>{a.user.email}</Typography>
            <Chip size="small" label={a.is_active ? 'Activo' : 'Desactivado'} color={a.is_active ? 'success' : 'default'} variant="outlined" />
            {a.must_change_password && <Chip size="small" color="warning" variant="outlined" label="Contraseña de inicio pendiente" />}
          </Stack>
          <Typography variant="caption" color="text.secondary">
            Último ingreso: {a.last_login_at ? formatDateTime(a.last_login_at) : 'aún no ingresa'} · creado {formatDateTime(a.created_at)}
          </Typography>
          {canManage && (
            <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap', rowGap: 1 }}>
              <FormControlLabel control={<Switch checked={a.is_active} disabled={busy} onChange={(e) => toggle.mutate(e.target.checked)} />} label="Acceso activo" />
              <Tooltip title={a.shared_account ? 'La cuenta también accede a otra empresa: debe usar "¿Olvidaste tu contraseña?"' : 'Reemplaza su contraseña, cierra sus sesiones y deberá cambiarla al ingresar'}>
                <span>
                  <Button
                    size="small"
                    color="warning"
                    disabled={busy || a.shared_account || !a.is_active}
                    onClick={() => window.confirm(`¿Generar una nueva contraseña de inicio para el portal de ${partyName}?`) && password.mutate()}
                  >
                    Generar contraseña de inicio
                  </Button>
                </span>
              </Tooltip>
              <Button size="small" color="error" disabled={busy} onClick={() => window.confirm(`¿Revocar el acceso al portal de ${partyName}? Su usuario dejará de poder ingresar.`) && revoke.mutate()}>
                Revocar acceso
              </Button>
            </Stack>
          )}
        </Stack>
      )}
      <InitialPasswordDialog value={issued} name={partyName} email={a?.user.email ?? email} onClose={() => setIssued(null)} />
    </Box>
  )
}
