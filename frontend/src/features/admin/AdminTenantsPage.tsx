import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Drawer from '@mui/material/Drawer'
import IconButton from '@mui/material/IconButton'
import InputAdornment from '@mui/material/InputAdornment'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import AddBusinessOutlined from '@mui/icons-material/AddBusinessOutlined'
import Close from '@mui/icons-material/Close'
import Search from '@mui/icons-material/Search'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { ApiError } from '../../services/apiClient'
import { adminApi } from '../../services/company'
import type { AdminTenantDto } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDate, formatDateTime, todayInChile } from '../../utils/format'
import { formatRut, isValidRut } from '../../utils/rut'

const EMPTY = { name: '', legal_name: '', rut: '', plan_key: 'start', starts_on: '', ends_on: '', admin_name: '', admin_email: '' }
const emailOk = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())

function PlanChip({ t }: { t: AdminTenantDto }) {
  return t.subscription?.plan ? (
    <Chip size="small" label={t.subscription.plan.name} sx={{ bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer, fontWeight: 600 }} />
  ) : (
    <Chip size="small" color="error" variant="outlined" label="Sin plan vigente" />
  )
}

/** Administración TIARE: empresas clientes, plan contratado y su administrador. Solo rol de plataforma. */
export function AdminTenantsPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [toast, setToast] = useState<string | null>(null)
  const [selected, setSelected] = useState<string | null>(null)

  const allowed = can('platform.tenants.manage')
  const plans = useQuery({ queryKey: ['admin-plans'], queryFn: adminApi.plans, staleTime: Infinity, enabled: allowed })
  const list = useQuery({ queryKey: ['admin-tenants', q, status, page], queryFn: () => adminApi.tenants({ q: q.trim() || undefined, status: status || undefined, page }), enabled: allowed })

  // ---- Alta de empresa
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const create = useMutation({
    mutationFn: () =>
      adminApi.create({
        name: form.name.trim(),
        legal_name: form.legal_name.trim() || null,
        rut: form.rut.trim() || null,
        plan_key: form.plan_key,
        starts_on: form.starts_on || null,
        ends_on: form.ends_on || null,
        admin_name: form.admin_name.trim(),
        admin_email: form.admin_email.trim(),
      }),
    onSuccess: (r) => {
      setOpen(false)
      setForm(EMPTY)
      setToast(r.data.message)
      void qc.invalidateQueries({ queryKey: ['admin-tenants'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setError(e instanceof ApiError ? e.message : 'No fue posible crear la empresa.')
    },
  })
  const rutInvalid = Boolean(form.rut && !isValidRut(form.rut))

  if (!allowed) return <Navigate to="/" replace />

  const rows = list.data?.data ?? []
  const pg = list.data?.meta.pagination

  return (
    <>
      <PageHeader
        title="Empresas clientes"
        subtitle="Plan contratado y administrador de cada empresa. Cada empresa solo ve su propia información."
        actions={
          <Button
            variant="contained"
            startIcon={<AddBusinessOutlined />}
            onClick={() => {
              setErrors({})
              setError(null)
              setForm({ ...EMPTY, starts_on: todayInChile() })
              setOpen(true)
            }}
          >
            Nueva empresa
          </Button>
        }
      />

      <Card sx={{ p: 2, mb: 2 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField
            size="small"
            placeholder="Buscar por nombre o RUT"
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setPage(1)
            }}
            InputProps={{ startAdornment: <InputAdornment position="start"><Search fontSize="small" /></InputAdornment> }}
            sx={{ flex: 1 }}
            inputProps={{ 'aria-label': 'Buscar empresa' }}
          />
          <TextField select size="small" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} sx={{ minWidth: 180 }}>
            <MenuItem value="">Todas</MenuItem>
            <MenuItem value="ACTIVE">Activas</MenuItem>
            <MenuItem value="SUSPENDED">Suspendidas</MenuItem>
          </TextField>
        </Stack>
      </Card>

      {list.isLoading && <ListSkeleton />}
      {list.error && <ErrorState error={list.error} what="las empresas" />}
      {list.data && rows.length === 0 && <EmptyState title="No hay empresas con esos filtros." />}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Empresa' }, { label: 'Plan' }, { label: 'Personas', hideOnMobile: true }, { label: 'Vigencia', hideOnMobile: true }, { label: 'Estado', align: 'right' }]}
          rows={rows.map((t) => ({
            key: t.public_id,
            label: `Ver ${t.name}`,
            onClick: () => setSelected(t.public_id),
            cells: [
              <Box>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {t.name} {t.is_demo && <Chip size="small" label="Demo" color="warning" variant="outlined" sx={{ ml: 0.5, height: 20 }} />}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {t.rut ?? 'Sin RUT'}
                </Typography>
              </Box>,
              <PlanChip t={t} />,
              <Typography variant="body2">
                {t.seats.used}
                {t.seats.max ? ` / ${t.seats.max}` : ''}
              </Typography>,
              <Typography variant="caption" color="text.secondary">
                {t.subscription ? `${formatDate(t.subscription.starts_on)} → ${t.subscription.ends_on ? formatDate(t.subscription.ends_on) : 'indefinida'}` : '—'}
              </Typography>,
              <Chip size="small" label={t.status === 'ACTIVE' ? 'Activa' : 'Suspendida'} color={t.status === 'ACTIVE' ? 'success' : 'default'} variant="outlined" />,
            ],
          }))}
        />
      )}
      {pg && pg.last_page > 1 && <Pager page={pg.page} lastPage={pg.last_page} onChange={setPage} />}

      <FormDialog open={open} title="Nueva empresa cliente" onClose={() => setOpen(false)} onSubmit={() => create.mutate()} submitLabel="Crear empresa e invitar administrador" submitting={create.isPending} canSubmit={form.name.trim().length >= 2 && !rutInvalid && form.admin_name.trim().length >= 2 && emailOk(form.admin_email)} error={error}>
        <Typography variant="subtitle2" sx={{ mb: 1 }}>
          Empresa
        </Typography>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr 200px' } }}>
          <TextField label="Nombre" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} inputProps={{ maxLength: 160 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name')} />
          <TextField label="Razón social" value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} inputProps={{ maxLength: 200 }} />
          <TextField
            label="RUT"
            value={form.rut}
            onChange={(e) => setForm({ ...form, rut: e.target.value })}
            onBlur={() => form.rut && isValidRut(form.rut) && setForm({ ...form, rut: formatRut(form.rut) })}
            error={rutInvalid || Boolean(fieldError(errors, 'rut'))}
            helperText={rutInvalid ? 'RUT no válido.' : fieldError(errors, 'rut')}
          />
        </Box>
        <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>
          Plan contratado
        </Typography>
        <PlanFields plans={plans.data?.data ?? []} value={form} onChange={(v) => setForm({ ...form, ...v })} errors={errors} />
        <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>
          Administrador de la empresa
        </Typography>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField label="Nombre y apellido" required value={form.admin_name} onChange={(e) => setForm({ ...form, admin_name: e.target.value })} error={Boolean(fieldError(errors, 'admin_name'))} helperText={fieldError(errors, 'admin_name')} />
          <TextField label="Correo" type="email" required value={form.admin_email} onChange={(e) => setForm({ ...form, admin_email: e.target.value })} error={Boolean(fieldError(errors, 'admin_email') || fieldError(errors, 'email'))} helperText={fieldError(errors, 'admin_email') ?? fieldError(errors, 'email')} />
        </Box>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
          La persona recibirá un correo para definir su contraseña. Con el rol Administrador de empresa verá solo esta empresa y su plan, y podrá invitar a su equipo.
        </Typography>
      </FormDialog>

      <TenantDrawer id={selected} onClose={() => setSelected(null)} plans={plans.data?.data ?? []} onToast={setToast} />
      <Snackbar open={Boolean(toast)} autoHideDuration={6000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

type PlanForm = { plan_key: string; starts_on: string; ends_on: string }

function PlanFields({ plans, value, onChange, errors }: { plans: { key: string; name: string; description: string | null; max_users: number | null; modules: { name: string }[] }[]; value: PlanForm; onChange: (v: Partial<PlanForm>) => void; errors: FieldErrors }) {
  const plan = plans.find((p) => p.key === value.plan_key)
  return (
    <>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 170px 170px' } }}>
        <TextField select label="Plan" value={value.plan_key} onChange={(e) => onChange({ plan_key: e.target.value })} error={Boolean(fieldError(errors, 'plan_key'))} helperText={fieldError(errors, 'plan_key')}>
          {plans.map((p) => (
            <MenuItem key={p.key} value={p.key}>
              {p.name} {p.max_users ? `· hasta ${p.max_users} personas` : '· sin límite de personas'}
            </MenuItem>
          ))}
        </TextField>
        <TextField label="Inicio" type="date" value={value.starts_on} onChange={(e) => onChange({ starts_on: e.target.value })} InputLabelProps={{ shrink: true }} />
        <TextField label="Término (opcional)" type="date" value={value.ends_on} onChange={(e) => onChange({ ends_on: e.target.value })} InputLabelProps={{ shrink: true }} error={Boolean(fieldError(errors, 'ends_on'))} helperText={fieldError(errors, 'ends_on')} />
      </Box>
      {plan && (
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
          {plan.description} Módulos: {plan.modules.map((m) => m.name).join(', ')}.
        </Typography>
      )}
    </>
  )
}

function TenantDrawer({ id, onClose, plans, onToast }: { id: string | null; onClose: () => void; plans: Parameters<typeof PlanFields>[0]['plans']; onToast: (m: string) => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['admin-tenant', id], queryFn: () => adminApi.tenant(id!), enabled: Boolean(id) })
  const t = detail.data?.data
  const [planForm, setPlanForm] = useState<PlanForm>({ plan_key: 'start', starts_on: todayInChile(), ends_on: '' })
  const [errors, setErrors] = useState<FieldErrors>({})

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['admin-tenant', id] })
    void qc.invalidateQueries({ queryKey: ['admin-tenants'] })
  }
  const subscribe = useMutation({
    mutationFn: () => adminApi.subscribe(id!, { plan_key: planForm.plan_key, starts_on: planForm.starts_on || null, ends_on: planForm.ends_on || null }),
    onSuccess: () => {
      setErrors({})
      onToast('Plan actualizado. Los permisos de la empresa cambian de inmediato.')
      refresh()
    },
    onError: (e) => setErrors(e instanceof ApiError ? (e.details ?? {}) : {}),
  })
  const setStatus = useMutation({
    mutationFn: (s: 'ACTIVE' | 'SUSPENDED') => adminApi.setStatus(id!, s),
    onSuccess: (r) => {
      onToast(r.data.status === 'ACTIVE' ? 'Empresa reactivada.' : 'Empresa suspendida: sus usuarios ya no pueden entrar a ella.')
      refresh()
    },
    onError: (e) => onToast(e instanceof ApiError ? e.message : 'No fue posible cambiar el estado.'),
  })

  return (
    <Drawer anchor="right" open={Boolean(id)} onClose={onClose} PaperProps={{ sx: { width: { xs: '100%', sm: 560 }, p: 3 } }}>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
        <Box>
          <Typography variant="h3" component="h2">
            {t?.name ?? 'Empresa'}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {t?.legal_name ?? ''} {t?.rut ? `· ${t.rut}` : ''}
          </Typography>
        </Box>
        <IconButton aria-label="Cerrar" onClick={onClose}>
          <Close />
        </IconButton>
      </Stack>
      {detail.isLoading && <LinearProgress sx={{ my: 2 }} />}
      {detail.error && <ErrorState error={detail.error} what="la empresa" />}
      {t && (
        <Stack spacing={3} sx={{ mt: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center">
            <PlanChip t={t} />
            <Chip size="small" label={t.status === 'ACTIVE' ? 'Activa' : 'Suspendida'} color={t.status === 'ACTIVE' ? 'success' : 'default'} variant="outlined" />
            <Box sx={{ flex: 1 }} />
            <Button
              size="small"
              color={t.status === 'ACTIVE' ? 'error' : 'primary'}
              disabled={setStatus.isPending}
              onClick={() => {
                const next = t.status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE'
                if (next === 'ACTIVE' || window.confirm(`¿Suspender ${t.name}? Sus usuarios dejarán de poder entrar a esta empresa.`)) setStatus.mutate(next)
              }}
            >
              {t.status === 'ACTIVE' ? 'Suspender empresa' : 'Reactivar empresa'}
            </Button>
          </Stack>

          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Cambiar plan
            </Typography>
            <PlanFields plans={plans} value={planForm} onChange={(v) => setPlanForm({ ...planForm, ...v })} errors={errors} />
            <Button variant="contained" sx={{ mt: 1.5 }} disabled={subscribe.isPending} onClick={() => subscribe.mutate()}>
              {subscribe.isPending ? 'Guardando…' : 'Asignar plan'}
            </Button>
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
              El plan anterior se cierra el día previo al inicio del nuevo. Sin cobros: el plan solo habilita módulos y límites.
            </Typography>
          </Box>

          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Personas ({t.seats.used}
              {t.seats.max ? ` de ${t.seats.max}` : ''})
            </Typography>
            {t.users.length === 0 ? (
              <Alert severity="info">Sin personas con acceso.</Alert>
            ) : (
              <DataTable
                columns={[{ label: 'Persona' }, { label: 'Rol' }, { label: 'Último ingreso', hideOnMobile: true }]}
                rows={t.users.map((u) => ({
                  key: u.public_id,
                  cells: [
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600, opacity: u.is_active ? 1 : 0.6 }}>
                        {u.name}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {u.email}
                      </Typography>
                    </Box>,
                    <Typography variant="body2">{u.role ?? '—'}</Typography>,
                    <Typography variant="caption" color="text.secondary">
                      {u.last_login_at ? formatDateTime(u.last_login_at) : 'Aún no ingresa'}
                    </Typography>,
                  ],
                }))}
              />
            )}
          </Box>

          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Historial de planes
            </Typography>
            <DataTable
              columns={[{ label: 'Plan' }, { label: 'Desde' }, { label: 'Hasta' }, { label: 'Estado' }]}
              rows={t.subscriptions.map((s) => ({
                key: s.public_id,
                cells: [s.plan?.name ?? '—', formatDate(s.starts_on), s.ends_on ? formatDate(s.ends_on) : '—', s.status_label],
              }))}
            />
          </Box>
        </Stack>
      )}
    </Drawer>
  )
}
