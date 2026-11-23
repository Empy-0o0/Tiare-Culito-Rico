import Alert from '@mui/material/Alert'
import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Radio from '@mui/material/Radio'
import RadioGroup from '@mui/material/RadioGroup'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import ApartmentOutlined from '@mui/icons-material/ApartmentOutlined'
import PersonRemoveOutlined from '@mui/icons-material/PersonRemoveOutlined'
import GroupOutlined from '@mui/icons-material/GroupOutlined'
import KeyOutlined from '@mui/icons-material/KeyOutlined'
import PersonAddAlt1Outlined from '@mui/icons-material/PersonAddAlt1Outlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { FormDialog } from '../../components/FormDialog'
import { ErrorState } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { ApiError } from '../../services/apiClient'
import { companyApi } from '../../services/company'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDateTime } from '../../utils/format'
import { formatRut, isValidRut } from '../../utils/rut'
import { InitialPasswordDialog } from '../admin/InitialPasswordDialog'
import { ApiTokensCard } from './ApiTokensCard'
import { PlanCard } from './PlanCard'
import { SectionTitle } from './SectionTitle'

const ROLE_HINT: Record<string, string> = {
  tenant_admin: 'Todo en la empresa, incluidos usuarios y datos de la empresa',
  tenant_procurement_user: 'Crea y sigue solicitudes de compra; consulta proveedores y catálogo',
  tenant_approver: 'Aprueba o rechaza solicitudes y compara cotizaciones',
  tenant_read_only: 'Solo consulta',
}

const initials = (n: string) =>
  n
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')

