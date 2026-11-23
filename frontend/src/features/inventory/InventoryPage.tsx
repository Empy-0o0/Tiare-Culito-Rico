import Alert from '@mui/material/Alert'
import Autocomplete from '@mui/material/Autocomplete'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import LinearProgress from '@mui/material/LinearProgress'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import Switch from '@mui/material/Switch'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import SwapHorizOutlined from '@mui/icons-material/SwapHorizOutlined'
import TuneOutlined from '@mui/icons-material/TuneOutlined'
import WarningAmberOutlined from '@mui/icons-material/WarningAmberOutlined'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import { inventoryApi, type StockItem, type Warehouse } from '../../services/logistics'
import type { ProductDto } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDateTime, formatMoney } from '../../utils/format'
import { qty } from '../procurement/status'
import { useCrmReferences } from '../crm/useCrmReferences'

const errMsg = (e: unknown) => (e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')

/** Inventario: stock por bodega con reposición, movimientos y bodegas. */
export function InventoryPage() {
  const { can } = useAuth()
  const manage = can('inventory.manage')
  const [tab, setTab] = useState<'stock' | 'movements' | 'warehouses'>('stock')
  const [moveOpen, setMoveOpen] = useState<{ product?: string; warehouse?: string } | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const warehouses = useQuery({ queryKey: ['warehouses'], queryFn: inventoryApi.warehouses })
  const noWarehouses = warehouses.data && warehouses.data.data.length === 0

  return (
    <>
      <PageHeader
        title="Inventario"
        subtitle="Stock por bodega, puntos de reposición y movimientos con trazabilidad."
        actions={
          manage && !noWarehouses ? (
            <Button variant="contained" startIcon={<SwapHorizOutlined />} onClick={() => setMoveOpen({})}>
              Registrar movimiento
            </Button>
          ) : undefined
        }
      />
      <Box sx={{ borderBottom: `1px solid ${tokens.outlineVariant}`, mb: 2.5 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)}>
          <Tab value="stock" label="Stock" />
          <Tab value="movements" label="Movimientos" />
          <Tab value="warehouses" label="Bodegas" />
        </Tabs>
      </Box>
      {noWarehouses && tab !== 'warehouses' && (
        <Alert severity="info" sx={{ mb: 2 }} action={<Button color="inherit" onClick={() => setTab('warehouses')}>Crear bodega</Button>}>
          Para llevar inventario, primero crea al menos una bodega.
        </Alert>
      )}
      {tab === 'stock' && <StockTab manage={manage} warehouses={warehouses.data?.data ?? []} onMove={(p, w) => setMoveOpen({ product: p, warehouse: w })} onToast={setToast} />}
      {tab === 'movements' && <MovementsTab warehouses={warehouses.data?.data ?? []} />}
      {tab === 'warehouses' && <WarehousesTab manage={manage} warehouses={warehouses.data?.data ?? []} loading={warehouses.isLoading} onToast={setToast} />}

      <MovementDialog
        open={moveOpen !== null}
        preset={moveOpen ?? {}}
        warehouses={(warehouses.data?.data ?? []).filter((w) => w.is_active)}
        onClose={() => setMoveOpen(null)}
        onSaved={(msg) => {
          setMoveOpen(null)
          setToast(msg)
        }}
      />
      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}

function StockTab({ manage, warehouses, onMove, onToast }: { manage: boolean; warehouses: Warehouse[]; onMove: (p: string, w: string) => void; onToast: (m: string) => void }) {
  const [q, setQ] = useState('')
  const [warehouse, setWarehouse] = useState('')
  const [lowOnly, setLowOnly] = useState(false)
  const [page, setPage] = useState(1)
  const [levels, setLevels] = useState<StockItem | null>(null)
  const debouncedQ = useDebounced(q)
  const list = useQuery({
    queryKey: ['stock', debouncedQ, warehouse, lowOnly, page],
    queryFn: () => inventoryApi.stock({ q: debouncedQ || undefined, warehouse_public_id: warehouse || undefined, low_only: lowOnly, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })
  const rows = list.data?.data ?? []
  const low = list.data?.meta.low_stock_count ?? 0

  return (
    <>
      {low > 0 && !lowOnly && (
        <Alert severity="warning" icon={<WarningAmberOutlined />} sx={{ mb: 2 }} action={<Button color="inherit" onClick={() => setLowOnly(true)}>Ver</Button>}>
          {low === 1 ? 'Un producto está bajo su stock mínimo.' : `${low} productos están bajo su stock mínimo.`}
        </Alert>
      )}
      <FilterBar count={list.data?.meta.pagination ? `${list.data.meta.pagination.total} registros` : undefined}>
        <SearchField value={q} onChange={(v) => (setQ(v), setPage(1))} placeholder="Producto o SKU" label="Buscar en stock" />
        <SelectField label="Bodega" value={warehouse} onChange={(v) => (setWarehouse(v), setPage(1))} allLabel="Todas" options={warehouses.map((w) => ({ value: w.public_id, label: w.name }))} />
        <FormControlLabel control={<Switch checked={lowOnly} onChange={(e) => (setLowOnly(e.target.checked), setPage(1))} />} label="Solo bajo mínimo" />
      </FilterBar>
      {list.error && <ErrorState error={list.error} what="el stock" />}
      {list.isLoading && <ListSkeleton rows={4} />}
      {!list.isLoading && rows.length === 0 && (
        <EmptyState title={q || warehouse || lowOnly ? 'Nada coincide con los filtros' : 'Aún no hay stock registrado'}>
          {q || warehouse || lowOnly ? 'Prueba con otros filtros.' : 'Registra una entrada o ingresa a bodega lo recibido en una orden de compra.'}
        </EmptyState>
      )}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Producto' }, { label: 'Bodega', hideOnMobile: true }, { label: 'Disponible', align: 'right' }, { label: 'Mín. / máx.', align: 'right', hideOnMobile: true }, { label: '', align: 'right' }]}
          rows={rows.map((i) => ({
            key: `${i.warehouse.public_id}-${i.product.public_id}`,
            cells: [
              <>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {i.product.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {[i.product.sku, i.location ? `ubicación ${i.location}` : null].filter(Boolean).join(', ')}
                </Typography>
              </>,
              i.warehouse.name,
              <Stack alignItems="flex-end">
                <Typography className="num" sx={{ fontWeight: 700, color: i.low_stock ? tokens.urgent : undefined }}>
                  {qty(i.quantity_on_hand)} {i.product.unit}
                </Typography>
                {i.reorder_suggestion && <Chip size="small" label={`Reponer ${qty(i.reorder_suggestion)}`} sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.urgent, height: 20 }} />}
              </Stack>,
              <span className="num">{i.min_quantity || i.max_quantity ? `${qty(i.min_quantity)} / ${qty(i.max_quantity)}` : '—'}</span>,
              manage ? (
                <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                  <Button size="small" startIcon={<SwapHorizOutlined />} onClick={() => onMove(i.product.public_id, i.warehouse.public_id)}>
                    Mover
                  </Button>
                  <Button size="small" startIcon={<TuneOutlined />} onClick={() => setLevels(i)}>
                    Niveles
                  </Button>
                </Stack>
              ) : null,
            ],
          }))}
        />
      )}
      {list.data?.meta.pagination && <Pager page={list.data.meta.pagination.page} lastPage={list.data.meta.pagination.last_page} onChange={setPage} />}
      <LevelsDialog item={levels} onClose={() => setLevels(null)} onSaved={() => (setLevels(null), onToast('Niveles de reposición guardados.'))} />
    </>
  )
}

function LevelsDialog({ item, onClose, onSaved }: { item: StockItem | null; onClose: () => void; onSaved: () => void }) {
  const qc = useQueryClient()
  const [f, setF] = useState({ min: '', max: '', location: '' })
  const [error, setError] = useState<string | null>(null)
  const [last, setLast] = useState<string | null>(null)
  const key = item ? `${item.warehouse.public_id}-${item.product.public_id}` : null
  if (key !== last) {
    setLast(key)
    if (item) {
      setF({ min: item.min_quantity ? String(Number(item.min_quantity)) : '', max: item.max_quantity ? String(Number(item.max_quantity)) : '', location: item.location ?? '' })
      setError(null)
    }
  }
  const save = useMutation({
    mutationFn: () => inventoryApi.levels(item!.warehouse.public_id, item!.product.public_id, { min_quantity: f.min || null, max_quantity: f.max || null, location: f.location || null }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['stock'] })
      onSaved()
    },
    onError: (e) => setError(errMsg(e)),
  })
  const bad = f.min !== '' && f.max !== '' && Number(f.max) < Number(f.min)
  return (
    <FormDialog open={Boolean(item)} title={`Reposición de ${item?.product.name ?? ''}`} onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Guardar niveles" submitting={save.isPending} canSubmit={!bad} error={error}>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        En {item?.warehouse.name}. Bajo el mínimo se avisa y se sugiere reponer hasta el máximo.
      </Typography>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr 1fr' } }}>
        <TextField label="Stock mínimo" type="number" value={f.min} onChange={(e) => setF({ ...f, min: e.target.value })} inputProps={{ min: 0 }} />
        <TextField label="Stock máximo" type="number" value={f.max} onChange={(e) => setF({ ...f, max: e.target.value })} inputProps={{ min: 0 }} error={bad} helperText={bad ? 'Menor que el mínimo' : undefined} />
        <TextField label="Ubicación" placeholder="Pasillo A-3" value={f.location} onChange={(e) => setF({ ...f, location: e.target.value })} />
      </Box>
    </FormDialog>
  )
}

