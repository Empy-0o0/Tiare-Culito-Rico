import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import GavelOutlined from '@mui/icons-material/GavelOutlined'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import Search from '@mui/icons-material/Search'
import StorefrontOutlined from '@mui/icons-material/StorefrontOutlined'
import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { SourceAttribution } from '../../components/DataBadges'
import { ErrorState } from '../../components/ListStates'
import { publicProcurementApi } from '../../services/publicProcurement'
import { formatRut, isValidRut } from '../../utils/rut'

/** Proveedor del Estado por RUT (BuscarProveedor) y acceso a su actividad en licitaciones y OC. */
export function SupplierLookupPage() {
  const [rut, setRut] = useState('')
  const [invalid, setInvalid] = useState(false)
  const lookup = useMutation({ mutationFn: (value: string) => publicProcurementApi.lookupSupplier(value) })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!isValidRut(rut)) {
      setInvalid(true)
      return
    }
    setInvalid(false)
    lookup.mutate(rut)
  }

  const s = lookup.data?.data

  return (
    <>
      <Card component="form" onSubmit={submit} aria-label="Buscar proveedor por RUT" sx={{ p: 2.5, mb: 2.5 }}>
        <Typography variant="h4" component="h2">
          Buscar un proveedor del Estado
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
          Ingresa el RUT de la empresa para obtener el código que Mercado Público usa en sus licitaciones y órdenes de compra.
        </Typography>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'flex-start' }}>
          <TextField
            label="RUT de la empresa"
            placeholder="76.000.000-0"
            value={rut}
            onChange={(e) => {
              setRut(e.target.value)
              if (invalid) setInvalid(false)
            }}
            onBlur={() => isValidRut(rut) && setRut(formatRut(rut))}
            error={invalid}
            helperText={invalid ? 'El RUT no es válido: revisa el dígito verificador.' : 'Con o sin puntos y guion'}
            inputProps={{ maxLength: 14 }}
            sx={{ minWidth: 260 }}
          />
          <Button type="submit" variant="contained" startIcon={<Search />} disabled={lookup.isPending || !rut.trim()} sx={{ height: 40 }}>
            {lookup.isPending ? 'Buscando…' : 'Buscar'}
          </Button>
        </Stack>
      </Card>

      {lookup.error && <ErrorState error={lookup.error} what="el proveedor" />}

      {s && lookup.data && (
        <Card sx={{ p: 2.5 }}>
          <Stack direction="row" spacing={2} alignItems="center">
            <Avatar sx={{ bgcolor: tokens.primaryContainer, color: tokens.primary, width: 52, height: 52 }}>
              <StorefrontOutlined />
            </Avatar>
            <Box sx={{ minWidth: 0 }}>
              <Typography variant="h3">{s.name}</Typography>
              <Typography variant="body2" color="text.secondary">
                RUT {s.rut}. Código de proveedor <strong className="code">{s.code}</strong>
              </Typography>
            </Box>
          </Stack>
          <Typography variant="subtitle2" sx={{ mt: 2.5, mb: 1 }}>
            Ver su actividad de hoy
          </Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
            <Button variant="outlined" startIcon={<ReceiptLongOutlined />} component={Link} to={`/mercado-publico/ordenes-de-compra?supplier_code=${s.code}`}>
              Órdenes de compra recibidas
            </Button>
            <Button variant="outlined" startIcon={<GavelOutlined />} component={Link} to={`/mercado-publico/licitaciones?supplier_code=${s.code}`}>
              Licitaciones en que participa
            </Button>
          </Stack>
          <SourceAttribution source={lookup.data.meta.source} updatedAt={s.last_fetched_at} />
        </Card>
      )}
    </>
  )
}
