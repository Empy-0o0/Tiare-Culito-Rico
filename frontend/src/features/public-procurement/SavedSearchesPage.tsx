import Alert from '@mui/material/Alert'
import Autocomplete from '@mui/material/Autocomplete'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import IconButton from '@mui/material/IconButton'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import TextField from '@mui/material/TextField'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import EditOutlined from '@mui/icons-material/EditOutlined'
import NotificationsActiveOutlined from '@mui/icons-material/NotificationsActiveOutlined'
import NotificationsOffOutlined from '@mui/icons-material/NotificationsOffOutlined'
import Refresh from '@mui/icons-material/Refresh'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { DataOriginTag } from '../../components/DataBadges'
import { FilterBar, SelectField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { publicProcurementApi } from '../../services/publicProcurement'
import { savedSearchesApi, type OpportunityMatch, type RunResult, type SavedSearch, type SearchCriteria } from '../../services/savedSearches'
import { closingInfo, formatDateTime, formatMoney } from '../../utils/format'
import { CompraAgilDetailDrawer } from './CompraAgilDetailDrawer'
import { TenderDetailDrawer } from './TenderDetailDrawer'

const runMessage = (r?: RunResult) =>
  !r
    ? 'Búsqueda guardada.'
    : r.catalog_empty
      ? 'Tu catálogo no tiene productos activos: agrégalos en Catálogo para usar este criterio.'
      : r.new === 0
        ? 'Sin coincidencias nuevas.'
        : r.new === 1
          ? '1 coincidencia nueva.'
          : `${r.new} coincidencias nuevas.`

function criteriaText(s: SavedSearch): string {
  const c = s.criteria
  const types = c.source_types ?? ['compra_agil']
  const parts = [
    types.length === 2 ? 'Compra Ágil y licitaciones' : types[0] === 'licitacion' ? 'Licitaciones' : 'Compra Ágil',
    c.q ? `"${c.q}"` : null,
    s.regions.length ? s.regions.map((r) => r.name).join(', ') : null,
    c.min_amount !== undefined || c.max_amount !== undefined
      ? `monto ${c.min_amount !== undefined ? `desde ${formatMoney(c.min_amount)}` : ''}${c.max_amount !== undefined ? ` hasta ${formatMoney(c.max_amount)}` : ''}`
      : null,
    c.buyer_rut ? `organismo ${c.buyer_rut}` : null,
    c.use_catalog ? 'productos de tu catálogo' : null,
    c.open_only === false ? 'incluye cerradas (60 días)' : 'solo abiertas',
  ]
  return parts.filter(Boolean).join(', ')
}

/** Búsquedas guardadas de Compra Ágil, sus coincidencias y alertas. Trabaja sobre datos ya sincronizados. */
export function SavedSearchesPage() {
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const selectedSearch = params.get('busqueda') ?? ''
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<SavedSearch | 'new' | null>(null)
  const [ficha, setFicha] = useState<{ code: string; type: string } | null>(null)
  const [toast, setToast] = useState<string | null>(null)

  const searches = useQuery({
    queryKey: ['saved-searches'],
    queryFn: savedSearchesApi.list,
  })
  const matches = useQuery({
    queryKey: ['matches', selectedSearch, status, page],
    queryFn: () =>
      savedSearchesApi.matches({
        search: selectedSearch || undefined,
        status: status || undefined,
        page,
        per_page: 20,
      }),
    placeholderData: keepPreviousData,
  })
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['saved-searches'] })
    void qc.invalidateQueries({ queryKey: ['matches'] })
  }
  const run = useMutation({
    mutationFn: savedSearchesApi.run,
    onSuccess: (d) => (refresh(), setToast(runMessage(d.meta.run))),
  })
  const toggle = useMutation({
    mutationFn: ({ s, field }: { s: SavedSearch; field: 'alerts_enabled' | 'is_active' }) => savedSearchesApi.update(s.public_id, { [field]: !s[field] }),
    onSuccess: refresh,
  })
  const setMatch = useMutation({
    mutationFn: ({ m, to }: { m: OpportunityMatch; to: OpportunityMatch['status']['code'] }) => savedSearchesApi.setMatchStatus(m.public_id, to),
    onSuccess: refresh,
  })
  const select = (id: string) => {
    const next = new URLSearchParams(params)
    if (id) next.set('busqueda', id)
    else next.delete('busqueda')
    setParams(next, { replace: true })
    setPage(1)
  }
  const openFicha = (m: OpportunityMatch) => {
    setFicha({ code: m.opportunity.code, type: m.opportunity.source_type })
    if (m.status.code === 'NEW') setMatch.mutate({ m, to: 'SEEN' })
  }

  const list = searches.data?.data ?? []
  const rows = matches.data?.data ?? []
  const counts = matches.data?.meta.counts ?? {}

  return (
    <>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={1} sx={{ mb: 2 }}>
        <Typography color="text.secondary" sx={{ maxWidth: '70ch' }}>
          Guarda criterios y TIARE revisa las Compras Ágiles sincronizadas cada 15 minutos. Te avisa de coincidencias nuevas y de las que cierran en menos de 24 horas.
        </Typography>
        <Button variant="contained" startIcon={<Add />} onClick={() => setEditing('new')} sx={{ flexShrink: 0 }}>
          Nueva búsqueda
        </Button>
      </Stack>

      {searches.error && <ErrorState error={searches.error} what="tus búsquedas" />}
      {searches.isLoading && <ListSkeleton rows={2} />}
      {!searches.isLoading && list.length === 0 && (
        <EmptyState title="Aún no tienes búsquedas guardadas">Crea una con palabras clave, regiones o los productos de tu catálogo para recibir alertas.</EmptyState>
      )}
      <Box
        sx={{
          display: 'grid',
          gap: 1.5,
          gridTemplateColumns: { xs: '1fr', md: '1fr 1fr', xl: '1fr 1fr 1fr' },
          mb: 3,
        }}
      >
        {list.map((s) => {
          const active = selectedSearch === s.public_id
          return (
            <Card
              key={s.public_id}
              variant="outlined"
              sx={{
                p: 2,
                cursor: 'pointer',
                opacity: s.is_active ? 1 : 0.6,
                borderColor: active ? tokens.primary : tokens.outlineVariant,
                borderWidth: active ? 2 : 1,
              }}
              onClick={() => select(active ? '' : s.public_id)}
              aria-pressed={active}
            >
              <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
                <Box sx={{ minWidth: 0 }}>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                    {s.name}
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    {criteriaText(s)}
                  </Typography>
                </Box>
                {(s.new_count ?? 0) > 0 && <Chip size="small" color="primary" label={`${s.new_count} nuevas`} />}
              </Stack>
              <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mt: 1 }} onClick={(e) => e.stopPropagation()}>
                <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
                  {s.is_active ? `${s.matches_count ?? 0} coincidencias, revisada ${formatDateTime(s.last_run_at)}` : 'Desactivada'}
                </Typography>
                <Tooltip title={s.alerts_enabled ? 'Alertas activadas' : 'Alertas desactivadas'}>
                  <IconButton
                    size="small"
                    aria-label={s.alerts_enabled ? `Desactivar alertas de ${s.name}` : `Activar alertas de ${s.name}`}
                    onClick={() => toggle.mutate({ s, field: 'alerts_enabled' })}
                  >
                    {s.alerts_enabled ? <NotificationsActiveOutlined fontSize="small" color="primary" /> : <NotificationsOffOutlined fontSize="small" />}
                  </IconButton>
                </Tooltip>
                {s.is_active && (
                  <Tooltip title="Buscar ahora">
                    <IconButton size="small" aria-label={`Buscar ahora ${s.name}`} disabled={run.isPending} onClick={() => run.mutate(s.public_id)}>
                      <Refresh fontSize="small" />
                    </IconButton>
                  </Tooltip>
                )}
                <Tooltip title="Editar">
                  <IconButton size="small" aria-label={`Editar ${s.name}`} onClick={() => setEditing(s)}>
                    <EditOutlined fontSize="small" />
                  </IconButton>
                </Tooltip>
              </Stack>
            </Card>
          )
        })}
      </Box>

      {list.length > 0 && (
        <>
          <Typography variant="h6" sx={{ mb: 1 }}>
            Coincidencias
            {selectedSearch ? ` de "${list.find((s) => s.public_id === selectedSearch)?.name ?? ''}"` : ''}
          </Typography>
          <FilterBar count={matches.data?.meta.pagination ? `${matches.data.meta.pagination.total} coincidencias` : undefined}>
            <SelectField
              label="Estado"
              value={status}
              onChange={(v) => (setStatus(v), setPage(1))}
              allLabel="Nuevas y vistas"
              options={[
                { value: 'NEW', label: `Nuevas (${counts.NEW ?? 0})` },
                { value: 'SEEN', label: `Vistas (${counts.SEEN ?? 0})` },
                {
                  value: 'DISMISSED',
                  label: `Descartadas (${counts.DISMISSED ?? 0})`,
                },
              ]}
            />
          </FilterBar>
          {matches.error && <ErrorState error={matches.error} what="las coincidencias" />}
          {!matches.isLoading && rows.length === 0 && <EmptyState title="Sin coincidencias por ahora">Cuando se sincronicen Compras Ágiles que calcen, aparecerán aquí.</EmptyState>}
          <Stack spacing={1.25}>
            {rows.map((m) => (
              <MatchCard
                key={m.public_id}
                m={m}
                onOpen={() => openFicha(m)}
                onDismiss={() =>
                  setMatch.mutate({
                    m,
                    to: m.status.code === 'DISMISSED' ? 'SEEN' : 'DISMISSED',
                  })
                }
              />
            ))}
          </Stack>
          {matches.data?.meta.pagination && <Pager page={matches.data.meta.pagination.page} lastPage={matches.data.meta.pagination.last_page} onChange={setPage} />}
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 2 }}>
            <DataOriginTag origin="official" />
            <Typography variant="caption" color="text.secondary">
              Oportunidades: Dirección ChileCompra / Mercado Público. El calce es preliminar y lo calcula TIARE por palabras.
            </Typography>
          </Stack>
        </>
      )}

      <SearchDialog
        search={editing}
        onClose={() => setEditing(null)}
        onSaved={(msg) => {
          setEditing(null)
          refresh()
          setToast(msg)
        }}
      />
      <CompraAgilDetailDrawer code={ficha?.type === 'compra_agil' ? ficha.code : null} onClose={() => setFicha(null)} />
      <TenderDetailDrawer code={ficha?.type === 'licitacion' ? ficha.code : null} onClose={() => setFicha(null)} />
      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