function MovementsTab({ warehouses }: { warehouses: Warehouse[] }) {
  const [warehouse, setWarehouse] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const refs = useQuery({ queryKey: ['inventory-refs'], queryFn: inventoryApi.references, staleTime: Infinity })
  const list = useQuery({
    queryKey: ['movements', warehouse, type, page],
    queryFn: () => inventoryApi.movements({ warehouse_public_id: warehouse || undefined, type: type || undefined, page, per_page: 30 }),
    placeholderData: keepPreviousData,
  })
  const rows = list.data?.data ?? []
  return (
    <>
      <FilterBar>
        <SelectField label="Bodega" value={warehouse} onChange={(v) => (setWarehouse(v), setPage(1))} allLabel="Todas" options={warehouses.map((w) => ({ value: w.public_id, label: w.name }))} />
        <SelectField label="Tipo" value={type} onChange={(v) => (setType(v), setPage(1))} allLabel="Todos" options={(refs.data?.data.movement_types ?? []).map((t) => ({ value: t.code, label: t.label }))} />
      </FilterBar>
      {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
      {list.error && <ErrorState error={list.error} what="los movimientos" />}
      {!list.isLoading && rows.length === 0 && <EmptyState title="Sin movimientos">Los movimientos aparecen al registrar entradas, salidas, ajustes, traspasos o recepciones.</EmptyState>}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Fecha' }, { label: 'Movimiento' }, { label: 'Producto' }, { label: 'Cantidad', align: 'right' }, { label: 'Saldo', align: 'right', hideOnMobile: true }]}
          rows={rows.map((m) => ({
            key: m.public_id,
            cells: [
              <Typography variant="caption">{formatDateTime(m.created_at)}</Typography>,
              <>
                <Chip size="small" label={m.type.label} color={m.type.direction === 'in' ? 'success' : 'default'} variant="outlined" />
                <Typography variant="caption" color="text.secondary" component="div">
                  {[m.warehouse.name, m.reason, m.lot ? `lote ${m.lot}` : null, m.created_by].filter(Boolean).join(', ')}
                </Typography>
              </>,
              m.product.name,
              <Typography className="num" sx={{ fontWeight: 700, color: m.type.direction === 'in' ? tokens.success : tokens.urgent }}>
                {m.type.direction === 'in' ? '+' : '−'}
                {qty(m.quantity)}
                {m.unit_cost && (
                  <Typography component="span" variant="caption" color="text.secondary" sx={{ display: 'block', fontWeight: 400 }}>
                    {formatMoney(m.unit_cost)} c/u
                  </Typography>
                )}
              </Typography>,
              <span className="num">{qty(m.balance_after)}</span>,
            ],
          }))}
        />
      )}
      {list.data?.meta.pagination && <Pager page={list.data.meta.pagination.page} lastPage={list.data.meta.pagination.last_page} onChange={setPage} />}
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
        Los movimientos no se editan ni se borran: un error se corrige con un ajuste con motivo.
      </Typography>
    </>
  )
}

