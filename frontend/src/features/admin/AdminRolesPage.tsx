import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Checkbox from '@mui/material/Checkbox'
import Dialog from '@mui/material/Dialog'
import DialogActions from '@mui/material/DialogActions'
import DialogContent from '@mui/material/DialogContent'
import DialogTitle from '@mui/material/DialogTitle'
import IconButton from '@mui/material/IconButton'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import AddOutlined from '@mui/icons-material/AddOutlined'
import DeleteOutline from '@mui/icons-material/DeleteOutline'
import LockOutlined from '@mui/icons-material/LockOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { tokens } from '../../app/theme'
import { FormDialog } from '../../components/FormDialog'
import { ErrorState, ListSkeleton } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { accountsApi, type AdminRoleDto, type PermissionDto } from '../../services/company'

const SCOPE_LABEL = { platform: 'Plataforma (personal TIARE)', tenant: 'Empresa cliente' }
const errText = (e: unknown, fallback: string) => (e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : fallback)

/** Mantenedor de roles: permisos por rol, roles propios y restauración de los roles del sistema. */
export function AdminRolesPage() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin-roles'], queryFn: accountsApi.roles })
  const [toast, setToast] = useState<string | null>(null)
  const [editing, setEditing] = useState<AdminRoleDto | 'new' | null>(null)
  const [form, setForm] = useState<{ name: string; description: string; scope: 'platform' | 'tenant'; permissions: string[] }>({ name: '', description: '', scope: 'tenant', permissions: [] })
  const [error, setError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState<AdminRoleDto | null>(null)
  const [replacement, setReplacement] = useState('')
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const catalog = q.data?.meta.permissions

  const groups = useMemo(() => {
    const g = new Map<string, PermissionDto[]>()
    for (const p of catalog ?? []) {
      if (form.scope === 'tenant' && p.platform_only) continue
      g.set(p.group, [...(g.get(p.group) ?? []), p])
    }
    return [...g.entries()]
  }, [catalog, form.scope])

  const refresh = () => void qc.invalidateQueries({ queryKey: ['admin-roles'] })
  const save = useMutation({
    mutationFn: () =>
      editing === 'new'
        ? accountsApi.createRole({ name: form.name.trim(), scope: form.scope, description: form.description.trim() || null, permissions: form.permissions })
        : accountsApi.updateRole((editing as AdminRoleDto).key, { name: form.name.trim(), description: form.description.trim() || null, permissions: form.permissions }),
    onSuccess: () => {
      setToast(editing === 'new' ? 'Rol creado.' : 'Rol actualizado. Los permisos cambian de inmediato para quienes lo tienen.')
      setEditing(null)
      refresh()
    },
    onError: (e) => setError(errText(e, 'No fue posible guardar el rol.')),
  })
  const reset = useMutation({
    mutationFn: (key: string) => accountsApi.resetRole(key),
    onSuccess: () => {
      setToast('Rol restaurado a sus permisos de fábrica.')
      setEditing(null)
      refresh()
    },
    onError: (e) => setError(errText(e, 'No fue posible restaurar el rol.')),
  })
  const remove = useMutation({
    mutationFn: ({ key, replacementKey }: { key: string; replacementKey: string | null }) => accountsApi.deleteRole(key, replacementKey),
    onSuccess: (res) => {
      setToast(res.data.moved_accounts > 0 ? `Rol eliminado. ${res.data.moved_accounts} ${res.data.moved_accounts === 1 ? 'cuenta se movió' : 'cuentas se movieron'} al rol elegido.` : 'Rol eliminado.')
      setDeleting(null)
      setEditing(null)
      refresh()
    },
    onError: (e) => setDeleteError(errText(e, 'No fue posible eliminar el rol.')),
  })
  const askDelete = (r: AdminRoleDto) => {
    setDeleteError(null)
    setReplacement('')
    setDeleting(r)
  }

  const openRole = (r: AdminRoleDto | 'new') => {
    setError(null)
    setEditing(r)
    setForm(r === 'new' ? { name: '', description: '', scope: 'tenant', permissions: [] } : { name: r.name, description: r.description ?? '', scope: r.scope, permissions: r.permissions })
  }
  const toggle = (k: string) => setForm((f) => ({ ...f, permissions: f.permissions.includes(k) ? f.permissions.filter((x) => x !== k) : [...f.permissions, k] }))
  const role = editing && editing !== 'new' ? editing : null

  if (q.isLoading) return <ListSkeleton />
  if (q.error || !q.data) return <ErrorState error={q.error} what="los roles" />

  const byScope = (s: 'platform' | 'tenant') => q.data.data.filter((r) => r.scope === s)

  return (
    <>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1} sx={{ mb: 2 }}>
        <Typography color="text.secondary">Un rol agrupa permisos. En empresas clientes, el plan contratado limita además los módulos disponibles.</Typography>
        <Button variant="contained" startIcon={<AddOutlined />} onClick={() => openRole('new')}>
          Nuevo rol
        </Button>
      </Stack>

      {(['tenant', 'platform'] as const).map((s) => (
        <Box key={s} sx={{ mb: 3 }}>
          <Typography variant="h3" component="h2" sx={{ mb: 1.5 }}>
            {SCOPE_LABEL[s]}
          </Typography>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr', lg: '1fr 1fr 1fr' } }}>
            {byScope(s).map((r) => (
              <Card key={r.key} sx={{ p: 2, cursor: 'pointer', '&:hover': { borderColor: tokens.primary } }} onClick={() => openRole(r)} role="button" tabIndex={0} aria-label={`Editar rol ${r.name}`} onKeyDown={(e) => e.key === 'Enter' && openRole(r)}>
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
                  <Typography sx={{ fontWeight: 700 }}>{r.name}</Typography>
                  {r.deletable ? (
                    <IconButton
                      size="small"
                      aria-label={`Eliminar rol ${r.name}`}
                      onClick={(e) => {
                        e.stopPropagation()
                        askDelete(r)
                      }}
                      sx={{ mt: -0.5, mr: -0.5 }}
                    >
                      <DeleteOutline fontSize="small" />
                    </IconButton>
                  ) : (
                    <LockOutlined fontSize="small" color="disabled" titleAccess="Rol protegido: no se elimina" />
                  )}
                </Stack>
                <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.5, minHeight: 18 }}>
                  {r.description ?? `${r.permissions.length} permisos`}
                </Typography>
                <Stack direction="row" spacing={0.75} sx={{ mt: 1, flexWrap: 'wrap', rowGap: 0.75 }}>
                  <Chip size="small" label={`${r.users_count} ${r.users_count === 1 ? 'cuenta' : 'cuentas'}`} />
                  <Chip size="small" variant="outlined" label={r.is_system ? 'Del sistema' : 'Propio'} />
                  {r.customized && r.is_system && <Chip size="small" color="warning" variant="outlined" label="Modificado" />}
                </Stack>
              </Card>
            ))}
          </Box>
        </Box>
      ))}

      <FormDialog
        open={Boolean(editing)}
        title={editing === 'new' ? 'Nuevo rol' : `Rol: ${role?.name ?? ''}`}
        onClose={() => setEditing(null)}
        onSubmit={() => save.mutate()}
        submitLabel={editing === 'new' ? 'Crear rol' : 'Guardar permisos'}
        submitting={save.isPending}
        canSubmit={!role?.locked && form.name.trim().length >= 3 && form.permissions.length > 0}
        error={error}
      >
        {role?.locked && <Alert severity="info" sx={{ mb: 2 }}>Rol protegido: los administradores de plataforma conservan todos los permisos para no perder el acceso a la administración.</Alert>}
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField label="Nombre" required value={form.name} disabled={role?.locked} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 120 }} />
          <TextField select label="Alcance" value={form.scope} disabled={editing !== 'new'} onChange={(e) => setForm({ ...form, scope: e.target.value as 'platform' | 'tenant', permissions: [] })}>
            <MenuItem value="tenant">{SCOPE_LABEL.tenant}</MenuItem>
            <MenuItem value="platform">{SCOPE_LABEL.platform}</MenuItem>
          </TextField>
          <TextField label="Descripción" value={form.description} disabled={role?.locked} onChange={(e) => setForm({ ...form, description: e.target.value })} inputProps={{ maxLength: 255 }} sx={{ gridColumn: '1 / -1' }} />
        </Box>
        <Typography variant="subtitle2" sx={{ mt: 2 }}>
          Permisos ({form.permissions.length})
        </Typography>
        {groups.map(([group, perms]) => (
          <Box key={group} sx={{ mt: 1.5 }}>
            <Typography variant="overline" color="text.secondary">
              {group}
            </Typography>
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
              {perms.map((p) => (
                <FormControlLabel
                  key={p.key}
                  control={<Checkbox size="small" checked={form.permissions.includes(p.key)} disabled={role?.locked} onChange={() => toggle(p.key)} />}
                  label={
                    <Box>
                      <Typography variant="body2">{p.description ?? p.key}</Typography>
                      <Typography variant="caption" color="text.secondary" className="code">
                        {p.key}
                      </Typography>
                    </Box>
                  }
                  sx={{ alignItems: 'flex-start', my: 0.25, '& .MuiCheckbox-root': { pt: 0.5 } }}
                />
              ))}
            </Box>
          </Box>
        ))}
        {role && !role.locked && (
          <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
            {role.is_system && role.customized && (
              <Button disabled={reset.isPending} onClick={() => window.confirm(`¿Restaurar "${role.name}" a sus permisos de fábrica?`) && reset.mutate(role.key)}>
                Restaurar permisos de fábrica
              </Button>
            )}
            {role.deletable && (
              <Button color="error" startIcon={<DeleteOutline />} onClick={() => askDelete(role)}>
                Eliminar rol
              </Button>
            )}
          </Stack>
        )}
      </FormDialog>
      <Dialog open={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: '24px' } }}>
        <DialogTitle sx={{ fontWeight: 700 }}>¿Eliminar el rol {deleting?.name}?</DialogTitle>
        <DialogContent>
          {deleting && deleting.users_count > 0 ? (
            <>
              <Typography variant="body2" sx={{ mb: 2 }}>
                Lo {deleting.users_count === 1 ? 'usa 1 cuenta' : `usan ${deleting.users_count} cuentas`}. Elige a qué rol moverlas: sus permisos cambian de inmediato.
              </Typography>
              <TextField select fullWidth label="Mover las cuentas a" value={replacement} onChange={(e) => setReplacement(e.target.value)}>
                {q.data.data
                  .filter((r) => r.scope === deleting.scope && r.key !== deleting.key && !(r.scope === 'tenant' && r.locked))
                  .map((r) => (
                    <MenuItem key={r.key} value={r.key}>
                      {r.name}
                    </MenuItem>
                  ))}
              </TextField>
            </>
          ) : (
            <Typography variant="body2">Ninguna cuenta usa este rol.</Typography>
          )}
          <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
            {deleting?.is_system ? 'Es un rol del sistema: queda eliminado y no se vuelve a crear al actualizar la plataforma.' : 'Esta acción no se puede deshacer.'}
          </Typography>
          {deleteError && (
            <Alert severity="warning" sx={{ mt: 2 }}>
              {deleteError}
            </Alert>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={() => setDeleting(null)}>Cancelar</Button>
          <Button
            variant="contained"
            color="error"
            disabled={remove.isPending || (Boolean(deleting?.users_count) && !replacement)}
            onClick={() => deleting && remove.mutate({ key: deleting.key, replacementKey: replacement || null })}
          >
            {remove.isPending ? 'Eliminando…' : 'Eliminar rol'}
          </Button>
        </DialogActions>
      </Dialog>
      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
