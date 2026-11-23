import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import FormControlLabel from '@mui/material/FormControlLabel'
import IconButton from '@mui/material/IconButton'
import LinearProgress from '@mui/material/LinearProgress'
import List from '@mui/material/List'
import ListItemButton from '@mui/material/ListItemButton'
import ListItemText from '@mui/material/ListItemText'
import MenuItem from '@mui/material/MenuItem'
import Snackbar from '@mui/material/Snackbar'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Switch from '@mui/material/Switch'
import ToggleButton from '@mui/material/ToggleButton'
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import CategoryOutlined from '@mui/icons-material/CategoryOutlined'
import HandymanOutlined from '@mui/icons-material/HandymanOutlined'
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { StatusBadge } from '../../components/DataBadges'
import { DataTable } from '../../components/DataTable'
import { FilterBar, SearchField, SelectField } from '../../components/FilterBar'
import { FormDialog } from '../../components/FormDialog'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { useDebounced } from '../../hooks/useDebounced'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import type { ProductDto } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { FormGrid } from './OrganizationFields'
import { TenantDataBanner } from './TenantDataBanner'
import { useCrmReferences } from './useCrmReferences'

interface ProductForm {
  name: string
  kind: string
  unit_code: string
  sku: string
  brand: string
  category_public_id: string
  public_catalog_code: string
}

const emptyForm: ProductForm = { name: '', kind: 'PRODUCT', unit_code: 'EA', sku: '', brand: '', category_public_id: '', public_catalog_code: '' }