function WarehousesTab({ manage, warehouses, loading, onToast }: { manage: boolean; warehouses: Warehouse[]; loading: boolean; onToast: (m: string) => void }) {
  const qc = useQueryClient()
  const { regions } = useCrmReferences()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ code: '', name: '', address: '', region_code: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const create = useMutation({
    mutationFn: () => inventoryApi.createWarehouse({ code: f.code.trim(), name: f.name.trim(), address: f.address, region_code: f.region_code ? Number(f.region_code) : null }),
    onSuccess: () => {
      setOpen(false)
      setF({ code: '', name: '', address: '', region_code: '' })
      void qc.invalidateQueries({ queryKey: ['warehouses'] })
      onToast('Bodega creada.')
    },
    onError: (e) => setErrors(e instanceof ApiError ? (e.details ?? {}) : {}),
  })
  const toggle = useMutation({
    mutationFn: (w: Warehouse) => inventoryApi.updateWarehouse(w.public_id, { is_active: !w.is_active }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['warehouses'] }),
  })
  return (
    <>
      {manage && (
        <Button variant="outlined" startIcon={<Add />} onClick={() => (setErrors({}), setOpen(true))} sx={{ mb: 2 }}>
          Nueva bodega
        </Button>
      )}
      {loading && <ListSkeleton rows={2} />}
      {!loading && warehouses.length === 0 && <EmptyState title="Aún no hay bodegas">Crea la bodega o centro de distribución donde guardas la mercadería.</EmptyState>}
      {warehouses.length > 0 && (
        <DataTable
          columns={[{ label: 'Código' }, { label: 'Bodega' }, { label: 'Productos', align: 'right' }, { label: 'Activa', align: 'right' }]}
          rows={warehouses.map((w) => ({
            key: w.public_id,
            cells: [
              <span className="code">{w.code}</span>,
              <>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {w.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {[w.address, w.region?.name].filter(Boolean).join(', ')}
                </Typography>
              </>,
              <span className="num">{w.items_count ?? 0}</span>,
              <Switch checked={w.is_active} disabled={!manage || toggle.isPending} onChange={() => toggle.mutate(w)} inputProps={{ 'aria-label': `Bodega ${w.name} activa` }} />,
            ],
          }))}
        />
      )}
      <FormDialog open={open} title="Nueva bodega" onClose={() => setOpen(false)} onSubmit={() => create.mutate()} submitLabel="Crear bodega" submitting={create.isPending} canSubmit={f.code.trim().length > 0 && f.name.trim().length >= 2}>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 2fr' } }}>
          <TextField label="Código" required placeholder="CENTRAL" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase() })} error={Boolean(fieldError(errors, 'code'))} helperText={fieldError(errors, 'code')} />
          <TextField label="Nombre" required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
          <TextField label="Dirección" value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} sx={{ gridColumn: '1 / -1' }} />
          <TextField select label="Región" value={f.region_code} onChange={(e) => setF({ ...f, region_code: e.target.value })}>
            <MenuItem value="">Sin región</MenuItem>
            {(regions ?? []).map((r) => (
              <MenuItem key={r.code} value={r.code}>
                {r.name}
              </MenuItem>
            ))}
          </TextField>
        </Box>
      </FormDialog>
    </>
  )
}

