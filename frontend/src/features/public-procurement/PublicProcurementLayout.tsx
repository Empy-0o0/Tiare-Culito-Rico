import Box from '@mui/material/Box'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import Typography from '@mui/material/Typography'
import BoltOutlined from '@mui/icons-material/BoltOutlined'
import ApartmentOutlined from '@mui/icons-material/ApartmentOutlined'
import GavelOutlined from '@mui/icons-material/GavelOutlined'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import StorefrontOutlined from '@mui/icons-material/StorefrontOutlined'
import BookmarksOutlined from '@mui/icons-material/BookmarksOutlined'
import { Link, Outlet, useLocation } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { IntegrationStrip } from './IntegrationStrip'

const TABS = [
  { to: '/mercado-publico', label: 'Compra Ágil', icon: <BoltOutlined />, hint: 'Cotizaciones rápidas publicadas por organismos' },
  { to: '/mercado-publico/busquedas', label: 'Mis búsquedas', icon: <BookmarksOutlined />, hint: 'Búsquedas guardadas, coincidencias y alertas' },
  { to: '/mercado-publico/licitaciones', label: 'Licitaciones', icon: <GavelOutlined />, hint: 'Procesos públicos y privados' },
  { to: '/mercado-publico/ordenes-de-compra', label: 'Órdenes de compra', icon: <ReceiptLongOutlined />, hint: 'Compras emitidas por organismos' },
  { to: '/mercado-publico/organismos', label: 'Organismos', icon: <ApartmentOutlined />, hint: 'Directorio de compradores' },
  { to: '/mercado-publico/proveedores', label: 'Proveedores', icon: <StorefrontOutlined />, hint: 'Busca un proveedor por RUT' },
]

/**
 * Sección Mercado Público: todas las consultas oficiales de ChileCompra en un solo lugar.
 * TIARE consulta y sigue procesos; las ofertas y demás acciones se hacen en el portal oficial.
 */
export function PublicProcurementLayout() {
  const { pathname } = useLocation()
  const current = TABS.find((t) => t.to === pathname) ?? TABS[0]

  return (
    <>
      <Box sx={{ mb: 2 }}>
        <Typography variant="h1">Mercado Público</Typography>
        <Typography color="text.secondary" sx={{ mt: 0.75, maxWidth: '70ch' }}>
          Oportunidades y compras del Estado con datos oficiales de ChileCompra. TIARE no presenta ofertas: postula y revisa bases en mercadopublico.cl.
        </Typography>
      </Box>
      <Box sx={{ borderBottom: `1px solid ${tokens.outlineVariant}`, mb: 2.5 }}>
        <Tabs value={current.to} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile aria-label="Secciones de Mercado Público">
          {TABS.map((t) => (
            <Tab key={t.to} value={t.to} label={t.label} icon={t.icon} iconPosition="start" component={Link} to={t.to} title={t.hint} />
          ))}
        </Tabs>
      </Box>
      <IntegrationStrip />
      <Outlet />
    </>
  )
}
