import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import Link from '@mui/material/Link'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import AttachFileOutlined from '@mui/icons-material/AttachFileOutlined'
import ReportProblemOutlined from '@mui/icons-material/ReportProblemOutlined'
import TaskAltOutlined from '@mui/icons-material/TaskAltOutlined'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { StatusBadge } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { FilterBar, SearchField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { logisticsApi, type Carrier, type ShipmentDetail, type ShipmentInput } from '../../services/logistics'
import { procurementApi } from '../../services/procurement'
import { formatDate, formatDateTime, formatMoney } from '../../utils/format'
import { useCrmReferences } from '../crm/useCrmReferences'

const errMsg = (e: unknown) => (e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')

/** Tono visual de cada estado de despacho. */
const shipmentTone = (code: string) =>
  ({ PLANNED: 'draft', IN_TRANSIT: 'progress', DELIVERED: 'approved', FAILED: 'rejected', RETURNED: 'pending', CANCELLED: 'rejected' })[code] ?? 'draft'

const TRANSITION_LABEL: Record<string, string> = {
  IN_TRANSIT: 'Marcar en tránsito',
  FAILED: 'Entrega fallida',
  RETURNED: 'Marcar devuelto',
  CANCELLED: 'Cancelar despacho',
}

/** Logística: despachos con seguimiento, incidencias, entrega con evidencia y transportistas aliados. */
export function LogisticsPage() {
  const { can } = useAuth()
  const [tab, setTab] = useState<'shipments' | 'carriers'>('shipments')
  const [toast, setToast] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const canUpdate = can('shipments.update')

  return (
    <>
      <PageHeader
        title="Logística"
        subtitle="Despachos coordinados con transportistas aliados, desde el retiro hasta la recepción conforme."
        actions={
          canUpdate && tab === 'shipments' ? (
            <Button variant="contained" startIcon={<Add />} onClick={() => setCreating(true)}>
              Nuevo despacho
            </Button>
          ) : undefined
        }
      />
      <Box sx={{ borderBottom: `1px solid ${tokens.outlineVariant}`, mb: 2.5 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)}>
          <Tab value="shipments" label="Despachos" />
          <Tab value="carriers" label="Transportistas" />
        </Tabs>
      </Box>
      {tab === 'shipments' ? <ShipmentsTab onToast={setToast} /> : <CarriersTab canUpdate={canUpdate} onToast={setToast} />}
      <ShipmentDialog open={creating} onClose={() => setCreating(false)} onSaved={() => (setCreating(false), setToast('Despacho creado.'))} />
      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

function ShipmentsTab({ onToast }: { onToast: (m: string) => void }) {
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [late, setLate] = useState(false)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)
  const refs = useQuery({ queryKey: ['logistics-refs'], queryFn: logisticsApi.references, staleTime: Infinity })
  const list = useQuery({
    queryKey: ['shipments', debouncedQ, status, late, page],
    queryFn: () => logisticsApi.shipments({ q: debouncedQ || undefined, status: status || undefined, late: late || undefined, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })
  const rows = list.data?.data ?? []
  const counts = list.data?.meta.status_counts ?? {}

  return (
    <>
      <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }}>
        {(refs.data?.data.statuses ?? []).map((s) => (
          <Chip
            key={s.code}
            label={`${s.label} ${counts[s.code] ?? 0}`}
            onClick={() => (setStatus(status === s.code ? '' : s.code), setPage(1))}
            color={status === s.code ? 'primary' : 'default'}
            variant={status === s.code ? 'filled' : 'outlined'}
          />
        ))}
      </Stack>
      <FilterBar count={list.data?.meta.pagination ? `${list.data.meta.pagination.total} despachos` : undefined}>
        <SearchField value={q} onChange={(v) => (setQ(v), setPage(1))} placeholder="N° despacho, destino o seguimiento" label="Buscar despachos" />
        <FormControlLabel control={<Switch checked={late} onChange={(e) => (setLate(e.target.checked), setPage(1))} />} label="Solo atrasados" />
      </FilterBar>
      {list.error && <ErrorState error={list.error} what="los despachos" />}
      {list.isLoading && <ListSkeleton rows={4} />}
      {!list.isLoading && rows.length === 0 && (
        <EmptyState title={q || status || late ? 'Nada coincide con los filtros' : 'Aún no hay despachos'}>
          {q || status || late ? 'Prueba con otros filtros.' : 'Crea un despacho para coordinar el traslado de una orden con un transportista.'}
        </EmptyState>
      )}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Despacho' }, { label: 'Destino' }, { label: 'Transportista', hideOnMobile: true }, { label: 'Entrega', hideOnMobile: true }, { label: 'Estado', align: 'right' }]}
          rows={rows.map((s) => ({
            key: s.public_id,
            onClick: () => setSelected(s.public_id),
            label: `Abrir despacho ${s.number}`,
            cells: [
              <>
                <span className="code">{s.number}</span>
                {s.order && (
                  <Typography variant="caption" color="text.secondary" component="div">
                    OC {s.order.number}
                  </Typography>
                )}
              </>,
              <Typography variant="body2">{s.destination}</Typography>,
              <>
                {s.carrier?.name ?? '—'}
                {s.tracking_code && (
                  <Typography variant="caption" color="text.secondary" component="div">
                    {s.tracking_code}
                  </Typography>
                )}
              </>,
              s.delivered_at ? formatDate(s.delivered_at) : (
                <Typography variant="body2" sx={{ color: s.late ? tokens.urgent : undefined, fontWeight: s.late ? 700 : 400 }}>
                  {s.planned_delivery_on ? formatDate(s.planned_delivery_on) : '—'}
                  {s.late && ' (atrasado)'}
                </Typography>
              ),
              <Stack alignItems="flex-end" spacing={0.5}>
                <StatusBadge code={s.status.code} label={s.status.label} className={shipmentTone(s.status.code)} />
                {s.open_incidents > 0 && <Chip size="small" icon={<ReportProblemOutlined />} label={s.open_incidents === 1 ? '1 incidencia' : `${s.open_incidents} incidencias`} sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.urgent, height: 22 }} />}
              </Stack>,
            ],
          }))}
        />
      )}
      {list.data?.meta.pagination && <Pager page={list.data.meta.pagination.page} lastPage={list.data.meta.pagination.last_page} onChange={setPage} />}
      <ShipmentDrawer shipmentId={selected} onClose={() => setSelected(null)} onChanged={onToast} />
    </>
  )
}