function MatchCard({ m, onOpen, onDismiss }: { m: OpportunityMatch; onOpen: () => void; onDismiss: () => void }) {
  const o = m.opportunity
  const isTender = o.source_type === 'licitacion'
  const open = isTender ? o.status.code === '5' : o.status.code === 'publicada'
  const closing = open ? closingInfo(o.closes_at) : null
  const why = [
    m.reasons.words?.length ? `contiene ${m.reasons.words.map((w) => `"${w}"`).join(', ')}` : null,
    m.reasons.catalog?.length
      ? `calza con ${m.reasons.catalog
          .flatMap((c) => c.catalog)
          .slice(0, 3)
          .join(', ')}`
      : null,
    m.reasons.region ? `región ${m.reasons.region}` : null,
  ].filter(Boolean)
  return (
    <Card
      variant="outlined"
      sx={{
        p: 2,
        borderLeft: m.status.code === 'NEW' ? `4px solid ${tokens.primary}` : undefined,
      }}
    >
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} justifyContent="space-between">
        <Box sx={{ minWidth: 0 }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.25, flexWrap: 'wrap', rowGap: 0.5 }}>
            <span className="code">{o.code}</span>
            <Chip size="small" variant="outlined" label={isTender ? 'Licitación' : 'Compra Ágil'} />
            {m.status.code === 'NEW' && <Chip size="small" color="primary" label="Nueva" />}
            {closing && (
              <Chip
                size="small"
                label={closing.text}
                sx={
                  closing.tone === 'soon'
                    ? {
                        bgcolor: tokens.tertiaryContainer,
                        color: tokens.urgent,
                      }
                    : undefined
                }
              />
            )}
            {!closing && o.status.label && <Chip size="small" variant="outlined" label={o.status.label} />}
          </Stack>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {o.title}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {[o.buyer, o.region, o.available_amount_clp ? formatMoney(o.available_amount_clp) : null].filter(Boolean).join(', ') ||
              (isTender && !o.has_detail ? 'Organismo, región y monto se completan al abrir la ficha.' : '')}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            Coincide porque {why.length ? why.join('; ') : 'cumple los filtros'}. Búsqueda "{m.search.name}".
          </Typography>
        </Box>
        <Stack direction={{ xs: 'row', sm: 'column' }} spacing={1} sx={{ flexShrink: 0 }}>
          <Button variant="outlined" size="small" onClick={onOpen}>
            Ver ficha
          </Button>
          <Button size="small" onClick={onDismiss}>
            {m.status.code === 'DISMISSED' ? 'Restaurar' : 'Descartar'}
          </Button>
        </Stack>
      </Stack>
    </Card>
  )
}

