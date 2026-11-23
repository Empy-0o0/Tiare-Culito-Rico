import Alert from '@mui/material/Alert'
import Autocomplete from '@mui/material/Autocomplete'
import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import InputAdornment from '@mui/material/InputAdornment'
import LinearProgress from '@mui/material/LinearProgress'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import MenuItem from '@mui/material/MenuItem'
import Rating from '@mui/material/Rating'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import EditOutlined from '@mui/icons-material/EditOutlined'
import WarningAmberOutlined from '@mui/icons-material/WarningAmberOutlined'
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
import type { CategoryDto, ContactInput, OrganizationInput, SupplierDto } from '../../types/crm'
import { compact, emptyToNull } from '../../utils/compact'
import { isValidRut } from '../../utils/rut'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { FormGrid, FormSectionTitle, OrganizationFields } from './OrganizationFields'
import { SupplierDocumentsPanel } from './SupplierDocumentsPanel'
import { SupplierEvaluationsPanel } from './SupplierEvaluationsPanel'
import { SupplierOfferingsPanel } from './SupplierOfferingsPanel'
import { DeleteRecordButton } from '../../components/DeleteRecordButton'
import { portalFieldsValid } from '../../utils/portalAccess'
import { InitialPasswordDialog } from '../admin/InitialPasswordDialog'
import { PortalAccessFields, PortalAccessPanel } from './PortalAccessPanel'
import { TenantDataBanner } from './TenantDataBanner'
import { useCrmReferences } from './useCrmReferences'

const STATUS_TONE: Record<string, string> = { APPROVED_FOR_QUOTES: 'approved', UNDER_REVIEW: 'progress', REGISTERED: 'pending', SUSPENDED: 'rejected' }

const initials = (name: string) =>
  name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')