function ShipmentDrawer({ shipmentId, onClose, onChanged }: { shipmentId: string | null; onClose: () => void; onChanged: (m: string) => void }) {
  const qc = useQueryClient()
  const { can } = useAuth()
  const query = useQuery({ queryKey: ['shipment', shipmentId], queryFn: () => logisticsApi.shipment(shipmentId!), enabled: Boolean(shipmentId) })
  const refs = useQuery({ queryKey: ['logistics-refs'], queryFn: logisticsApi.references, staleTime: Infinity })
  const s = query.data?.data
  const [error, setError] = useState<string | null>(null)
  const [transition, setTransition] = useState<string | null>(null)
  const [transitionNote, setTransitionNote] = useState('')
  const [note, setNote] = useState('')
  const [incident, setIncident] = useState<{ type: string; description: string } | null>(null)
  const [resolving, setResolving] = useState<{ id: string; text: string } | null>(null)
  const [delivering, setDelivering] = useState(false)
  const [editing, setEditing] = useState(false)

  const set = (d: ShipmentDetail, msg: string) => {
    qc.setQueryData(['shipment', shipmentId], { data: d, meta: query.data?.meta })
    void qc.invalidateQueries({ queryKey: ['shipments'] })
    setError(null)
    onChanged(msg)
  }
  const fail = (e: unknown) => setError(errMsg(e))
  const changeStatus = useMutation({
    mutationFn: () => logisticsApi.status(shipmentId!, { status: transition!, description: transitionNote.trim() || undefined }),
    onSuccess: (d) => {
      setTransition(null)
      setTransitionNote('')
      set(d.data, `Despacho: ${d.data.status.label.toLowerCase()}.`)
    },
    onError: fail,
  })
  const addNote = useMutation({
    mutationFn: () => logisticsApi.note(shipmentId!, { description: note.trim() }),
    onSuccess: (d) => (setNote(''), set(d.data, 'Seguimiento registrado.')),
    onError: fail,
  })
  const report = useMutation({
    mutationFn: () => logisticsApi.incident(shipmentId!, { type: incident!.type, description: incident!.description.trim() }),
    onSuccess: (d) => (setIncident(null), set(d.data, 'Incidencia registrada.')),
    onError: fail,
  })
  const resolve = useMutation({
    mutationFn: () => logisticsApi.resolve(shipmentId!, resolving!.id, resolving!.text.trim()),
    onSuccess: (d) => (setResolving(null), set(d.data, 'Incidencia resuelta.')),
    onError: fail,
  })

  const money = (v: string | null) => (v ? formatMoney(v) : '—')
  const canDeliver = s?.actions.deliver && can('shipments.confirm_delivery')

  return (
    <Drawer
      open={Boolean(shipmentId)}
      onClose={onClose}
      code={s?.number ?? ''}
      title={s ? s.destination : undefined}
      footer={
        s && (s.actions.transitions.length > 0 || canDeliver) ? (
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
            {canDeliver && (
              <Button variant="contained" startIcon={<TaskAltOutlined />} onClick={() => setDelivering(true)}>
                Confirmar entrega
              </Button>
            )}
            {s.actions.transitions.map((t) => (
              <Button key={t} variant={t === 'IN_TRANSIT' && !canDeliver ? 'contained' : 'outlined'} color={t === 'CANCELLED' || t === 'FAILED' ? 'error' : 'primary'} onClick={() => (setTransition(t), setTransitionNote(''))}>
                {TRANSITION_LABEL[t] ?? t}
              </Button>
            ))}
          </Stack>
        ) : undefined
      }
    >
      {query.isLoading && <ListSkeleton rows={3} />}
      {query.error && <ErrorState error={query.error} what="el despacho" />}
      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      {s && (
        <>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 2 }}>
            <StatusBadge code={s.status.code} label={s.status.label} className={shipmentTone(s.status.code)} />
            {s.late && <Chip size="small" label="Atrasado" sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.urgent }} />}
          </Stack>
          {transition && (
            <Alert severity={transition === 'FAILED' || transition === 'CANCELLED' ? 'warning' : 'info'} sx={{ mb: 2 }}>
              <Typography variant="body2" sx={{ mb: 1 }}>
                {TRANSITION_LABEL[transition]}. {transition === 'FAILED' ? 'Indica qué pasó.' : 'Puedes agregar un comentario.'}
              </Typography>
              <TextField size="small" fullWidth multiline label="Comentario" required={transition === 'FAILED'} value={transitionNote} onChange={(e) => setTransitionNote(e.target.value)} sx={{ mb: 1, bgcolor: tokens.surface }} />
              <Stack direction="row" spacing={1}>
                <Button size="small" variant="contained" disabled={changeStatus.isPending || (transition === 'FAILED' && transitionNote.trim().length < 3)} onClick={() => changeStatus.mutate()}>
                  Confirmar
                </Button>
                <Button size="small" onClick={() => setTransition(null)}>
                  Volver
                </Button>
              </Stack>
            </Alert>
          )}
          <Facts
            rows={[
              ['Origen', s.origin],
              ['Destino', [s.destination, s.destination_region?.name].filter(Boolean).join(', ')],
              ['Contacto en destino', [s.contact_name, s.contact_phone].filter(Boolean).join(', ') || '—'],
              ['Transportista', s.carrier ? `${s.carrier.name}${s.service_type ? `, ${s.service_type.label.toLowerCase()}` : ''}` : '—'],
              [
                'Seguimiento',
                s.tracking_code ? (
                  s.tracking_url ? (
                    <Link href={s.tracking_url} target="_blank" rel="noopener noreferrer">
                      {s.tracking_code}
                    </Link>
                  ) : (
                    s.tracking_code
                  )
                ) : (
                  '—'
                ),
              ],
              ['Orden de compra', s.order?.number ?? 'Sin orden asociada'],
              ['Bultos y peso', [s.packages ? `${s.packages} bultos` : null, s.weight_kg ? `${Number(s.weight_kg)} kg` : null].filter(Boolean).join(', ') || '—'],
              ['Retiro', s.picked_up_at ? formatDateTime(s.picked_up_at) : s.planned_pickup_on ? `Planificado ${formatDate(s.planned_pickup_on)}` : '—'],
              ['Entrega', s.delivered_at ? formatDateTime(s.delivered_at) : s.planned_delivery_on ? `Planificada ${formatDate(s.planned_delivery_on)}` : '—'],
              ['Costo estimado / real', `${money(s.estimated_cost)} / ${money(s.actual_cost)}`],
            ]}
          />
          {s.actions.update && (
            <Button size="small" sx={{ mt: 1 }} onClick={() => setEditing(true)}>
              {s.status.code === 'DELIVERED' || s.status.code === 'CANCELLED' || s.status.code === 'RETURNED' ? 'Registrar costo real' : 'Editar datos del despacho'}
            </Button>
          )}

          {s.status.code === 'DELIVERED' && (
            <DetailSection title="Recepción">
              <Facts
                rows={[
                  ['Recibido por', s.received_by_name ?? '—'],
                  ['Observaciones', s.delivery_notes ?? '—'],
                  [
                    'Evidencia',
                    s.evidence_url ? (
                      <Link href={s.evidence_url} target="_blank" rel="noopener noreferrer">
                        Ver comprobante
                      </Link>
                    ) : (
                      'Sin comprobante adjunto'
                    ),
                  ],
                ]}
              />
            </DetailSection>
          )}

          <DetailSection title="Incidencias">
            {s.incidents.length === 0 && (
              <Typography variant="body2" color="text.secondary">
                Sin incidencias registradas.
              </Typography>
            )}
            <Stack spacing={1}>
              {s.incidents.map((i) => (
                <Box key={i.public_id} sx={{ p: 1.5, borderRadius: '12px', bgcolor: i.status === 'OPEN' ? tokens.tertiaryContainer : tokens.surfaceHigh }}>
                  <Stack direction="row" justifyContent="space-between" alignItems="baseline">
                    <Typography variant="body2" sx={{ fontWeight: 700 }}>
                      {i.type.label}
                    </Typography>
                    <Typography variant="caption">{i.status === 'OPEN' ? 'Abierta' : 'Resuelta'}</Typography>
                  </Stack>
                  <Typography variant="body2">{i.description}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {[i.reported_by, formatDateTime(i.created_at)].filter(Boolean).join(', ')}
                  </Typography>
                  {i.resolution && (
                    <Typography variant="body2" sx={{ mt: 0.5 }}>
                      Solución: {i.resolution}
                    </Typography>
                  )}
                  {i.status === 'OPEN' && can('shipments.update') && (
                    resolving?.id === i.public_id ? (
                      <Stack spacing={1} sx={{ mt: 1 }}>
                        <TextField size="small" label="Cómo se resolvió" value={resolving.text} onChange={(e) => setResolving({ id: i.public_id, text: e.target.value })} sx={{ bgcolor: tokens.surface }} />
                        <Stack direction="row" spacing={1}>
                          <Button size="small" variant="contained" disabled={resolving.text.trim().length < 3 || resolve.isPending} onClick={() => resolve.mutate()}>
                            Marcar resuelta
                          </Button>
                          <Button size="small" onClick={() => setResolving(null)}>
                            Volver
                          </Button>
                        </Stack>
                      </Stack>
                    ) : (
                      <Button size="small" sx={{ mt: 0.5 }} onClick={() => setResolving({ id: i.public_id, text: '' })}>
                        Resolver
                      </Button>
                    )
                  )}
                </Box>
              ))}
            </Stack>
            {s.actions.report_incident &&
              (incident ? (
                <Stack spacing={1.5} sx={{ mt: 1.5 }}>
                  <TextField select size="small" label="Tipo" value={incident.type} onChange={(e) => setIncident({ ...incident, type: e.target.value })}>
                    {(refs.data?.data.incident_types ?? []).map((t) => (
                      <MenuItem key={t.code} value={t.code}>
                        {t.label}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField size="small" multiline minRows={2} label="Qué ocurrió" value={incident.description} onChange={(e) => setIncident({ ...incident, description: e.target.value })} />
                  <Stack direction="row" spacing={1}>
                    <Button size="small" variant="contained" disabled={!incident.type || incident.description.trim().length < 3 || report.isPending} onClick={() => report.mutate()}>
                      Registrar incidencia
                    </Button>
                    <Button size="small" onClick={() => setIncident(null)}>
                      Volver
                    </Button>
                  </Stack>
                </Stack>
              ) : (
                <Button size="small" startIcon={<ReportProblemOutlined />} sx={{ mt: 1 }} onClick={() => setIncident({ type: refs.data?.data.incident_types[0]?.code ?? '', description: '' })}>
                  Reportar incidencia
                </Button>
              ))}
          </DetailSection>

          <DetailSection title="Seguimiento">
            {can('shipments.update') && (
              <Stack direction="row" spacing={1} sx={{ mb: 1.5 }}>
                <TextField size="small" fullWidth label="Agregar novedad" placeholder="Ej. retirado en bodega central" value={note} onChange={(e) => setNote(e.target.value)} />
                <Button variant="outlined" disabled={note.trim().length < 3 || addNote.isPending} onClick={() => addNote.mutate()}>
                  Agregar
                </Button>
              </Stack>
            )}
            <Stack spacing={1.25} component="ol" sx={{ listStyle: 'none', p: 0, m: 0 }}>
              {s.events.map((e, idx) => (
                <Box component="li" key={idx} sx={{ pl: 1.5, borderLeft: `3px solid ${idx === 0 ? tokens.primary : tokens.outlineVariant}` }}>
                  <Typography variant="body2" sx={{ fontWeight: e.status ? 700 : 400 }}>
                    {e.status ? `${e.status.label}. ` : ''}
                    {e.description}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {[formatDateTime(e.occurred_at), e.location, e.user].filter(Boolean).join(', ')}
                  </Typography>
                </Box>
              ))}
            </Stack>
          </DetailSection>
          {s.notes && (
            <DetailSection title="Notas">
              <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                {s.notes}
              </Typography>
            </DetailSection>
          )}
          <DeliverDialog open={delivering} shipment={s} onClose={() => setDelivering(false)} onDone={(d) => (setDelivering(false), set(d, 'Entrega confirmada.'))} />
          <ShipmentDialog open={editing} shipment={s} onClose={() => setEditing(false)} onSaved={(d) => (setEditing(false), d && set(d, 'Despacho actualizado.'))} />
        </>
      )}
    </Drawer>
  )
}