/** Entrada, salida, ajuste (con motivo) o traspaso entre bodegas. */
function MovementDialog({ open, preset, warehouses, onClose, onSaved }: { open: boolean; preset: { product?: string; warehouse?: string }; warehouses: Warehouse[]; onClose: () => void; onSaved: (m: string) => void }) {
  const qc = useQueryClient()
  const products = useQuery({ queryKey: ['products', 'inventory'], queryFn: () => crmApi.products({ kind: 'PRODUCT', per_page: 100 }), enabled: open })
  const blank = { type: 'IN', product: null as ProductDto | null, warehouse: '', to: '', quantity: '', unit_cost: '', lot: '', expires_on: '', reason: '' }
  const [f, setF] = useState(blank)
  const [error, setError] = useState<string | null>(null)
  const [lastOpen, setLastOpen] = useState(false)
  if (open !== lastOpen) {
    setLastOpen(open)
    if (open) {
      setF({ ...blank, warehouse: preset.warehouse ?? warehouses[0]?.public_id ?? '' })
      setError(null)
    }
  }
  if (open && preset.product && !f.product && products.data) {
    const p = products.data.data.find((x) => x.public_id === preset.product)
    if (p) setF({ ...f, product: p })
  }
  const save = useMutation({
    mutationFn: () =>
      inventoryApi.move({
        type: f.type,
        product_public_id: f.product!.public_id,
        warehouse_public_id: f.warehouse,
        to_warehouse_public_id: f.type === 'TRANSFER' ? f.to : undefined,
        quantity: f.quantity,
        unit_cost: f.type === 'IN' ? f.unit_cost : undefined,
        lot: f.lot,
        expires_on: f.expires_on,
        reason: f.reason,
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['stock'] })
      void qc.invalidateQueries({ queryKey: ['movements'] })
      void qc.invalidateQueries({ queryKey: ['warehouses'] })
      onSaved('Movimiento registrado.')
    },
    onError: (e) => setError(errMsg(e)),
  })
  const isAdjust = f.type.startsWith('ADJUST')
  const ok = f.product && f.warehouse && Number(f.quantity) > 0 && (!isAdjust || f.reason.trim().length >= 3) && (f.type !== 'TRANSFER' || (f.to && f.to !== f.warehouse))

  return (
    <FormDialog open={open} title="Registrar movimiento" onClose={onClose} onSubmit={() => save.mutate()} submitLabel="Registrar movimiento" submitting={save.isPending} canSubmit={Boolean(ok)} error={error}>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField select label="Tipo" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
          <MenuItem value="IN">Entrada</MenuItem>
          <MenuItem value="OUT">Salida (consumo, despacho)</MenuItem>
          <MenuItem value="TRANSFER">Traspaso entre bodegas</MenuItem>
          <MenuItem value="ADJUST_IN">Ajuste positivo</MenuItem>
          <MenuItem value="ADJUST_OUT">Ajuste negativo</MenuItem>
        </TextField>
        <Autocomplete
          options={products.data?.data ?? []}
          getOptionLabel={(p) => `${p.name}${p.sku ? ` (${p.sku})` : ''}`}
          value={f.product}
          onChange={(_, v) => setF({ ...f, product: v })}
          renderInput={(p) => <TextField {...p} label="Producto" required />}
          noOptionsText="No hay productos. Créalos en Catálogo."
        />
        <TextField select label={f.type === 'TRANSFER' ? 'Desde bodega' : 'Bodega'} value={f.warehouse} onChange={(e) => setF({ ...f, warehouse: e.target.value })}>
          {warehouses.map((w) => (
            <MenuItem key={w.public_id} value={w.public_id}>
              {w.name}
            </MenuItem>
          ))}
        </TextField>
        {f.type === 'TRANSFER' && (
          <TextField select label="Hacia bodega" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} error={Boolean(f.to) && f.to === f.warehouse}>
            {warehouses
              .filter((w) => w.public_id !== f.warehouse)
              .map((w) => (
                <MenuItem key={w.public_id} value={w.public_id}>
                  {w.name}
                </MenuItem>
              ))}
          </TextField>
        )}
        <TextField label="Cantidad" type="number" required value={f.quantity} onChange={(e) => setF({ ...f, quantity: e.target.value })} inputProps={{ min: 0, step: 'any' }} />
        {f.type === 'IN' && (
          <>
            <TextField label="Costo unitario (CLP)" type="number" value={f.unit_cost} onChange={(e) => setF({ ...f, unit_cost: e.target.value })} />
            <TextField label="Lote" value={f.lot} onChange={(e) => setF({ ...f, lot: e.target.value })} />
            <TextField label="Vencimiento" type="date" value={f.expires_on} onChange={(e) => setF({ ...f, expires_on: e.target.value })} InputLabelProps={{ shrink: true }} />
          </>
        )}
        <TextField
          label={isAdjust ? 'Motivo del ajuste' : 'Motivo o referencia (opcional)'}
          required={isAdjust}
          value={f.reason}
          onChange={(e) => setF({ ...f, reason: e.target.value })}
          helperText={isAdjust ? 'Ej. conteo físico, merma, error de registro' : undefined}
          sx={{ gridColumn: '1 / -1' }}
        />
      </Box>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
        No se permite dejar stock negativo. Para ingresar lo recibido en una orden de compra, usa "Registrar recepción" en la orden y elige la bodega.
      </Typography>
    </FormDialog>
  )
}
