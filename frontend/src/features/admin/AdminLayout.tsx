import Box from '@mui/material/Box'
import Tab from '@mui/material/Tab'
import Tabs from '@mui/material/Tabs'
import Typography from '@mui/material/Typography'
import AdminPanelSettingsOutlined from '@mui/icons-material/AdminPanelSettingsOutlined'
import ApartmentOutlined from '@mui/icons-material/ApartmentOutlined'
import ManageAccountsOutlined from '@mui/icons-material/ManageAccountsOutlined'
import { Link, Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'

const TABS = [
  { to: '/admin', label: 'Empresas', icon: <ApartmentOutlined />, permission: 'platform.tenants.manage' },
  { to: '/admin/cuentas', label: 'Cuentas', icon: <ManageAccountsOutlined />, permission: 'platform.users.manage' },
  { to: '/admin/roles', label: 'Roles y permisos', icon: <AdminPanelSettingsOutlined />, permission: 'platform.roles.manage' },
]

/** Administración TIARE: empresas clientes, cuentas y roles. Solo perfiles de plataforma autorizados. */
export function AdminLayout() {
  const { can } = useAuth()
  const { pathname } = useLocation()
  const tabs = TABS.filter((t) => can(t.permission))
  if (tabs.length === 0) return <Navigate to="/" replace />
  const current = tabs.find((t) => t.to === pathname)
  if (!current) return <Navigate to={tabs[0].to} replace />

  return (
    <>
      <Box sx={{ mb: 2 }}>
        <Typography variant="h1">Administración TIARE</Typography>
        <Typography color="text.secondary" sx={{ mt: 0.75, maxWidth: '75ch' }}>
          Empresas clientes y su plan, cuentas de usuario y roles con sus permisos. Cada cambio queda registrado en la auditoría.
        </Typography>
      </Box>
      <Box sx={{ borderBottom: `1px solid ${tokens.outlineVariant}`, mb: 2.5 }}>
        <Tabs value={current.to} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile aria-label="Secciones de administración">
          {tabs.map((t) => (
            <Tab key={t.to} value={t.to} label={t.label} icon={t.icon} iconPosition="start" component={Link} to={t.to} />
          ))}
        </Tabs>
      </Box>
      <Outlet />
    </>
  )
}