function SearchDialog({ search, onClose, onSaved }: { search: SavedSearch | 'new' | null; onClose: () => void; onSaved: (msg: string) => void }) {
  const catalogs = useQuery({
    queryKey: ['pp-catalogs'],
    queryFn: publicProcurementApi.catalogs,
    staleTime: Infinity,
    enabled: search !== null,
  })
  const regions = catalogs.data?.data.regions ?? []
  const blank = {
    name: '',
    q: '',
    regions: [] as number[],
    min: '',
    max: '',
    rut: '',
    openOnly: true,
    useCatalog: false,
    alerts: true,
    active: true,
    types: ['compra_agil'] as Array<'compra_agil' | 'licitacion'>,
  }
  const [f, setF] = useState(blank)
  const [error, setError] = useState<string | null>(null)
  const [last, setLast] = useState<SavedSearch | 'new' | null>(null)
  if (search !== last) {
    setLast(search)
    setError(null)
    if (search === 'new') setF(blank)
    else if (search)
      setF({
        name: search.name,
        q: search.criteria.q ?? '',
        regions: search.criteria.regions ?? [],
        min: search.criteria.min_amount !== undefined ? String(search.criteria.min_amount) : '',
        max: search.criteria.max_amount !== undefined ? String(search.criteria.max_amount) : '',
        rut: search.criteria.buyer_rut ?? '',
        openOnly: search.criteria.open_only !== false,
        useCatalog: Boolean(search.criteria.use_catalog),
        alerts: search.alerts_enabled,
        active: search.is_active,
        types: search.criteria.source_types ?? ['compra_agil'],
      })
  }
  const isNew = search === 'new'
  const criteria = (): SearchCriteria => ({
    q: f.q.trim() || undefined,
    regions: f.regions.length ? f.regions : undefined,
    min_amount: f.min !== '' ? Number(f.min) : undefined,
    max_amount: f.max !== '' ? Number(f.max) : undefined,
    buyer_rut: f.rut.trim() || undefined,
    open_only: f.openOnly,
    use_catalog: f.useCatalog,
    source_types: f.types,
  })
  const toggleType = (t: 'compra_agil' | 'licitacion') => setF({ ...f, types: f.types.includes(t) ? f.types.filter((x) => x !== t) : [...f.types, t] })
  const save = useMutation({
    mutationFn: () =>
      isNew
        ? savedSearchesApi.create({
            name: f.name.trim(),
            criteria: criteria(),
            alerts_enabled: f.alerts,
          })
        : savedSearchesApi.update((search as SavedSearch).public_id, {
            name: f.name.trim(),
            criteria: criteria(),
            alerts_enabled: f.alerts,
            is_active: f.active,
          }),
    onSuccess: (d) => onSaved(runMessage(d.meta.run)),
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible guardar la búsqueda.'),
  })
  const hasCriterion = f.q.trim() || f.regions.length || f.min || f.max || f.rut.trim() || f.useCatalog
  const badRange = f.min !== '' && f.max !== '' && Number(f.max) < Number(f.min)

  return (
    <FormDialog
      open={search !== null}
      title={isNew ? 'Nueva búsqueda guardada' : 'Editar búsqueda'}
      onClose={onClose}
      onSubmit={() => save.mutate()}
      submitLabel={isNew ? 'Guardar y buscar' : 'Guardar cambios'}
      submitting={save.isPending}
      canSubmit={f.name.trim().length >= 2 && Boolean(hasCriterion) && !badRange && f.types.length > 0}
      error={error}
    >
      <Typography variant="subtitle2" sx={{ mb: 1 }}>
        Qué procesos revisar
      </Typography>
      <Stack direction="row" spacing={1} sx={{ mb: 0.5 }}>
        {(
          [
            ['compra_agil', 'Compra Ágil'],
            ['licitacion', 'Licitaciones'],
          ] as const
        ).map(([t, label]) => (
          <Chip key={t} label={label} color={f.types.includes(t) ? 'primary' : 'default'} variant={f.types.includes(t) ? 'filled' : 'outlined'} onClick={() => toggleType(t)} aria-pressed={f.types.includes(t)} />
        ))}
      </Stack>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 2 }}>
        {f.types.length === 0
          ? 'Elige al menos un tipo de proceso.'
          : f.types.includes('licitacion')
            ? 'Las licitaciones abiertas se revisan cada pocas horas. Su región, monto y productos se conocen al traer el detalle desde Mercado Público.'
            : 'Las Compras Ágiles se revisan con cada sincronización.'}
      </Typography>
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' },
        }}
      >
        <TextField label="Nombre" required placeholder="Aseo en Biobío" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
        <TextField
          label="Palabras clave"
          placeholder="detergente guantes"
          value={f.q}
          onChange={(e) => setF({ ...f, q: e.target.value })}
          helperText="Todas deben aparecer en el nombre, la descripción, el organismo o los productos."
          sx={{ gridColumn: '1 / -1' }}
        />
        <Autocomplete
          multiple
          options={regions.map((r) => r.code)}
          getOptionLabel={(c) => regions.find((r) => r.code === c)?.name ?? String(c)}
          value={f.regions}
          onChange={(_, v) => setF({ ...f, regions: v })}
          renderInput={(p) => <TextField {...p} label="Regiones" placeholder={f.regions.length ? '' : 'Todas'} />}
          sx={{ gridColumn: '1 / -1' }}
        />
        <TextField label="Monto disponible desde (CLP)" type="number" value={f.min} onChange={(e) => setF({ ...f, min: e.target.value })} inputProps={{ min: 0 }} />
        <TextField
          label="Hasta (CLP)"
          type="number"
          value={f.max}
          onChange={(e) => setF({ ...f, max: e.target.value })}
          inputProps={{ min: 0 }}
          error={badRange}
          helperText={badRange ? 'Menor que el mínimo' : undefined}
        />
        <TextField label="RUT del organismo" placeholder="69.070.300-9" value={f.rut} onChange={(e) => setF({ ...f, rut: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
      </Box>
      <Stack sx={{ mt: 1.5 }}>
        <FormControlLabel control={<Switch checked={f.useCatalog} onChange={(e) => setF({ ...f, useCatalog: e.target.checked })} />} label="Solo las que calzan con productos de mi catálogo" />
        <FormControlLabel control={<Switch checked={f.openOnly} onChange={(e) => setF({ ...f, openOnly: e.target.checked })} />} label="Solo abiertas a cotización" />
        <FormControlLabel control={<Switch checked={f.alerts} onChange={(e) => setF({ ...f, alerts: e.target.checked })} />} label="Avisarme de coincidencias nuevas y cierres próximos" />
        {!isNew && <FormControlLabel control={<Switch checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} />} label="Búsqueda activa" />}
      </Stack>
      {!hasCriterion && (
        <Alert severity="info" sx={{ mt: 1 }}>
          Indica al menos un criterio: palabras, región, monto, organismo o tu catálogo.
        </Alert>
      )}
    </FormDialog>
  )
}
