import Alert from '@mui/material/Alert'
import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Divider from '@mui/material/Divider'
import Drawer from '@mui/material/Drawer'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Radio from '@mui/material/Radio'
import RadioGroup from '@mui/material/RadioGroup'
import FormControlLabel from '@mui/material/FormControlLabel'
import Switch from '@mui/material/Switch'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import Close from '@mui/icons-material/Close'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import PersonAddAlt1Outlined from '@mui/icons-material/PersonAddAlt1Outlined'
import Search from '@mui/icons-material/Search'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { DeleteRecordButton } from '../../components/DeleteRecordButton'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { accountsApi, type AdminUserDto } from '../../services/company'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDateTime } from '../../utils/format'
import { InitialPasswordDialog } from './InitialPasswordDialog'

const initials = (n: string) =>
  n
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')
const emailOk = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())
const errText = (e: unknown, fallback: string) => (e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : fallback)

/** Mantenedor de cuentas: alta, edición, activación, rol de plataforma y acceso a empresas. */
export function AdminAccountsPage() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [role, setRole] = useState('')
  const [tenant, setTenant] = useState('')
  const [page, setPage] = useState(1)
  const [toast, setToast] = useState<string | null>(null)
  const [selected, setSelected] = useState<AdminUserDto | null>(null)

  const refs = useQuery({ queryKey: ['account-refs'], queryFn: accountsApi.references, staleTime: 60_000 })
  const list = useQuery({ queryKey: ['admin-users', q, status, role, tenant, page], queryFn: () => accountsApi.users({ q: q.trim() || undefined, status: status || undefined, role: role || undefined, tenant: tenant || undefined, page }) })
  const r = refs.data?.data
  const selfId = list.data?.meta.self_public_id

  // ---- Alta
  const empty = { name: '', email: '', platform_role_key: '', tenant_public_id: '', role_key: 'tenant_admin', credential: 'password' as 'link' | 'password' }
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(empty)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  // Contraseña de inicio recién generada: solo vive en memoria mientras el diálogo está abierto.
  const [issued, setIssued] = useState<{ value: string; name: string; email: string } | null>(null)
  const create = useMutation({
    mutationFn: () =>
      accountsApi.createUser({
        name: form.name.trim(),
        email: form.email.trim(),
        credential: form.credential,
        platform_role_key: form.platform_role_key || null,
        memberships: form.tenant_public_id ? [{ tenant_public_id: form.tenant_public_id, role_key: form.role_key }] : [],
      }),
    onSuccess: (res) => {
      setOpen(false)
      setForm(empty)
      setToast(res.data.message)
      if (res.data.initial_password) setIssued({ value: res.data.initial_password, name: res.data.name, email: res.data.email })
      void qc.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible crear la cuenta.')
    },
  })

  const rows = list.data?.data ?? []
  const pg = list.data?.meta.pagination

  return (
    <>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1} sx={{ mb: 2 }}>
        <Typography color="text.secondary">Cuentas de toda la plataforma: personal TIARE y usuarios de empresas clientes.</Typography>
        <Button
          variant="contained"
          startIcon={<PersonAddAlt1Outlined />}
          onClick={() => {
            setErrors({})
            setError(null)
            setForm(empty)
            setOpen(true)
          }}
        >
          Nueva cuenta
        </Button>
      </Stack>

      <Card sx={{ p: 2, mb: 2 }}>
        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: '2fr 1fr 1fr 1fr' } }}>
          <TextField
            size="small"
            placeholder="Buscar por nombre o correo"
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setPage(1)
            }}
            InputProps={{ startAdornment: <InputAdornment position="start"><Search fontSize="small" /></InputAdornment> }}
            inputProps={{ 'aria-label': 'Buscar cuenta' }}
          />
          <TextField select size="small" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <MenuItem value="">Todas</MenuItem>
            <MenuItem value="active">Activas</MenuItem>
            <MenuItem value="inactive">Desactivadas</MenuItem>
          </TextField>
          <TextField select size="small" label="Rol" value={role} onChange={(e) => { setRole(e.target.value); setPage(1) }}>
            <MenuItem value="">Todos</MenuItem>
            {[...(r?.platform_roles ?? []), ...(r?.tenant_roles ?? [])].map((x) => (
              <MenuItem key={x.key} value={x.key}>
                {x.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField select size="small" label="Empresa" value={tenant} onChange={(e) => { setTenant(e.target.value); setPage(1) }}>
            <MenuItem value="">Todas</MenuItem>
            {(r?.tenants ?? []).map((t) => (
              <MenuItem key={t.public_id} value={t.public_id}>
                {t.name}
              </MenuItem>
            ))}
          </TextField>
        </Box>
      </Card>

      {list.isLoading && <ListSkeleton />}
      {list.error && <ErrorState error={list.error} what="las cuentas" />}
      {list.data && rows.length === 0 && <EmptyState title="No hay cuentas con esos filtros." />}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Persona' }, { label: 'Rol de plataforma', hideOnMobile: true }, { label: 'Empresas' }, { label: 'Último ingreso', hideOnMobile: true }, { label: 'Estado', align: 'right' }]}
          rows={rows.map((u) => ({
            key: u.public_id,
            label: `Ver cuenta de ${u.name}`,
            onClick: () => setSelected(u),
            cells: [
              <Stack direction="row" spacing={1.5} alignItems="center">
                <Avatar sx={{ width: 34, height: 34, fontSize: '0.8rem', bgcolor: u.is_active ? 'primary.main' : tokens.surfaceHigh, color: u.is_active ? '#fff' : tokens.onSurfaceVariant }}>{initials(u.name)}</Avatar>
                <Box sx={{ minWidth: 0 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {u.name} {u.public_id === selfId && <Chip size="small" label="Tú" sx={{ ml: 0.5, height: 20 }} />}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {u.email}
                  </Typography>
                </Box>
              </Stack>,
              u.platform_role ? <Chip size="small" label={u.platform_role.name} sx={{ bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer }} /> : <Typography variant="caption" color="text.secondary">—</Typography>,
              <Typography variant="body2">{u.memberships.length === 0 ? '—' : u.memberships.map((m) => `${m.tenant.name} (${m.role.name ?? '—'})`).join(', ')}</Typography>,
              <Typography variant="caption" color="text.secondary">
                {u.last_login_at ? formatDateTime(u.last_login_at) : 'Aún no ingresa'}
              </Typography>,
              <Stack direction="row" spacing={0.5} justifyContent="flex-end" sx={{ flexWrap: 'wrap', rowGap: 0.5 }}>
                {u.must_change_password && <Chip size="small" color="warning" variant="outlined" label="Contraseña de inicio" />}
                <Chip size="small" label={u.is_active ? 'Activa' : 'Desactivada'} color={u.is_active ? 'success' : 'default'} variant="outlined" />
              </Stack>,
            ],
          }))}
        />
      )}
      {pg && pg.last_page > 1 && <Pager page={pg.page} lastPage={pg.last_page} onChange={setPage} />}

      <FormDialog open={open} title="Nueva cuenta" onClose={() => setOpen(false)} onSubmit={() => create.mutate()} submitLabel={form.credential === 'password' ? 'Crear cuenta y generar contraseña' : 'Crear cuenta y enviar enlace'} submitting={create.isPending} canSubmit={form.name.trim().length >= 2 && emailOk(form.email) && Boolean(form.platform_role_key || form.tenant_public_id)} error={error}>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField label="Nombre y apellido" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 120 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name')} />
          <TextField label="Correo" type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={Boolean(fieldError(errors, 'email'))} helperText={fieldError(errors, 'email')} />
          <TextField select label="Rol de plataforma (personal TIARE)" value={form.platform_role_key} onChange={(e) => setForm({ ...form, platform_role_key: e.target.value })} helperText={fieldError(errors, 'platform_role_key') ?? 'Déjalo vacío para usuarios de empresas clientes'} error={Boolean(fieldError(errors, 'platform_role_key'))} sx={{ gridColumn: '1 / -1' }}>
            <MenuItem value="">Sin rol de plataforma</MenuItem>
            {(r?.platform_roles ?? []).map((x) => (
              <MenuItem key={x.key} value={x.key}>
                {x.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField select label="Empresa" value={form.tenant_public_id} onChange={(e) => setForm({ ...form, tenant_public_id: e.target.value })} error={Boolean(fieldError(errors, 'memberships') || fieldError(errors, 'tenant_public_id'))} helperText={fieldError(errors, 'memberships') ?? fieldError(errors, 'tenant_public_id')}>
            <MenuItem value="">Sin empresa</MenuItem>
            {(r?.tenants ?? []).map((t) => (
              <MenuItem key={t.public_id} value={t.public_id}>
                {t.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField select label="Rol en la empresa" value={form.role_key} disabled={!form.tenant_public_id} onChange={(e) => setForm({ ...form, role_key: e.target.value })}>
            {(r?.tenant_roles ?? []).map((x) => (
              <MenuItem key={x.key} value={x.key}>
                {x.name}
              </MenuItem>
            ))}
          </TextField>
        </Box>
        <Typography variant="subtitle2" sx={{ mt: 2.5 }}>
          Acceso inicial
        </Typography>
        <RadioGroup value={form.credential} onChange={(e) => setForm({ ...form, credential: e.target.value as 'link' | 'password' })}>
          <FormControlLabel value="password" control={<Radio />} label="Generar contraseña de inicio (se muestra una vez; deberá cambiarla al ingresar)" />
          <FormControlLabel value="link" control={<Radio />} label="Enviar enlace por correo para que la persona cree su contraseña" />
        </RadioGroup>
      </FormDialog>

      <InitialPasswordDialog value={issued?.value ?? null} name={issued?.name} email={issued?.email} onClose={() => setIssued(null)} />

      <AccountDrawer user={selected} selfId={selfId} refs={r} onClose={() => setSelected(null)} onChanged={(u) => { setSelected(u); void qc.invalidateQueries({ queryKey: ['admin-users'] }) }} onDeleted={(name) => { setSelected(null); setToast(`Cuenta de ${name} eliminada. Su nombre se conserva en el historial.`); void qc.invalidateQueries({ queryKey: ['admin-users'] }) }} onToast={setToast} />
      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

function AccountDrawer({
  user,
  selfId,
  refs,
  onClose,
  onChanged,
  onDeleted,
  onToast,
}: {
  user: AdminUserDto | null
  selfId?: string
  refs?: { platform_roles: { key: string; name: string }[]; tenant_roles: { key: string; name: string }[]; tenants: { public_id: string; name: string }[] }
  onClose: () => void
  onChanged: (u: AdminUserDto) => void
  onDeleted: (name: string) => void
  onToast: (m: string) => void
}) {
  const [form, setForm] = useState({ name: '', email: '' })
  const [newTenant, setNewTenant] = useState({ tenant: '', role: '' })
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (user) setForm({ name: user.name, email: user.email })
    setError(null)
  }, [user])

  const done = (msg: string) => (r: { data: AdminUserDto }) => {
    setError(null)
    onChanged(r.data)
    onToast(msg)
  }
  const fail = (e: unknown) => setError(errText(e, 'No fue posible guardar el cambio.'))
  const update = useMutation({ mutationFn: (body: Parameters<typeof accountsApi.updateUser>[1]) => accountsApi.updateUser(user!.public_id, body), onSuccess: done('Cuenta actualizada.'), onError: fail })
  const saveM = useMutation({ mutationFn: (v: { tenant: string; role: string; active?: boolean }) => accountsApi.saveMembership(user!.public_id, v.tenant, { role_key: v.role, is_active: v.active ?? true }), onSuccess: done('Acceso a la empresa actualizado.'), onError: fail })
  const removeM = useMutation({ mutationFn: (tenant: string) => accountsApi.removeMembership(user!.public_id, tenant), onSuccess: done('Acceso a la empresa eliminado.'), onError: fail })
  const link = useMutation({ mutationFn: () => accountsApi.passwordLink(user!.public_id), onSuccess: (r) => onToast(r.data.message), onError: fail })
  const [issued, setIssued] = useState<string | null>(null)
  const initial = useMutation({
    mutationFn: () => accountsApi.initialPassword(user!.public_id),
    onSuccess: (r) => {
      setError(null)
      setIssued(r.data.initial_password)
      onChanged({ ...user!, must_change_password: true })
      onToast(r.data.message)
    },
    onError: fail,
  })

  const self = user?.public_id === selfId
  const busy = update.isPending || saveM.isPending || removeM.isPending
  const available = (refs?.tenants ?? []).filter((t) => !user?.memberships.some((m) => m.tenant.public_id === t.public_id))
  const dirty = user && (form.name.trim() !== user.name || form.email.trim() !== user.email)

  return (
    <Drawer anchor="right" open={Boolean(user)} onClose={onClose} PaperProps={{ sx: { width: { xs: '100%', sm: 560 }, p: 3 } }}>
      {user && (
        <Stack spacing={2.5}>
          <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
            <Box>
              <Typography variant="h3" component="h2">
                {user.name}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Creada {formatDateTime(user.created_at)} · último ingreso {user.last_login_at ? formatDateTime(user.last_login_at) : 'nunca'}
              </Typography>
            </Box>
            <IconButton aria-label="Cerrar" onClick={onClose}>
              <Close />
            </IconButton>
          </Stack>
          {error && (
            <Alert severity="error" onClose={() => setError(null)}>
              {error}
            </Alert>
          )}

          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Datos de la cuenta
            </Typography>
            <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
              <TextField size="small" label="Nombre" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              <TextField size="small" label="Correo" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </Box>
            <Stack direction="row" spacing={1} sx={{ mt: 1.5, flexWrap: 'wrap', rowGap: 1 }}>
              <Button size="small" variant="contained" disabled={!dirty || busy || form.name.trim().length < 2 || !emailOk(form.email)} onClick={() => update.mutate({ name: form.name.trim(), email: form.email.trim() })}>
                Guardar datos
              </Button>
              <Button size="small" disabled={link.isPending || !user.is_active} onClick={() => link.mutate()}>
                Enviar enlace para nueva contraseña
              </Button>
              <Tooltip title={self ? 'Para tu propia cuenta usa "Mi cuenta"' : 'Reemplaza la contraseña actual, cierra sus sesiones y obliga a cambiarla al ingresar'}>
                <span>
                  <Button
                    size="small"
                    color="warning"
                    disabled={self || initial.isPending || !user.is_active}
                    onClick={() => window.confirm(`¿Generar una contraseña de inicio para ${user.name}? Su contraseña actual dejará de funcionar y se cerrarán sus sesiones.`) && initial.mutate()}
                  >
                    Generar contraseña de inicio
                  </Button>
                </span>
              </Tooltip>
            </Stack>
            {user.must_change_password && (
              <Alert severity="warning" sx={{ mt: 1.5 }}>
                Tiene una contraseña de inicio pendiente: deberá cambiarla en su próximo ingreso.
              </Alert>
            )}
          </Box>

          <Stack direction="row" alignItems="center" justifyContent="space-between">
            <Box>
              <Typography variant="subtitle2">Cuenta activa</Typography>
              <Typography variant="caption" color="text.secondary">
                Al desactivarla se cierran sus sesiones y se revocan sus tokens API.
              </Typography>
            </Box>
            <Tooltip title={self ? 'No puedes desactivar tu propia cuenta' : ''}>
              <span>
                <Switch checked={user.is_active} disabled={self || busy} onChange={(e) => update.mutate({ is_active: e.target.checked })} inputProps={{ 'aria-label': 'Cuenta activa' }} />
              </span>
            </Tooltip>
          </Stack>

          <TextField
            select
            size="small"
            label="Rol de plataforma (personal TIARE)"
            value={user.platform_role?.key ?? ''}
            disabled={self || busy}
            helperText={self ? 'No puedes cambiar tu propio rol.' : 'Da acceso a la administración y a la operación de TIARE.'}
            onChange={(e) => update.mutate({ platform_role_key: e.target.value || null })}
          >
            <MenuItem value="">Sin rol de plataforma</MenuItem>
            {(refs?.platform_roles ?? []).map((x) => (
              <MenuItem key={x.key} value={x.key}>
                {x.name}
              </MenuItem>
            ))}
          </TextField>

          <Divider />
          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Acceso a empresas ({user.memberships.length})
            </Typography>
            <Stack spacing={1}>
              {user.memberships.map((m) => (
                <Stack key={m.tenant.public_id} direction="row" spacing={1} alignItems="center">
                  <Typography variant="body2" sx={{ flex: 1, fontWeight: 600 }}>
                    {m.tenant.name}
                  </Typography>
                  <TextField select size="small" value={m.role.key ?? ''} disabled={busy} onChange={(e) => saveM.mutate({ tenant: m.tenant.public_id, role: e.target.value, active: m.is_active })} sx={{ minWidth: 190 }} inputProps={{ 'aria-label': `Rol en ${m.tenant.name}` }}>
                    {(refs?.tenant_roles ?? []).map((x) => (
                      <MenuItem key={x.key} value={x.key}>
                        {x.name}
                      </MenuItem>
                    ))}
                  </TextField>
                  <Tooltip title={m.is_active ? 'Acceso activo' : 'Acceso suspendido'}>
                    <Switch size="small" checked={m.is_active} disabled={busy} onChange={(e) => saveM.mutate({ tenant: m.tenant.public_id, role: m.role.key ?? 'tenant_read_only', active: e.target.checked })} inputProps={{ 'aria-label': `Acceso a ${m.tenant.name}` }} />
                  </Tooltip>
                  <Tooltip title="Quitar acceso a la empresa">
                    <IconButton size="small" aria-label={`Quitar acceso a ${m.tenant.name}`} disabled={busy} onClick={() => window.confirm(`¿Quitar el acceso de ${user.name} a ${m.tenant.name}? Sus tokens API de esa empresa se revocan.`) && removeM.mutate(m.tenant.public_id)}>
                      <DeleteOutline fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </Stack>
              ))}
            </Stack>
            {available.length > 0 && (
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mt: 1.5 }}>
                <TextField select size="small" label="Agregar empresa" value={newTenant.tenant} onChange={(e) => setNewTenant({ ...newTenant, tenant: e.target.value })} sx={{ flex: 1 }}>
                  {available.map((t) => (
                    <MenuItem key={t.public_id} value={t.public_id}>
                      {t.name}
                    </MenuItem>
                  ))}
                </TextField>
                <TextField select size="small" label="Rol" value={newTenant.role} onChange={(e) => setNewTenant({ ...newTenant, role: e.target.value })} sx={{ minWidth: 180 }}>
                  {(refs?.tenant_roles ?? []).map((x) => (
                    <MenuItem key={x.key} value={x.key}>
                      {x.name}
                    </MenuItem>
                  ))}
                </TextField>
                <Button
                  variant="outlined"
                  disabled={!newTenant.tenant || !newTenant.role || busy}
                  onClick={() => {
                    saveM.mutate({ tenant: newTenant.tenant, role: newTenant.role })
                    setNewTenant({ tenant: '', role: '' })
                  }}
                >
                  Agregar
                </Button>
              </Stack>
            )}
          </Box>

          <Divider />
          <Box>
            <Typography variant="subtitle2">Eliminar cuenta</Typography>
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 1 }}>
              La persona pierde todo acceso y su correo queda libre. Su nombre se conserva en aprobaciones, órdenes y auditoría.
            </Typography>
            {self ? (
              <Typography variant="body2" color="text.secondary">
                No puedes eliminar tu propia cuenta.
              </Typography>
            ) : (
              <DeleteRecordButton
                noun="cuenta"
                name={`${user.name} (${user.email})`}
                detail="Se quitan sus empresas, se cierran sus sesiones y se revocan sus tokens API. No se puede eliminar al último administrador de TIARE ni de una empresa."
                onDelete={() => accountsApi.deleteUser(user.public_id)}
                onDeleted={() => onDeleted(user.name)}
              />
            )}
          </Box>
        </Stack>
      )}
      <InitialPasswordDialog value={issued} name={user?.name} email={user?.email} onClose={() => setIssued(null)} />
    </Drawer>
  )
}