/** Catálogo de productos y servicios normalizados, con unidad de medida y categoría. */
export function CatalogPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const { references } = useCrmReferences()
  const [q, setQ] = useState('')
  const [kind, setKind] = useState('')
  const [category, setCategory] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<ProductForm>(emptyForm)
  const [editing, setEditing] = useState<ProductDto | null>(null)
  const [active, setActive] = useState(true)
  const [newCategory, setNewCategory] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const debouncedQ = useDebounced(q)

  const categories = useQuery({ queryKey: ['categories'], queryFn: crmApi.categories })
  const list = useQuery({
    queryKey: ['products', debouncedQ, kind, category, page],
    queryFn: () => crmApi.products({ q: debouncedQ.trim() || undefined, kind: kind || undefined, category_public_id: category || undefined, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })

  const createProduct = useMutation({
    mutationFn: () => {
      const body = {
        name: form.name,
        kind: form.kind,
        unit_code: form.unit_code,
        sku: form.sku || undefined,
        brand: form.brand || undefined,
        category_public_id: form.category_public_id || null,
        public_catalog_code: form.public_catalog_code || undefined,
      }
      // En edición, los campos vaciados se envían como null para borrarlos.
      return editing
        ? crmApi.updateProduct(editing.public_id, { ...body, sku: form.sku || null, brand: form.brand || null, public_catalog_code: form.public_catalog_code || null, is_active: active })
        : crmApi.createProduct(body)
    },
    onSuccess: (r) => {
      setOpen(false)
      setForm(emptyForm)
      setToast(editing ? `Cambios guardados en "${r.data.name}".` : `"${r.data.name}" agregado al catálogo.`)
      setEditing(null)
      void qc.invalidateQueries({ queryKey: ['products'] })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? (e.details ?? {}) : {})
      setMsg(e instanceof ApiError ? e.message : 'No fue posible guardar.')
    },
  })

  const createCategory = useMutation({
    mutationFn: () => crmApi.createCategory(newCategory.trim()),
    onSuccess: (r) => {
      setNewCategory('')
      setToast(`Categoría "${r.data.name}" creada.`)
      void qc.invalidateQueries({ queryKey: ['categories'] })
    },
  })

  const rows = list.data?.data ?? []
  const pagination = list.data?.meta.pagination
  const set = (k: keyof ProductForm) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })
  const pickCategory = (id: string) => {
    setCategory(id)
    setPage(1)
  }
  const openForm = (p?: ProductDto) => {
    setErrors({})
    setMsg(null)
    setEditing(p ?? null)
    setActive(p?.is_active ?? true)
    setForm(
      p
        ? {
            name: p.name,
            kind: p.kind,
            unit_code: p.unit?.code ?? 'EA',
            sku: p.sku ?? '',
            brand: p.brand ?? '',
            category_public_id: p.category?.public_id ?? '',
            public_catalog_code: p.public_catalog_code ?? '',
          }
        : { ...emptyForm, category_public_id: category },
    )
    setOpen(true)
  }
  const addCategory = (e: FormEvent) => {
    e.preventDefault()
    if (newCategory.trim().length >= 2) createCategory.mutate()
  }
  const filtered = Boolean(q || kind || category)

  return (
    <>
      <PageHeader
        title="Catálogo"
        subtitle="Productos y servicios normalizados, con unidad de medida y categoría."
        actions={
          can('products.manage') && (
            <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
              Nuevo producto o servicio
            </Button>
          )
        }
      />

      <TenantDataBanner />

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '260px minmax(0,1fr)' }, alignItems: 'start' }}>
        <Card sx={{ p: 1.5, position: { md: 'sticky' }, top: { md: 88 } }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ px: 1, pt: 0.5, pb: 1 }}>
            <CategoryOutlined fontSize="small" color="primary" />
            <Typography variant="h4" component="h2">
              Categorías
            </Typography>
          </Stack>
          <List dense disablePadding aria-label="Filtrar por categoría">
            <ListItemButton selected={category === ''} onClick={() => pickCategory('')} sx={{ mx: 0, borderRadius: '10px' }}>
              <ListItemText primary="Todas" primaryTypographyProps={{ fontWeight: category === '' ? 700 : 500 }} />
            </ListItemButton>
            {(categories.data?.data ?? []).map((c) => (
              <ListItemButton key={c.public_id} selected={category === c.public_id} onClick={() => pickCategory(c.public_id)} sx={{ mx: 0, borderRadius: '10px' }}>
                <ListItemText primary={c.name} primaryTypographyProps={{ fontWeight: category === c.public_id ? 700 : 500 }} />
              </ListItemButton>
            ))}
          </List>
          {can('products.manage') && (
            <Box component="form" onSubmit={addCategory} sx={{ display: 'flex', gap: 1, mt: 1.5, px: 0.5 }}>
              <TextField
                placeholder="Nueva categoría"
                value={newCategory}
                onChange={(e) => setNewCategory(e.target.value)}
                inputProps={{ 'aria-label': 'Nueva categoría', maxLength: 120 }}
                sx={{ flex: 1 }}
              />
              <IconButton type="submit" color="primary" aria-label="Agregar categoría" disabled={createCategory.isPending || newCategory.trim().length < 2} sx={{ bgcolor: tokens.primaryContainer }}>
                <Add />
              </IconButton>
            </Box>
          )}
          {createCategory.error && (
            <Alert severity="error" sx={{ mt: 1 }}>
              {createCategory.error instanceof ApiError ? createCategory.error.message : 'No se pudo crear la categoría.'}
            </Alert>
          )}
        </Card>

        <Box sx={{ minWidth: 0 }}>
          <FilterBar count={pagination ? `${pagination.total.toLocaleString('es-CL')} registros` : undefined}>
            <SearchField
              value={q}
              onChange={(v) => {
                setQ(v)
                setPage(1)
              }}
              placeholder="Nombre, SKU o marca"
              label="Buscar productos"
            />
            <ToggleButtonGroup
              exclusive
              size="small"
              value={kind}
              onChange={(_, v: string | null) => {
                setKind(v ?? '')
                setPage(1)
              }}
              aria-label="Tipo"
              sx={{ '& .MuiToggleButton-root': { textTransform: 'none', px: 1.75, borderRadius: '10px' } }}
            >
              <ToggleButton value="">Todos</ToggleButton>
              <ToggleButton value="PRODUCT">Productos</ToggleButton>
              <ToggleButton value="SERVICE">Servicios</ToggleButton>
            </ToggleButtonGroup>
            <Box sx={{ display: { md: 'none' } }}>
              <SelectField label="Categoría" value={category} onChange={pickCategory} allLabel="Todas" options={(categories.data?.data ?? []).map((c) => ({ value: c.public_id, label: c.name }))} />
            </Box>
          </FilterBar>

          {list.isFetching && !list.isLoading && <LinearProgress sx={{ mb: 1, borderRadius: '4px' }} />}
          {list.error && <ErrorState error={list.error} what="el catálogo" />}
          {list.isLoading && <ListSkeleton rows={6} />}
          {!list.isLoading && !list.error && rows.length === 0 && (
            <EmptyState
              title={filtered ? 'Nada coincide con los filtros' : 'El catálogo está vacío'}
              action={
                !filtered && can('products.manage') ? (
                  <Button variant="contained" startIcon={<Add />} onClick={() => openForm()}>
                    Agregar el primero
                  </Button>
                ) : undefined
              }
            >
              {filtered ? 'Prueba con otra búsqueda, tipo o categoría.' : 'Agrega los productos y servicios que TIARE compra para sus clientes.'}
            </EmptyState>
          )}

          {rows.length > 0 && (
            <DataTable
              columns={[{ label: 'Producto o servicio' }, { label: 'SKU', hideOnMobile: true }, { label: 'Categoría', hideOnMobile: true }, { label: 'Unidad', hideOnMobile: true }, { label: 'Estado' }]}
              rows={rows.map((p) => ({
                key: p.public_id,
                onClick: can('products.manage') ? () => openForm(p) : undefined,
                label: can('products.manage') ? `Editar ${p.name}` : undefined,
                cells: [
                  <Stack direction="row" spacing={1.25} alignItems="center">
                    <Box sx={{ width: 32, height: 32, borderRadius: '8px', display: 'grid', placeItems: 'center', bgcolor: p.kind === 'SERVICE' ? tokens.tertiaryContainer : tokens.primaryContainer, color: p.kind === 'SERVICE' ? tokens.tertiary : tokens.primary, flex: 'none' }}>
                      {p.kind === 'SERVICE' ? <HandymanOutlined fontSize="small" /> : <Inventory2Outlined fontSize="small" />}
                    </Box>
                    <Box sx={{ minWidth: 0 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {p.name}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {[p.kind === 'SERVICE' ? 'Servicio' : 'Producto', p.brand, p.public_catalog_code ? `ONU ${p.public_catalog_code}` : null].filter(Boolean).join(', ')}
                      </Typography>
                    </Box>
                  </Stack>,
                  <span className="code">{p.sku ?? '—'}</span>,
                  p.category ? <Chip size="small" variant="outlined" label={p.category.name} /> : '—',
                  p.unit?.name ?? '—',
                  <StatusBadge label={p.is_active ? 'Activo' : 'Inactivo'} className={p.is_active ? 'approved' : 'draft'} />,
                ],
              }))}
            />
          )}

          {pagination && <Pager page={pagination.page} lastPage={pagination.last_page} onChange={setPage} />}
        </Box>
      </Box>

      <FormDialog
        open={open}
        title={editing ? `Editar ${editing.name}` : 'Nuevo producto o servicio'}
        onClose={() => {
          setOpen(false)
          setEditing(null)
        }}
        onSubmit={() => createProduct.mutate()}
        submitLabel={editing ? 'Guardar cambios' : 'Guardar en el catálogo'}
        submitting={createProduct.isPending}
        canSubmit={form.name.trim().length >= 2}
        error={msg}
      >
        <FormGrid>
          <TextField label="Nombre" required autoFocus value={form.name} onChange={set('name')} inputProps={{ maxLength: 200 }} error={Boolean(fieldError(errors, 'name'))} helperText={fieldError(errors, 'name')} sx={{ gridColumn: '1 / -1' }} />
          <TextField select label="Tipo" required value={form.kind} onChange={set('kind')}>
            <MenuItem value="PRODUCT">Producto</MenuItem>
            <MenuItem value="SERVICE">Servicio</MenuItem>
          </TextField>
          <TextField select label="Unidad de medida" required value={form.unit_code} onChange={set('unit_code')}>
            {(references?.units_of_measure ?? []).map((u) => (
              <MenuItem key={u.code} value={u.code}>
                {u.name} ({u.code})
              </MenuItem>
            ))}
          </TextField>
          <TextField label="SKU" value={form.sku} onChange={set('sku')} inputProps={{ maxLength: 64 }} error={Boolean(fieldError(errors, 'sku'))} helperText={fieldError(errors, 'sku') ?? 'Código interno (opcional)'} />
          <TextField select label="Categoría" value={form.category_public_id} onChange={set('category_public_id')}>
            <MenuItem value="">Sin categoría</MenuItem>
            {(categories.data?.data ?? []).map((c) => (
              <MenuItem key={c.public_id} value={c.public_id}>
                {c.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField label="Marca" value={form.brand} onChange={set('brand')} inputProps={{ maxLength: 120 }} />
          <TextField
            label="Código de catálogo Mercado Público"
            placeholder="Ej. 45111603"
            value={form.public_catalog_code}
            onChange={set('public_catalog_code')}
            inputProps={{ maxLength: 10, inputMode: 'numeric' }}
            error={Boolean(fieldError(errors, 'public_catalog_code'))}
            helperText={fieldError(errors, 'public_catalog_code') ?? 'Permitirá relacionarlo con oportunidades públicas'}
          />
          {editing && (
            <FormControlLabel
              control={<Switch checked={active} onChange={(e) => setActive(e.target.checked)} />}
              label={active ? 'Activo: disponible para nuevas solicitudes' : 'Inactivo: se conserva el historial, pero no se ofrece'}
              sx={{ gridColumn: '1 / -1' }}
            />
          )}
        </FormGrid>
      </FormDialog>

      <Snackbar open={Boolean(toast)} autoHideDuration={5000} onClose={() => setToast(null)} message={toast} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </>
  )
}
