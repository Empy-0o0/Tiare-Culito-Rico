import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import List from '@mui/material/List'
import ListItem from '@mui/material/ListItem'
import ListItemAvatar from '@mui/material/ListItemAvatar'
import ListItemText from '@mui/material/ListItemText'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import EditOutlined from '@mui/icons-material/EditOutlined'
import PersonOutline from '@mui/icons-material/PersonOutline'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataOriginTag, StatusBadge } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import type { ContactInput, CustomerDto, OrganizationInput } from '../../types/crm'
import { compact, emptyToNull } from '../../utils/compact'
import { isValidRut } from '../../utils/rut'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { FormGrid, OrganizationFields } from './OrganizationFields'
import { DeleteRecordButton } from '../../components/DeleteRecordButton'
import { portalFieldsValid } from '../../utils/portalAccess'
import { InitialPasswordDialog } from '../admin/InitialPasswordDialog'
import { PortalAccessFields, PortalAccessPanel } from './PortalAccessPanel'
import { TenantDataBanner } from './TenantDataBanner'
import { useCrmReferences } from './useCrmReferences'

const STATUS_TONE: Record<string, string> = { ACTIVE: 'approved', PROSPECT: 'pending', INACTIVE: 'draft' }

const emptyOrg: OrganizationInput = { legal_name: '', kind: 'SME' }
const emptyContact: ContactInput = { full_name: '' }