function DeliverDialog({ open, shipment, onClose, onDone }: { open: boolean; shipment: ShipmentDetail; onClose: () => void; onDone: (d: ShipmentDetail) => void }) {
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => logisticsApi.deliver(shipment.public_id, { received_by_name: name.trim(), delivery_notes: notes.trim() || undefined, evidence: file }),
    onSuccess: (d) => {
      setName('')
      setNotes('')
      setFile(null)
      onDone(d.data)
    },
    onError: (e) => setError(errMsg(e)),
  })
  return (
    <FormDialog open={open} title={`Confirmar entrega de ${shipment.number}`} onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Confirmar entrega" submitting={save.isPending} canSubmit={name.trim().length >= 2} error={error}>
      <Stack spacing={2}>
        <TextField label="Nombre de quien recibe" required value={name} onChange={(e) => setName(e.target.value)} />
        <TextField label="Observaciones de la recepción" multiline minRows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        <Box>
          <Button component="label" variant="outlined" startIcon={<AttachFileOutlined />}>
            {file ? 'Cambiar comprobante' : 'Adjuntar comprobante'}
            <input hidden type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </Button>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.5 }}>
            {file ? file.name : 'Guía firmada o foto de la entrega. PDF, JPG o PNG, hasta 10 MB. Opcional.'}
          </Typography>
        </Box>
      </Stack>
    </FormDialog>
  )
}