/** Monto máximo (CLP) que la persona puede aprobar. Vacío = sin límite. Se guarda al salir del campo. */
function ApprovalLimitField({ name, value, disabled, onCommit }: { name: string; value: string | null; disabled: boolean; onCommit: (v: number | null) => void }) {
  const initial = value ? String(Math.round(Number(value))) : ''
  const [v, setV] = useState(initial)
  const commit = () => {
    if (v === initial) return
    onCommit(v === '' ? null : Math.max(0, Number(v)))
  }
  return (
    <TextField
      size="small"
      type="number"
      value={v}
      disabled={disabled}
      onChange={(e) => setV(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
      placeholder="Sin límite"
      inputProps={{ min: 0, step: 1000, 'aria-label': `Límite de aprobación de ${name}` }}
      InputProps={{ startAdornment: <InputAdornment position="start">$</InputAdornment> }}
      sx={{ width: 170 }}
    />
  )
}

/** Configuración de la empresa activa: datos de la empresa y personas con acceso. */
export function SettingsPage() {
  const { profile } = useAuth()
  const qc = useQueryClient()
  const [toast, setToast] = useState<string | null>(null)
  const company = useQuery({ queryKey: ['company'], queryFn: companyApi.get })
  const c = company.data?.data

  // ---- Datos de la empresa
  const [form, setForm] = useState({ name: '', legal_name: '', rut: '' })
  const [companyErrors, setCompanyErrors] = useState<FieldErrors>({})
  useEffect(() => {
    if (c) setForm({ name: c.name, legal_name: c.legal_name ?? '', rut: c.rut ?? '' })
  }, [c])
  const saveCompany = useMutation({
    mutationFn: () => companyApi.update({ name: form.name.trim(), legal_name: form.legal_name.trim() || null, rut: form.rut.trim() || null }),
    onSuccess: (r) => {
      qc.setQueryData(['company'], r)
      setCompanyErrors({})
      setToast('Datos de la empresa guardados.')
    },
    onError: (e) => setCompanyErrors(e instanceof ApiError ? (e.details ?? {}) : {}),
  })
  const rutInvalid = Boolean(form.rut && !isValidRut(form.rut))
  const dirty = c && (form.name !== c.name || form.legal_name !== (c.legal_name ?? '') || form.rut !== (c.rut ?? ''))

  // ---- Usuarios
  const users = useQuery({ queryKey: ['company-users'], queryFn: companyApi.users, enabled: Boolean(c?.can_manage_users) })
  const roles = useQuery({ queryKey: ['company-roles'], queryFn: companyApi.roles, enabled: Boolean(c?.can_manage_users), staleTime: Infinity })
  const updateUser = useMutation({
    mutationFn: (v: { id: string; role_key?: string; is_active?: boolean; approval_limit_clp?: number | null }) =>
      companyApi.updateUser(v.id, { role_key: v.role_key, is_active: v.is_active, ...(v.approval_limit_clp !== undefined ? { approval_limit_clp: v.approval_limit_clp } : {}) }),
    onSuccess: (r) => {
      qc.setQueryData(['company-users'], r)
      setToast('Acceso actualizado.')
    },
    onError: (e) => setToast(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible actualizar.'),
  })

  const removeUser = useMutation({
    mutationFn: (u: { id: string; name: string }) => companyApi.removeUser(u.id).then((r) => ({ r, u })),
    onSuccess: ({ r, u }) => {
      qc.setQueryData(['company-users'], r)
      setToast(`${u.name} ya no tiene acceso a la empresa.`)
      void qc.invalidateQueries({ queryKey: ['company'] })
    },
    onError: (e) => setToast(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible quitar a la persona.'),
  })

  // Contraseña de inicio recién generada: solo en memoria mientras el diálogo está abierto (TD-027).
  const [issued, setIssued] = useState<{ value: string; name: string; email?: string } | null>(null)
  const regenerate = useMutation({
    mutationFn: (u: { id: string; name: string; email: string }) => companyApi.initialPassword(u.id).then((r) => ({ r, u })),
    onSuccess: ({ r, u }) => {
      setIssued({ value: r.data.initial_password, name: u.name, email: u.email })
      setToast(r.data.message)
      void qc.invalidateQueries({ queryKey: ['company-users'] })
    },
    onError: (e) => setToast(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible generar la contraseña.'),
  })

  const emptyInvite = { name: '', email: '', role_key: 'tenant_procurement_user', credential: 'password' as 'link' | 'password' }
  const [inviteOpen, setInviteOpen] = useState(false)
  const [invite, setInvite] = useState(emptyInvite)
  const [inviteErrors, setInviteErrors] = useState<FieldErrors>({})
  const [inviteError, setInviteError] = useState<string | null>(null)
  const sendInvite = useMutation({
    mutationFn: () => companyApi.invite({ name: invite.name.trim(), email: invite.email.trim(), role_key: invite.role_key, credential: invite.credential }),
    onSuccess: (r) => {
      setInviteOpen(false)
      if (r.data.initial_password) setIssued({ value: r.data.initial_password, name: invite.name.trim(), email: invite.email.trim() })
      setInvite(emptyInvite)
      setToast(r.data.message)
      void qc.invalidateQueries({ queryKey: ['company-users'] })
      void qc.invalidateQueries({ queryKey: ['company'] })
    },
    onError: (e) => {
      setInviteErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setInviteError(e instanceof ApiError ? e.message : 'No fue posible enviar la invitación.')
    },
  })
  const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(invite.email.trim())

  if (company.isLoading) return <LinearProgress sx={{ borderRadius: '4px' }} />
  if (company.error || !c) return <ErrorState error={company.error} what="la configuración" />

  return (
    <>
      <PageHeader title="Configuración" subtitle="Datos de la empresa activa, plan contratado, personas con acceso y tokens API." />

      <PlanCard company={c} />

      <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <SectionTitle icon={<ApartmentOutlined />} title="Empresa" subtitle={c.is_demo ? 'Empresa demostrativa: sus registros son ficticios.' : `${c.members_count} personas con acceso activo`} />
        <Box
          component="form"
          onSubmit={(e) => {
            e.preventDefault()
            if (c.can_manage && dirty && !rutInvalid) saveCompany.mutate()
          }}
          sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr 220px' }, alignItems: 'start' }}
        >
          <TextField
            label="Nombre de la empresa"
            required
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            disabled={!c.can_manage}
            inputProps={{ maxLength: 160 }}
            error={Boolean(fieldError(companyErrors, 'name'))}
            helperText={fieldError(companyErrors, 'name') ?? 'Como aparece en TIARE'}
          />
          <TextField
            label="Razón social"
            value={form.legal_name}
            onChange={(e) => setForm({ ...form, legal_name: e.target.value })}
            disabled={!c.can_manage}
            inputProps={{ maxLength: 200 }}
          />
          <TextField
            label="RUT"
            value={form.rut}
            onChange={(e) => setForm({ ...form, rut: e.target.value })}
            onBlur={() => form.rut && isValidRut(form.rut) && setForm({ ...form, rut: formatRut(form.rut) })}
            disabled={!c.can_manage}
            error={rutInvalid || Boolean(fieldError(companyErrors, 'rut'))}
            helperText={rutInvalid ? 'RUT no válido.' : fieldError(companyErrors, 'rut')}
            inputProps={{ maxLength: 14 }}
          />
          {c.can_manage ? (
            <Stack direction="row" spacing={1} sx={{ gridColumn: '1 / -1' }}>
              <Button type="submit" variant="contained" disabled={!dirty || rutInvalid || form.name.trim().length < 2 || saveCompany.isPending}>
                {saveCompany.isPending ? 'Guardando…' : 'Guardar cambios'}
              </Button>
              {dirty && <Button onClick={() => setForm({ name: c.name, legal_name: c.legal_name ?? '', rut: c.rut ?? '' })}>Descartar</Button>}
            </Stack>
          ) : (
            <Typography variant="caption" color="text.secondary" sx={{ gridColumn: '1 / -1' }}>
              Solo un administrador de la empresa puede modificar estos datos.
            </Typography>
          )}
        </Box>
      </Card>

      <Card sx={{ p: { xs: 2, sm: 3 } }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1}>
          <SectionTitle icon={<GroupOutlined />} title="Personas" subtitle="Quién puede entrar a esta empresa y qué puede hacer." />
          {c.can_manage_users && (
            <Button
              variant="contained"
              startIcon={<PersonAddAlt1Outlined />}
              onClick={() => {
                setInviteErrors({})
                setInviteError(null)
                const keys = roles.data?.data.map((r) => r.key) ?? []
                setInvite((v) => ({ ...v, role_key: keys.includes(v.role_key) ? v.role_key : (keys[0] ?? '') }))
                setInviteOpen(true)
              }}
              sx={{ mb: 2 }}
            >
              Invitar persona
            </Button>
          )}
        </Stack>

        {!c.can_manage_users ? (
          <Alert severity="info">Solo un administrador de la empresa puede ver y gestionar a las personas con acceso.</Alert>
        ) : (
          <>
            {(users.isLoading || updateUser.isPending) && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
            {users.error && <ErrorState error={users.error} what="las personas" />}
            {users.data && (
              <DataTable
                columns={[{ label: 'Persona' }, { label: 'Rol' }, { label: 'Límite de aprobación', hideOnMobile: true }, { label: 'Último ingreso', hideOnMobile: true }, { label: 'Acceso', align: 'right' }]}
                rows={users.data.data.map((u) => {
                  const self = u.public_id === profile?.user.public_id
                  return {
                    key: u.public_id,
                    cells: [
                      <Stack direction="row" spacing={1.5} alignItems="center">
                        <Avatar sx={{ width: 34, height: 34, fontSize: '0.8rem', bgcolor: u.is_active ? 'primary.main' : tokens.surfaceHigh, color: u.is_active ? '#fff' : tokens.onSurfaceVariant }}>
                          {initials(u.name)}
                        </Avatar>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {u.name} {self && <Chip size="small" label="Tú" sx={{ ml: 0.5, height: 20 }} />}
                            {u.must_change_password && <Chip size="small" color="warning" variant="outlined" label="Contraseña de inicio" sx={{ ml: 0.5, height: 20 }} />}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            {u.email}
                          </Typography>
                        </Box>
                      </Stack>,
                      <Tooltip title={self ? 'No puedes cambiar tu propio rol' : (ROLE_HINT[u.role.key ?? ''] ?? '')}>
                        <span>
                          <TextField
                            select
                            size="small"
                            value={u.role.key ?? ''}
                            disabled={self || updateUser.isPending}
                            onChange={(e) => updateUser.mutate({ id: u.public_id, role_key: e.target.value })}
                            inputProps={{ 'aria-label': `Rol de ${u.name}` }}
                            sx={{ minWidth: 200 }}
                          >
                            {(roles.data?.data ?? []).map((r) => (
                              <MenuItem key={r.key} value={r.key}>
                                {r.name}
                              </MenuItem>
                            ))}
                          </TextField>
                        </span>
                      </Tooltip>,
                      <ApprovalLimitField
                        key={`${u.public_id}-${u.approval_limit_clp}`}
                        name={u.name}
                        value={u.approval_limit_clp}
                        disabled={self || updateUser.isPending}
                        onCommit={(v) => updateUser.mutate({ id: u.public_id, approval_limit_clp: v })}
                      />,
                      <Typography variant="caption" color="text.secondary">
                        {u.last_login_at ? formatDateTime(u.last_login_at) : 'Aún no ingresa'}
                      </Typography>,
                      <Stack direction="row" alignItems="center" justifyContent="flex-end">
                        <Tooltip title={u.initial_password_blocker ?? 'Generar contraseña de inicio (cierra sus sesiones; deberá cambiarla al ingresar)'}>
                          <span>
                            <IconButton
                              aria-label={`Generar contraseña de inicio para ${u.name}`}
                              disabled={Boolean(u.initial_password_blocker) || regenerate.isPending}
                              onClick={() => window.confirm(`¿Generar una contraseña de inicio para ${u.name}? Su contraseña actual dejará de funcionar y se cerrarán sus sesiones.`) && regenerate.mutate({ id: u.public_id, name: u.name, email: u.email })}
                            >
                              <KeyOutlined fontSize="small" />
                            </IconButton>
                          </span>
                        </Tooltip>
                        <Tooltip title={self ? 'No puedes desactivarte a ti mismo' : u.is_active ? 'Quitar acceso' : 'Devolver acceso'}>
                          <span>
                            <Switch
                              checked={u.is_active}
                              disabled={self || updateUser.isPending}
                              onChange={(e) => updateUser.mutate({ id: u.public_id, is_active: e.target.checked })}
                              inputProps={{ 'aria-label': `Acceso de ${u.name}` }}
                            />
                          </span>
                        </Tooltip>
                        <Tooltip title={self ? 'No puedes quitarte de la empresa' : 'Quitar de la empresa'}>
                          <span>
                            <IconButton
                              aria-label={`Quitar a ${u.name} de la empresa`}
                              disabled={self || removeUser.isPending}
                              onClick={() =>
                                window.confirm(`¿Quitar a ${u.name} de la empresa? Pierde el acceso y sus tokens API de esta empresa. Si no tiene acceso a otra empresa, su cuenta se elimina; su nombre se conserva en el historial.`) &&
                                removeUser.mutate({ id: u.public_id, name: u.name })
                              }
                            >
                              <PersonRemoveOutlined fontSize="small" />
                            </IconButton>
                          </span>
                        </Tooltip>
                      </Stack>,
                    ],
                  }
                })}
              />
            )}
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
              El interruptor suspende el acceso sin quitar a la persona. El botón de quitar la saca de la empresa; si no tiene otra empresa, su cuenta se elimina. En ambos casos el historial conserva su nombre y la empresa siempre mantiene al menos un administrador activo.
            </Typography>
          </>
        )}
      </Card>

      {c.can_manage_tokens && <ApiTokensCard onToast={setToast} />}

      <FormDialog
        open={inviteOpen}
        title="Invitar persona"
        onClose={() => setInviteOpen(false)}
        onSubmit={() => sendInvite.mutate()}
        submitLabel={invite.credential === 'password' ? 'Agregar y generar contraseña' : 'Enviar invitación'}
        submitting={sendInvite.isPending}
        canSubmit={invite.name.trim().length >= 2 && emailOk}
        error={inviteError}
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField label="Nombre y apellido" required autoFocus value={invite.name} onChange={(e) => setInvite({ ...invite, name: e.target.value })} inputProps={{ maxLength: 120 }} error={Boolean(fieldError(inviteErrors, 'name'))} helperText={fieldError(inviteErrors, 'name')} />
          <TextField label="Correo" type="email" required value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} error={Boolean(fieldError(inviteErrors, 'email'))} helperText={fieldError(inviteErrors, 'email')} />
          <TextField
            select
            label="Rol"
            value={invite.role_key}
            onChange={(e) => setInvite({ ...invite, role_key: e.target.value })}
            helperText={ROLE_HINT[invite.role_key]}
            sx={{ gridColumn: '1 / -1' }}
          >
            {(roles.data?.data ?? []).map((r) => (
              <MenuItem key={r.key} value={r.key}>
                {r.name}
              </MenuItem>
            ))}
          </TextField>
        </Box>
        <Typography variant="subtitle2" sx={{ mt: 2.5 }}>
          Acceso inicial
        </Typography>
        <RadioGroup value={invite.credential} onChange={(e) => setInvite({ ...invite, credential: e.target.value as 'link' | 'password' })}>
          <FormControlLabel value="password" control={<Radio />} label="Generar contraseña de inicio (se muestra una vez; deberá cambiarla al ingresar)" />
          <FormControlLabel value="link" control={<Radio />} label="Enviar enlace por correo para que cree su contraseña" />
        </RadioGroup>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          Si la persona ya tiene cuenta en TIARE, obtendrá acceso a esta empresa de inmediato y conservará su contraseña actual.
        </Typography>
      </FormDialog>

      <InitialPasswordDialog value={issued?.value ?? null} name={issued?.name} email={issued?.email} onClose={() => setIssued(null)} />

      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