/** Red de proveedores: rubros, cobertura territorial, plazo y estado de habilitación. */
export function SuppliersPage() {
  const { can, profile } = useAuth()
  const isDemo = Boolean(profile?.current_tenant?.is_demo)
  const qc = useQueryClient()
  const { references, regions } = useCrmReferences()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [region, setRegion] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [detail, setDetail] = useState<SupplierDto | null>(null)
  const [org, setOrg] = useState<OrganizationInput>({ legal_name: '', kind: 'SME' })
  const [contact, setContact] = useState<ContactInput>({ full_name: '' })
  const [cats, setCats] = useState<CategoryDto[]>([])
  const [coverage, setCoverage] = useState<Array<{ code: number; name: string }>>([])
  const [leadTime, setLeadTime] = useState('')
  const [editing, setEditing] = useState<SupplierDto | null>(null)
  const [supStatus, setSupStatus] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [notes, setNotes] = useState('')
  const [tab, setTab] = useState<'summary' | 'offerings' | 'evaluations' | 'documents' | 'portal'>('summary')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)

  const categories = useQuery({ queryKey: ['categories'], queryFn: crmApi.categories })
  const list = useQuery({
    queryKey: ['suppliers', debouncedQ, status, category, region, page],
    queryFn: () =>
      crmApi.suppliers({
        q: debouncedQ.trim() || undefined,
        status: status || undefined,
        category_public_id: category || undefined,
        region: region ? Number(region) : undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  // Acceso al portal al crear (TD-028): usuario + contraseña de inicio (se muestra una vez).
  const [portal, setPortal] = useState({ enabled: true, email: '' })
  const [issued, setIssued] = useState<{ value: string; name: string; email: string } | null>(null)
  const portalEmail = portal.email || contact.email || org.email || ''

  const create = useMutation({
    mutationFn: () => {
      const common = {
        category_public_ids: cats.map((c) => c.public_id),
        coverage_regions: coverage.map((r) => r.code),
        lead_time_days: leadTime ? Number(leadTime) : null,
      }
      return editing
        ? crmApi.updateSupplier(editing.public_id, {
            ...common,
            organization: emptyToNull(org) as Partial<OrganizationInput>,
            status: supStatus || undefined,
            payment_terms: paymentTerms || null,
            notes: notes || null,
          })
        : crmApi.createSupplier({ ...common, organization: compact(org), contact: contact.full_name ? compact(contact) : undefined, portal_access: portal.enabled ? { email: portalEmail.trim() } : undefined })
    },
    onSuccess: (r) => {
      const pa = !editing ? (r.meta as { portal_access?: { initial_password: string | null; user: { email: string } } }).portal_access : undefined
      if (pa?.initial_password) setIssued({ value: pa.initial_password, name: r.data.organization.legal_name, email: pa.user.email })
      setPortal({ enabled: true, email: '' })
      setOpen(false)
      setOrg({ legal_name: '', kind: 'SME' })
      setContact({ full_name: '' })
      setCats([])
      setCoverage([])
      setLeadTime('')
      setToast(editing ? `Cambios guardados en "${r.data.organization.legal_name}".` : `Proveedor "${r.data.organization.legal_name}" registrado.`)
      if (editing) setDetail({ ...r.data, evaluation: editing.evaluation, expired_documents: editing.expired_documents })
      setEditing(null)
      void qc.invalidateQueries({ queryKey: ['suppliers'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setFormError(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })

  const rows = list.data?.data ?? []
  const pagination = list.data?.meta.pagination
  const filtered = Boolean(q || status || category || region)
  const reset = (fn: (v: string) => void) => (v: string) => {
    fn(v)
    setPage(1)
  }
  const openForm = (s?: SupplierDto) => {
    setErrors({})
    setFormError(null)
    setEditing(s ?? null)
    if (s) {
      const o = s.organization
      setOrg({ legal_name: o.legal_name, rut: o.rut ?? '', kind: o.kind.code, segment: o.segment ?? '', email: o.email ?? '', phone: o.phone ?? '', region_code: o.region?.code ?? null })
      setCats(s.categories ?? [])
      setCoverage(s.coverage_regions)
      setLeadTime(s.lead_time_days != null ? String(s.lead_time_days) : '')
      setSupStatus(s.status.code)
      setPaymentTerms(s.payment_terms ?? '')
      setNotes(s.notes ?? '')
    } else {
      setOrg({ legal_name: '', kind: 'SME' })
      setContact({ full_name: '' })
      setCats([])
      setCoverage([])
      setLeadTime('')
    }
    setOpen(true)
  }
  const showDetail = (s: SupplierDto) => {
    setTab('summary')
    setDetail(s)
  }

  return (
    <>
      <PageHeader
        title="Proveedores"
        subtitle="Red de suministro: rubros, cobertura territorial y estado de habilitación."
        actions={
          can('suppliers.create') && (
            <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
              Registrar proveedor
            </Button>
          )
        }
      />

      <TenantDataBanner />

      <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} proveedores` : undefined}>
        <SearchField value={q} onChange={reset(setQ)} placeholder="Proveedor, RUT, insumo o servicio" label="Buscar proveedores" />
        <SelectField label="Categoría" value={category} onChange={reset(setCategory)} allLabel="Todas" options={(categories.data?.data ?? []).map((c) => ({ value: c.public_id, label: c.name }))} />
        <SelectField label="Cobertura" value={region} onChange={reset(setRegion)} allLabel="Todo Chile" options={(regions ?? []).map((r) => ({ value: String(r.code), label: r.name }))} />
        <SelectField label="Estado" value={status} onChange={reset(setStatus)} allLabel="Todos" options={(references?.supplier_statuses ?? []).map((s) => ({ value: s.code, label: s.label }))} />
      </FilterBar>

      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="los proveedores" />}
      {list.isLoading && <ListSkeleton rows={6} />}
      {!list.isLoading && !list.error && rows.length === 0 && (
        <EmptyState
          title={filtered ? 'Ningún proveedor coincide con los filtros' : 'Aún no hay proveedores'}
          action={
            !filtered && can('suppliers.create') ? (
              <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
                Registrar el primer proveedor
              </Button>
            ) : undefined
          }
        >
          {filtered ? 'Prueba con otra búsqueda o quita filtros.' : 'Registra a los proveedores con los que TIARE cotiza.'}
        </EmptyState>
      )}

      {rows.length > 0 && (
        <DataTable
          columns={[
            { label: 'Proveedor' },
            { label: 'Categorías', hideOnMobile: true },
            { label: 'Evaluación', hideOnMobile: true },
            { label: 'Plazo', align: 'right', hideOnMobile: true },
            { label: 'Estado' },
          ]}
          rows={rows.map((s) => ({
            key: s.public_id,
            label: `Ver ficha de ${s.organization.legal_name}`,
            onClick: () => showDetail(s),
            cells: [
              <Stack direction="row" spacing={1.5} alignItems="center">
                <Avatar variant="rounded" sx={{ width: 36, height: 36, fontSize: '0.8rem', fontWeight: 700, bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer }}>
                  {initials(s.organization.legal_name)}
                </Avatar>
                <Box sx={{ minWidth: 0 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {s.organization.legal_name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary" className="num">
                    {s.organization.rut ?? s.organization.kind.label}
                    {(s.offerings_count ?? 0) > 0 && ` · ${s.offerings_count} ${s.offerings_count === 1 ? 'insumo/servicio' : 'insumos/servicios'}`}
                  </Typography>
                </Box>
              </Stack>,
              <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                {(s.categories ?? []).slice(0, 3).map((c) => (
                  <Chip key={c.public_id} size="small" variant="outlined" label={c.name} />
                ))}
                {(s.categories?.length ?? 0) > 3 && <Chip size="small" label={`+${(s.categories?.length ?? 0) - 3}`} />}
                {!s.categories?.length && '—'}
              </Stack>,
              s.evaluation?.average ? (
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Rating value={Number(s.evaluation.average)} precision={0.1} readOnly size="small" />
                  <Typography variant="caption" color="text.secondary" className="num">
                    {Number(s.evaluation.average).toFixed(1)} ({s.evaluation.count})
                  </Typography>
                </Stack>
              ) : (
                <Typography variant="caption" color="text.secondary">
                  Sin evaluar
                </Typography>
              ),
              s.lead_time_days != null ? `${s.lead_time_days} días` : '—',
              <Stack spacing={0.5} alignItems="flex-start">
                <StatusBadge label={s.status.label} className={STATUS_TONE[s.status.code] ?? 'draft'} />
                {(s.expired_documents ?? 0) > 0 && (
                  <Chip size="small" icon={<WarningAmberOutlined />} label={s.expired_documents === 1 ? '1 documento vencido' : `${s.expired_documents} documentos vencidos`} color="error" variant="outlined" />
                )}
              </Stack>,
            ],
          }))}
        />
      )}

      {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={setPage} />}

      <Alert severity="info" sx={{ mt: 3 }}>
        "Habilitado para cotizar" es una decisión operativa interna. TIARE no presenta proveedores como "validados" mientras no existan criterios y evidencia documental definidos.
      </Alert>

      <FormDialog
        open={open}
        title={editing ? `Editar ${editing.organization.legal_name}` : 'Registrar proveedor'}
        onClose={() => {
          setOpen(false)
          setEditing(null)
        }}
        onSubmit={() => create.mutate()}
        submitLabel={editing ? 'Guardar cambios' : 'Guardar proveedor'}
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
            <FormSectionTitle>Abastecimiento</FormSectionTitle>
            <Autocomplete
              multiple
              options={categories.data?.data ?? []}
              getOptionLabel={(c) => c.name}
              isOptionEqualToValue={(a, b) => a.public_id === b.public_id}
              value={cats}
              onChange={(_, v) => setCats(v)}
              noOptionsText="Crea categorías en Catálogo"
              renderInput={(p) => (
                <TextField {...p} label="Categorías" error={Boolean(fieldError(errors, 'category_public_ids.0'))} helperText={fieldError(errors, 'category_public_ids.0') ?? 'Rubros que el proveedor abastece'} />
              )}
              sx={{ gridColumn: '1 / -1' }}
            />
            <Autocomplete
              multiple
              options={regions ?? []}
              getOptionLabel={(r) => r.name}
              isOptionEqualToValue={(a, b) => a.code === b.code}
              value={coverage}
              onChange={(_, v) => setCoverage(v)}
              renderInput={(p) => <TextField {...p} label="Cobertura territorial" helperText="Regiones donde despacha o presta servicio" />}
              sx={{ gridColumn: '1 / -1' }}
            />
            <TextField
              label="Plazo de entrega habitual"
              type="number"
              value={leadTime}
              onChange={(e) => setLeadTime(e.target.value)}
              inputProps={{ min: 0, max: 365 }}
              InputProps={{ endAdornment: <InputAdornment position="end">días</InputAdornment> }}
            />
            {editing && (
              <>
                <TextField label="Condiciones de pago" value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} inputProps={{ maxLength: 120 }} placeholder="Ej. 30 días contra factura" />
                <TextField
                  select
                  label="Estado de habilitación"
                  value={supStatus}
                  onChange={(e) => setSupStatus(e.target.value)}
                  helperText="Decisión operativa interna de TIARE"
                >
                  {(references?.supplier_statuses ?? []).map((st) => (
                    <MenuItem key={st.code} value={st.code}>
                      {st.label}
                    </MenuItem>
                  ))}
                </TextField>
                <TextField label="Notas internas" value={notes} onChange={(e) => setNotes(e.target.value)} multiline minRows={2} inputProps={{ maxLength: 5000 }} sx={{ gridColumn: '1 / -1' }} />
              </>
            )}
          </FormGrid>
        </Box>
        {!editing && (
          <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
            Queda en estado "Registrado". El registro no valida por sí mismo la habilitación legal, tributaria o técnica del proveedor.
          </Typography>
        )}
        {!editing && <PortalAccessFields type="supplier" value={{ ...portal, email: portalEmail }} onChange={setPortal} error={fieldError(errors, 'portal_access.email')} />}
      </FormDialog>

      <InitialPasswordDialog value={issued?.value ?? null} name={issued?.name} email={issued?.email} onClose={() => setIssued(null)} />

      <Drawer
        open={Boolean(detail)}
        onClose={() => setDetail(null)}
        code={detail?.organization.legal_name ?? ''}
        title={detail?.organization.kind.label}
        headingVariant="name"
        footer={
          detail && (can('suppliers.update') || can('suppliers.delete')) ? (
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              {can('suppliers.update') && (
                <Button variant="contained" startIcon={<EditOutlined />} onClick={() => openForm(detail)}>
                  Editar proveedor
                </Button>
              )}
              {can('suppliers.delete') && (
                <DeleteRecordButton
                  noun="proveedor"
                  name={detail.organization.legal_name}
                  detail="También se eliminan sus evaluaciones y documentos. Si tiene cotizaciones, órdenes o facturas, no se podrá eliminar."
                  onDelete={() => crmApi.deleteSupplier(detail.public_id)}
                  onDeleted={() => {
                    setToast(`Proveedor "${detail.organization.legal_name}" eliminado.`)
                    setDetail(null)
                    void qc.invalidateQueries({ queryKey: ['suppliers'] })
                  }}
                />
              )}
            </Stack>
          ) : undefined
        }
      >
        {detail && (
          <>
            <Stack direction="row" spacing={1} sx={{ mb: 1.5 }}>
              <StatusBadge label={detail.status.label} className={STATUS_TONE[detail.status.code] ?? 'draft'} />
              <DataOriginTag origin={isDemo ? 'demo' : 'client'} />
            </Stack>
            <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2, borderBottom: `1px solid ${tokens.outlineVariant}` }} variant="scrollable" allowScrollButtonsMobile>
              <Tab value="summary" label="Resumen" />
              <Tab value="offerings" label="Oferta y capacidad" />
              <Tab value="evaluations" label="Evaluación" />
              <Tab value="documents" label={(detail.expired_documents ?? 0) > 0 ? `Documentos (${detail.expired_documents} vencido${detail.expired_documents === 1 ? '' : 's'})` : 'Documentos'} />
              <Tab value="portal" label="Acceso al portal" />
            </Tabs>

            {tab === 'summary' && (
              <>
                <Facts
                  rows={[
                    ['RUT', detail.organization.rut],
                    ['Plazo de entrega', detail.lead_time_days != null ? `${detail.lead_time_days} días` : null],
                    ['Condiciones de pago', detail.payment_terms],
                    ['Región', detail.organization.region?.name],
                    ['Correo', detail.organization.email],
                    ['Teléfono', detail.organization.phone],
                  ]}
                />
                <DetailSection title="Categorías">
                  <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                    {(detail.categories ?? []).map((c) => (
                      <Chip key={c.public_id} label={c.name} sx={{ bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer }} />
                    ))}
                    {!detail.categories?.length && (
                      <Typography variant="body2" color="text.secondary">
                        Sin categorías.
                      </Typography>
                    )}
                  </Stack>
                </DetailSection>
                <DetailSection title="Cobertura territorial">
                  <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                    {detail.coverage_regions.map((r) => (
                      <Chip key={r.code} size="small" variant="outlined" label={r.name} />
                    ))}
                    {!detail.coverage_regions.length && (
                      <Typography variant="body2" color="text.secondary">
                        Sin cobertura registrada.
                      </Typography>
                    )}
                  </Stack>
                </DetailSection>
                {detail.notes && (
                  <DetailSection title="Notas internas">
                    <Typography variant="body2" sx={{ whiteSpace: 'pre-line' }}>
                      {detail.notes}
                    </Typography>
                  </DetailSection>
                )}
              </>
            )}
            {tab === 'offerings' && <SupplierOfferingsPanel supplierId={detail.public_id} canManage={can('suppliers.update')} />}
            {tab === 'evaluations' && <SupplierEvaluationsPanel supplierId={detail.public_id} canEvaluate={can('suppliers.evaluate')} />}
            {tab === 'documents' && <SupplierDocumentsPanel supplierId={detail.public_id} canManage={can('suppliers.update')} />}
            {tab === 'portal' && <PortalAccessPanel key={detail.public_id} type="supplier" partyId={detail.public_id} partyName={detail.organization.legal_name} canManage={can('suppliers.update')} defaultEmail={detail.organization.email} />}
          </>
        )}
      </Drawer>

      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