/** Crear un despacho o editar sus datos (si está cerrado, solo costo real y notas). */
function ShipmentDialog({ open, shipment, onClose, onSaved }: { open: boolean; shipment?: ShipmentDetail; onClose: () => void; onSaved: (d?: ShipmentDetail) => void }) {
  const qc = useQueryClient()
  const { regions } = useCrmReferences()
  const refs = useQuery({ queryKey: ['logistics-refs'], queryFn: logisticsApi.references, staleTime: Infinity, enabled: open })
  const carriers = useQuery({ queryKey: ['carriers'], queryFn: logisticsApi.carriers, enabled: open })
  const orders = useQuery({ queryKey: ['purchase-orders-internal', 'for-shipment'], queryFn: () => procurementApi.orders({ per_page: 100 }), enabled: open && !shipment })
  const closed = shipment ? ['DELIVERED', 'CANCELLED', 'RETURNED'].includes(shipment.status.code) : false
  const blank: ShipmentInput = { order_public_id: '', carrier_public_id: '', service_type: '', origin: '', destination: '', destination_region: null, contact_name: '', contact_phone: '', tracking_code: '', packages: '', weight_kg: '', planned_pickup_on: '', planned_delivery_on: '', estimated_cost: '', actual_cost: '', notes: '' }
  const [f, setF] = useState<ShipmentInput>(blank)
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setError(null)
      setF(
        shipment
          ? {
              carrier_public_id: shipment.carrier?.public_id ?? '',
              service_type: shipment.service_type?.code ?? '',
              origin: shipment.origin,
              destination: shipment.destination,
              destination_region: shipment.destination_region?.code ?? null,
              contact_name: shipment.contact_name ?? '',
              contact_phone: shipment.contact_phone ?? '',
              tracking_code: shipment.tracking_code ?? '',
              packages: shipment.packages ? String(shipment.packages) : '',
              weight_kg: shipment.weight_kg ? String(Number(shipment.weight_kg)) : '',
              planned_pickup_on: shipment.planned_pickup_on ?? '',
              planned_delivery_on: shipment.planned_delivery_on ?? '',
              estimated_cost: shipment.estimated_cost ? String(Number(shipment.estimated_cost)) : '',
              actual_cost: shipment.actual_cost ? String(Number(shipment.actual_cost)) : '',
              notes: shipment.notes ?? '',
            }
          : blank,
      )
    }
  }
  const set = (k: keyof ShipmentInput, v: string | number | null) => setF({ ...f, [k]: v })
  const save = useMutation({
    mutationFn: () => {
      if (shipment) {
        // Al editar, los vacíos se envían como null para poder borrar un dato.
        const body = Object.fromEntries(Object.entries(closed ? { actual_cost: f.actual_cost, notes: f.notes } : f).map(([k, v]) => [k, v === '' ? null : v])) as ShipmentInput
        return logisticsApi.update(shipment.public_id, body)
      }
      return logisticsApi.create(f)
    },
    onSuccess: (d) => {
      void qc.invalidateQueries({ queryKey: ['shipments'] })
      onSaved(d.data)
    },
    onError: (e) => setError(errMsg(e)),
  })
  const pickOrder = (id: string) => {
    const o = orders.data?.data.find((x) => x.public_id === id)
    setF({ ...f, order_public_id: id, planned_delivery_on: f.planned_delivery_on || (o?.expected_delivery_on ?? '') })
  }
  const ok = closed || ((f.origin ?? '').trim().length >= 3 && (f.destination ?? '').trim().length >= 3)
  const activeCarriers = (carriers.data?.data ?? []).filter((c) => c.is_active || c.public_id === f.carrier_public_id)

  return (
    <FormDialog
      open={open}
      title={shipment ? (closed ? `Costo real de ${shipment.number}` : `Editar ${shipment.number}`) : 'Nuevo despacho'}
      onClose={onClose}
      onSubmit={() => save.mutate()}
      submitLabel={shipment ? 'Guardar cambios' : 'Crear despacho'}
      submitting={save.isPending}
      canSubmit={ok}
      error={error}
    >
      {closed ? (
        <Stack spacing={2}>
          <Typography variant="body2" color="text.secondary">
            El despacho está cerrado: solo se puede registrar el costo real y notas.
          </Typography>
          <TextField label="Costo real (CLP)" type="number" value={f.actual_cost} onChange={(e) => set('actual_cost', e.target.value)} />
          <TextField label="Notas" multiline minRows={2} value={f.notes} onChange={(e) => set('notes', e.target.value)} />
        </Stack>
      ) : (
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          {!shipment && (
            <TextField select label="Orden de compra (opcional)" value={f.order_public_id ?? ''} onChange={(e) => pickOrder(e.target.value)} sx={{ gridColumn: '1 / -1' }}>
              <MenuItem value="">Sin orden asociada</MenuItem>
              {(orders.data?.data ?? [])
                .filter((o) => !['CANCELLED', 'DRAFT'].includes(o.status.code))
                .map((o) => (
                  <MenuItem key={o.public_id} value={o.public_id}>
                    {o.number}, {o.supplier.name}
                  </MenuItem>
                ))}
            </TextField>
          )}
          <TextField label="Origen" required placeholder="Dirección de retiro" value={f.origin} onChange={(e) => set('origin', e.target.value)} />
          <TextField label="Destino" required placeholder="Dirección de entrega" value={f.destination} onChange={(e) => set('destination', e.target.value)} />
          <TextField select label="Región de destino" value={f.destination_region ?? ''} onChange={(e) => set('destination_region', e.target.value === '' ? null : Number(e.target.value))}>
            <MenuItem value="">Sin región</MenuItem>
            {(regions ?? []).map((r) => (
              <MenuItem key={r.code} value={r.code}>
                {r.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField label="Contacto en destino" value={f.contact_name} onChange={(e) => set('contact_name', e.target.value)} />
          <TextField label="Teléfono de contacto" value={f.contact_phone} onChange={(e) => set('contact_phone', e.target.value)} />
          <TextField select label="Transportista" value={f.carrier_public_id ?? ''} onChange={(e) => set('carrier_public_id', e.target.value)} helperText={activeCarriers.length === 0 ? 'Agrega transportistas en la pestaña Transportistas.' : undefined}>
            <MenuItem value="">Por definir</MenuItem>
            {activeCarriers.map((c) => (
              <MenuItem key={c.public_id} value={c.public_id}>
                {c.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField select label="Tipo de servicio" value={f.service_type ?? ''} onChange={(e) => set('service_type', e.target.value)}>
            <MenuItem value="">Por definir</MenuItem>
            {(refs.data?.data.service_types ?? []).map((t) => (
              <MenuItem key={t.code} value={t.code}>
                {t.label}
              </MenuItem>
            ))}
          </TextField>
          <TextField label="Código de seguimiento" value={f.tracking_code} onChange={(e) => set('tracking_code', e.target.value)} />
          <TextField label="Retiro planificado" type="date" value={f.planned_pickup_on} onChange={(e) => set('planned_pickup_on', e.target.value)} InputLabelProps={{ shrink: true }} />
          <TextField label="Entrega planificada" type="date" value={f.planned_delivery_on} onChange={(e) => set('planned_delivery_on', e.target.value)} InputLabelProps={{ shrink: true }} />
          <TextField label="Bultos" type="number" value={f.packages} onChange={(e) => set('packages', e.target.value)} inputProps={{ min: 1 }} />
          <TextField label="Peso (kg)" type="number" value={f.weight_kg} onChange={(e) => set('weight_kg', e.target.value)} inputProps={{ min: 0, step: 'any' }} />
          <TextField label="Costo estimado (CLP)" type="number" value={f.estimated_cost} onChange={(e) => set('estimated_cost', e.target.value)} />
          <TextField label="Notas" multiline minRows={2} value={f.notes} onChange={(e) => set('notes', e.target.value)} sx={{ gridColumn: '1 / -1' }} />
        </Box>
      )}
    </FormDialog>
  )
}

function CarriersTab({ canUpdate, onToast }: { canUpdate: boolean; onToast: (m: string) => void }) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Carrier | 'new' | null>(null)
  const list = useQuery({ queryKey: ['carriers'], queryFn: logisticsApi.carriers })
  const toggle = useMutation({
    mutationFn: (c: Carrier) => logisticsApi.updateCarrier(c.public_id, { is_active: !c.is_active }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['carriers'] }),
  })
  const rows = list.data?.data ?? []
  return (
    <>
      {canUpdate && (
        <Button variant="outlined" startIcon={<Add />} onClick={() => setEditing('new')} sx={{ mb: 2 }}>
          Nuevo transportista
        </Button>
      )}
      {list.error && <ErrorState error={list.error} what="los transportistas" />}
      {list.isLoading && <ListSkeleton rows={3} />}
      {!list.isLoading && rows.length === 0 && <EmptyState title="Aún no hay transportistas">Registra los operadores aliados con los que coordinas despachos.</EmptyState>}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Transportista' }, { label: 'Servicios', hideOnMobile: true }, { label: 'Cobertura', hideOnMobile: true }, { label: 'Activo', align: 'right' }]}
          rows={rows.map((c) => ({
            key: c.public_id,
            onClick: canUpdate ? () => setEditing(c) : undefined,
            label: `Editar ${c.name}`,
            cells: [
              <>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {c.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {[c.rut, c.contact_name, c.contact_phone].filter(Boolean).join(', ')}
                </Typography>
              </>,
              c.service_types.map((s) => s.label).join(', ') || '—',
              c.coverage_regions.length === 16 ? 'Todo Chile' : c.coverage_regions.map((r) => r.name).join(', ') || '—',
              <Switch checked={c.is_active} disabled={!canUpdate || toggle.isPending} onClick={(e) => e.stopPropagation()} onChange={() => toggle.mutate(c)} inputProps={{ 'aria-label': `${c.name} activo` }} />,
            ],
          }))}
        />
      )}
      <CarrierDialog carrier={editing} onClose={() => setEditing(null)} onSaved={(m) => (setEditing(null), onToast(m))} />
    </>
  )
}

function CarrierDialog({ carrier, onClose, onSaved }: { carrier: Carrier | 'new' | null; onClose: () => void; onSaved: (m: string) => void }) {
  const qc = useQueryClient()
  const { regions } = useCrmReferences()
  const refs = useQuery({ queryKey: ['logistics-refs'], queryFn: logisticsApi.references, staleTime: Infinity, enabled: carrier !== null })
  const blank = { name: '', rut: '', contact_name: '', contact_phone: '', contact_email: '', tracking_url_template: '', notes: '', service_types: [] as string[], coverage_regions: [] as number[] }
  const [f, setF] = useState(blank)
  const [error, setError] = useState<string | null>(null)
  const [last, setLast] = useState<Carrier | 'new' | null>(null)
  if (carrier !== last) {
    setLast(carrier)
    setError(null)
    if (carrier === 'new') setF(blank)
    else if (carrier)
      setF({
        name: carrier.name,
        rut: carrier.rut ?? '',
        contact_name: carrier.contact_name ?? '',
        contact_phone: carrier.contact_phone ?? '',
        contact_email: carrier.contact_email ?? '',
        tracking_url_template: carrier.tracking_url_template ?? '',
        notes: carrier.notes ?? '',
        service_types: carrier.service_types.map((s) => s.code),
        coverage_regions: carrier.coverage_regions.map((r) => r.code),
      })
  }
  const isNew = carrier === 'new'
  const save = useMutation({
    mutationFn: () => {
      const body = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]))
      return isNew ? logisticsApi.createCarrier(body) : logisticsApi.updateCarrier((carrier as Carrier).public_id, body)
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['carriers'] })
      onSaved(isNew ? 'Transportista creado.' : 'Transportista actualizado.')
    },
    onError: (e) => setError(errMsg(e)),
  })
  const toggleIn = <T,>(arr: T[], v: T) => (arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v])
  const badTemplate = f.tracking_url_template !== '' && !/^https:\/\/\S+\{code\}\S*$/.test(f.tracking_url_template)

  return (
    <FormDialog open={carrier !== null} title={isNew ? 'Nuevo transportista' : 'Editar transportista'} onClose={onClose} onSubmit={() => save.mutate()} submitLabel={isNew ? 'Crear transportista' : 'Guardar cambios'} submitting={save.isPending} canSubmit={f.name.trim().length >= 2 && !badTemplate} error={error}>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField label="Nombre" required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        <TextField label="RUT" placeholder="76.123.456-7" value={f.rut} onChange={(e) => setF({ ...f, rut: e.target.value })} />
        <TextField label="Contacto" value={f.contact_name} onChange={(e) => setF({ ...f, contact_name: e.target.value })} />
        <TextField label="Teléfono" value={f.contact_phone} onChange={(e) => setF({ ...f, contact_phone: e.target.value })} />
        <TextField label="Correo" type="email" value={f.contact_email} onChange={(e) => setF({ ...f, contact_email: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
        <TextField
          label="Enlace de seguimiento"
          placeholder="https://transportista.cl/seguimiento?n={code}"
          value={f.tracking_url_template}
          onChange={(e) => setF({ ...f, tracking_url_template: e.target.value })}
          error={badTemplate}
          helperText={badTemplate ? 'Debe empezar con https:// e incluir {code}' : '{code} se reemplaza por el código de seguimiento de cada despacho.'}
          sx={{ gridColumn: '1 / -1' }}
        />
      </Box>
      <Typography variant="subtitle2" sx={{ mt: 2, mb: 1 }}>
        Servicios
      </Typography>
      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
        {(refs.data?.data.service_types ?? []).map((t) => (
          <Chip key={t.code} label={t.label} color={f.service_types.includes(t.code) ? 'primary' : 'default'} variant={f.service_types.includes(t.code) ? 'filled' : 'outlined'} onClick={() => setF({ ...f, service_types: toggleIn(f.service_types, t.code) })} />
        ))}
      </Stack>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 2, mb: 1 }}>
        <Typography variant="subtitle2">Cobertura</Typography>
        <Button size="small" onClick={() => setF({ ...f, coverage_regions: f.coverage_regions.length === 16 ? [] : (regions ?? []).map((r) => r.code) })}>
          {f.coverage_regions.length === 16 ? 'Quitar todas' : 'Todo Chile'}
        </Button>
      </Stack>
      <Stack direction="row" spacing={0.75} sx={{ flexWrap: 'wrap', rowGap: 0.75 }}>
        {(regions ?? []).map((r) => (
          <Chip key={r.code} size="small" label={r.name} color={f.coverage_regions.includes(r.code) ? 'primary' : 'default'} variant={f.coverage_regions.includes(r.code) ? 'filled' : 'outlined'} onClick={() => setF({ ...f, coverage_regions: toggleIn(f.coverage_regions, r.code) })} />
        ))}
      </Stack>
      <TextField label="Notas" multiline minRows={2} fullWidth value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} sx={{ mt: 2 }} />
    </FormDialog>
  )
}