/** Clientes de la empresa activa: organizaciones, contacto principal, plan y estado comercial. */
export function CustomersPage() {
  const { can, profile } = useAuth()
  const isDemo = Boolean(profile?.current_tenant?.is_demo)
  const qc = useQueryClient()
  const { references, regions } = useCrmReferences()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [detail, setDetail] = useState<CustomerDto | null>(null)
  const [org, setOrg] = useState(emptyOrg)
  const [contact, setContact] = useState(emptyContact)
  const [plan, setPlan] = useState('')
  const [editing, setEditing] = useState<CustomerDto | null>(null)
  const [custStatus, setCustStatus] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)

  const list = useQuery({
    queryKey: ['customers', debouncedQ, status, page],
    queryFn: () => crmApi.customers({ q: debouncedQ.trim() || undefined, status: status || undefined, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })

  // Acceso al portal al crear (TD-028): usuario + contraseña de inicio (se muestra una vez).
  const [portal, setPortal] = useState({ enabled: true, email: '' })
  const [issued, setIssued] = useState<{ value: string; name: string; email: string } | null>(null)
  const portalEmail = portal.email || contact.email || org.email || ''

  const create = useMutation({
    mutationFn: () =>
      editing
        ? crmApi.updateCustomer(editing.public_id, { organization: emptyToNull(org) as Partial<OrganizationInput>, plan: plan || null, status: custStatus || undefined })
        : crmApi.createCustomer({ organization: compact(org), contact: contact.full_name ? compact(contact) : undefined, plan: plan || undefined, portal_access: portal.enabled ? { email: portalEmail.trim() } : undefined }),
    onSuccess: (r) => {
      const pa = !editing ? (r.meta as { portal_access?: { initial_password: string | null; user: { email: string } } }).portal_access : undefined
      if (pa?.initial_password) setIssued({ value: pa.initial_password, name: r.data.organization.legal_name, email: pa.user.email })
      setPortal({ enabled: true, email: '' })
      setOpen(false)
      setOrg(emptyOrg)
      setContact(emptyContact)
      setPlan('')
      setToast(editing ? `Cambios guardados en "${r.data.organization.legal_name}".` : `Cliente "${r.data.organization.legal_name}" guardado como prospecto.`)
      if (editing) {
        setDetail(r.data)
        void qc.invalidateQueries({ queryKey: ['customer', editing.public_id] })
      }
      setEditing(null)
      void qc.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setFormError(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })

  const detailFull = useQuery({
    queryKey: ['customer', detail?.public_id],
    queryFn: () => crmApi.customer(detail!.public_id),
    enabled: Boolean(detail),
  })

  const rows = list.data?.data ?? []
  const pagination = list.data?.meta.pagination
  const openForm = (c?: CustomerDto) => {
    setErrors({})
    setFormError(null)
    setEditing(c ?? null)
    if (c) {
      const o = c.organization
      setOrg({ legal_name: o.legal_name, rut: o.rut ?? '', kind: o.kind.code, segment: o.segment ?? '', email: o.email ?? '', phone: o.phone ?? '', region_code: o.region?.code ?? null })
      setPlan(c.plan ?? '')
      setCustStatus(c.status.code)
    } else {
      setOrg(emptyOrg)
      setContact(emptyContact)
      setPlan('')
    }
    setOpen(true)
  }
  const d = detailFull.data?.data ?? detail

  return (
    <>
      <PageHeader
        title="Clientes"
        subtitle="Organizaciones, contactos y estado comercial de la empresa activa."
        actions={
          can('customers.create') && (
            <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
              Agregar cliente
            </Button>
          )
        }
      />

      <TenantDataBanner />

      <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} clientes` : undefined}>
        <SearchField
          value={q}
          onChange={(v) => {
            setQ(v)
            setPage(1)
          }}
          placeholder="Razón social, RUT o correo"
          label="Buscar clientes"
        />
        <SelectField
          label="Estado"
          value={status}
          onChange={(v) => {
            setStatus(v)
            setPage(1)
          }}
          allLabel="Todos"
          options={(references?.customer_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))}
        />
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="los clientes" />}
      {list.isLoading && <ListSkeleton rows={6} />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState
          title={q || status ? 'Ningún cliente coincide con los filtros' : 'Aún no hay clientes'}
          action={
            !q && !status && can('customers.create') ? (
              <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
                Agregar el primer cliente
              </Button>
            ) : undefined
          }
        >
          {q || status ? 'Prueba con otra búsqueda o quita el filtro de estado.' : 'Registra las organizaciones a las que TIARE presta servicio.'}
        </EmptyState>
      )}

      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Organización' }, { label: 'RUT', hideOnMobile: true }, { label: 'Tipo', hideOnMobile: true }, { label: 'Plan', hideOnMobile: true }, { label: 'Estado' }]}
          rows={rows.map((c) => ({
            key: c.public_id,
            label: `Ver ficha de ${c.organization.legal_name}`,
            onClick: () => setDetail(c),
            cells: [
              <>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {c.organization.legal_name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {[c.organization.region?.name, c.organization.email].filter(Boolean).join(', ') || 'Sin región ni correo'}
                </Typography>
              </>,
              <span className="num">{c.organization.rut ?? '—'}</span>,
              <>
                <Chip size="small" variant="outlined" label={c.organization.kind.label} />
                {c.organization.segment && (
                  <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.5 }}>
                    {c.organization.segment}
                  </Typography>
                )}
              </>,
              c.plan ?? '—',
              <StatusBadge label={c.status.label} className={STATUS_TONE[c.status.code] ?? 'draft'} />,
            ],
          }))}
        />
      )}

      {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={setPage} />}

      <FormDialog
        open={open}
        title={editing ? `Editar ${editing.organization.legal_name}` : 'Agregar cliente'}
        onClose={() => {
          setOpen(false)
          setEditing(null)
        }}
        onSubmit={() => create.mutate()}
        submitLabel={editing ? 'Guardar cambios' : 'Guardar cliente'}
        submitting={create.isPending}
        canSubmit={org.legal_name.trim().length >= 2 && !(org.rut && !isValidRut(org.rut)) && (Boolean(editing) || portalFieldsValid({ ...portal, email: portalEmail }))}
        error={formError}
      >
        <OrganizationFields
          org={org}
          contact={contact}
          onOrg={setOrg}
          onContact={setContact}
          references={references}
          regions={regions}
          errors={errors}
          hideContact={Boolean(editing)}
        />
        <Box sx={{ mt: 2 }}>
          <FormGrid>
            <TextField select label="Plan comercial" value={plan} onChange={(e) => setPlan(e.target.value)}>
              <MenuItem value="">Sin plan</MenuItem>
              {(references?.customer_plans ?? []).map((p) => (
                <MenuItem key={p} value={p}>
                  {p}
                </MenuItem>
              ))}
            </TextField>
            {editing && (
              <TextField select label="Estado comercial" value={custStatus} onChange={(e) => setCustStatus(e.target.value)}>
                {(references?.customer_statuses ?? []).map((s) => (
                  <MenuItem key={s.code} value={s.code}>
                    {s.label}
                  </MenuItem>
                ))}
              </TextField>
            )}
          </FormGrid>
        </Box>
        {!editing && (
          <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
            Se crea con estado "Prospecto". Si la organización ya existe como proveedor, se reutiliza.
          </Typography>
        )}
        {!editing && <PortalAccessFields type="customer" value={{ ...portal, email: portalEmail }} onChange={setPortal} error={fieldError(errors, 'portal_access.email')} />}
      </FormDialog>

      <InitialPasswordDialog value={issued?.value ?? null} name={issued?.name} email={issued?.email} onClose={() => setIssued(null)} />

      <Drawer
        open={Boolean(detail)}
        onClose={() => setDetail(null)}
        code={d?.organization.legal_name ?? ''}
        title={d?.organization.kind.label}
        headingVariant="name"
        footer={
          d && (can('customers.update') || can('customers.delete')) ? (
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              {can('customers.update') && (
                <Button variant="contained" startIcon={<EditOutlined />} onClick={() => openForm(d)}>
                  Editar cliente
                </Button>
              )}
              {can('customers.delete') && (
                <DeleteRecordButton
                  noun="cliente"
                  name={d.organization.legal_name}
                  detail="Si tiene solicitudes de compra asociadas, no se podrá eliminar: déjalo en estado Inactivo."
                  onDelete={() => crmApi.deleteCustomer(d.public_id)}
                  onDeleted={() => {
                    setToast(`Cliente "${d.organization.legal_name}" eliminado.`)
                    setDetail(null)
                    void qc.invalidateQueries({ queryKey: ['customers'] })
                  }}
                />
              )}
            </Stack>
          ) : undefined
        }
      >
        {d && (
          <>
            <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
              <StatusBadge label={d.status.label} className={STATUS_TONE[d.status.code] ?? 'draft'} />
              <DataOriginTag origin={isDemo ? 'demo' : 'client'} />
            </Stack>
            <Facts
              rows={[
                ['RUT', d.organization.rut],
                ['Plan', d.plan],
                ['Responsable', d.owner?.name],
                ['Región', d.organization.region?.name],
                ['Segmento', d.organization.segment],
                ['Correo', d.organization.email],
                ['Teléfono', d.organization.phone],
                ['Próxima acción', d.next_action_summary],
              ]}
            />
            <DetailSection title="Contactos">
              {detailFull.isLoading ? (
                <LinearProgress sx={{ borderRadius: '4px' }} />
              ) : d.organization.contacts && d.organization.contacts.length > 0 ? (
                <List disablePadding sx={{ bgcolor: '#fff', borderRadius: '12px', border: `1px solid ${tokens.outlineVariant}` }}>
                  {d.organization.contacts.map((ct) => (
                    <ListItem key={ct.public_id} secondaryAction={ct.is_primary ? <Chip size="small" label="Principal" color="primary" variant="outlined" /> : undefined}>
                      <ListItemAvatar>
                        <Avatar sx={{ bgcolor: tokens.primaryContainer, color: tokens.primary }}>
                          <PersonOutline />
                        </Avatar>
                      </ListItemAvatar>
                      <ListItemText primary={ct.full_name} secondary={[ct.position, ct.email, ct.phone].filter(Boolean).join(', ') || 'Sin datos de contacto'} />
                    </ListItem>
                  ))}
                </List>
              ) : (
                <Typography variant="body2" color="text.secondary">
                  Sin contactos registrados.
                </Typography>
              )}
            </DetailSection>
            <DetailSection title="Acceso al portal del cliente">
              <PortalAccessPanel key={d.public_id} type="customer" partyId={d.public_id} partyName={d.organization.legal_name} canManage={can('customers.update')} defaultEmail={d.organization.contacts?.find((c) => c.email)?.email ?? d.organization.email} />
            </DetailSection>
          </>
        )}
      </Drawer>

      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
