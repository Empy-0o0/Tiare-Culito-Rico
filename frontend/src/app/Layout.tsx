import AppBar from '@mui/material/AppBar'
import Avatar from '@mui/material/Avatar'
import Box from '@mui/material/Box'
import Chip from '@mui/material/Chip'
import Divider from '@mui/material/Divider'
import Drawer from '@mui/material/Drawer'
import IconButton from '@mui/material/IconButton'
import InputBase from '@mui/material/InputBase'
import List from '@mui/material/List'
import ListItemButton from '@mui/material/ListItemButton'
import ListItemIcon from '@mui/material/ListItemIcon'
import ListItemText from '@mui/material/ListItemText'
import ListSubheader from '@mui/material/ListSubheader'
import Menu from '@mui/material/Menu'
import MenuItem from '@mui/material/MenuItem'
import Stack from '@mui/material/Stack'
import Toolbar from '@mui/material/Toolbar'
import Tooltip from '@mui/material/Tooltip'
import Typography from '@mui/material/Typography'
import useMediaQuery from '@mui/material/useMediaQuery'
import { useTheme } from '@mui/material/styles'
import Logout from '@mui/icons-material/Logout'
import BadgeOutlined from '@mui/icons-material/BadgeOutlined'
import KeyOutlined from '@mui/icons-material/KeyOutlined'
import MenuIcon from '@mui/icons-material/Menu'
import Search from '@mui/icons-material/Search'
import VerifiedOutlined from '@mui/icons-material/VerifiedOutlined'
import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { AlertsBell } from '../features/public-procurement/AlertsBell'
import { useAuth } from './AuthContext'
import { NAV_ITEMS, type NavItem } from './navigation'
import { tokens } from './theme'

const DRAWER = 272

function initials(name: string): string {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')
}

/** Estructura: menú lateral con íconos (fijo en escritorio, deslizable en móvil), barra superior con búsqueda y contenido. */
export function Layout() {
  const { profile, logout, can } = useAuth()
  const theme = useTheme()
  const desktop = useMediaQuery(theme.breakpoints.up('md'))
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [userMenu, setUserMenu] = useState<HTMLElement | null>(null)
  const navigate = useNavigate()
  const tenant = profile?.current_tenant
  const userName = profile?.user.name ?? ''

  // Cuenta de portal (cliente/proveedor): solo "Mi perfil" (TD-028).
  const portalItems: NavItem[] = [{ path: '/portal', label: 'Mi perfil', muiIcon: BadgeOutlined, live: true }]
  const navList = (live: boolean) =>
    // Solo se muestran los módulos que el rol y el plan contratado permiten (el backend igual lo exige).
    (profile?.portal ? portalItems.filter((n) => n.live === live) : NAV_ITEMS.filter((n) => n.live === live && (!n.permission || can(n.permission)) && (!n.anyPermission || n.anyPermission.some((p) => can(p))))).map((n) => {
      const Icon = n.muiIcon
      return (
        <ListItemButton
          key={n.path}
          component={NavLink}
          to={n.path}
          end={n.path === '/'}
          onClick={() => setOpen(false)}
          sx={{ mb: 0.25, opacity: live ? 1 : 0.72 }}
        >
          <ListItemIcon sx={{ minWidth: 40 }}>
            <Icon fontSize="small" />
          </ListItemIcon>
          <ListItemText primary={n.label} primaryTypographyProps={{ fontSize: '0.9rem', fontWeight: 'inherit' }} />
          {!live && <Chip label="Pronto" size="small" sx={{ height: 20, fontSize: '0.68rem', bgcolor: tokens.surfaceHigh }} />}
        </ListItemButton>
      )
    })

  const drawerContent = (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', bgcolor: tokens.surfaceLow }}>
      <Box sx={{ px: 3, pt: 3, pb: 2 }}>
        <Stack direction="row" spacing={1.25} alignItems="center">
          <Box sx={{ width: 36, height: 36, borderRadius: '10px', bgcolor: 'primary.main', color: '#fff', display: 'grid', placeItems: 'center', fontWeight: 800 }}>T</Box>
          <Box>
            <Typography sx={{ fontWeight: 800, fontSize: '1.15rem', letterSpacing: '0.04em', lineHeight: 1 }}>TIARE</Typography>
            <Typography variant="caption" color="text.secondary">
              Compras y abastecimiento
            </Typography>
          </Box>
        </Stack>
      </Box>
      <Box sx={{ mx: 2, mb: 1.5, p: 1.5, borderRadius: '12px', bgcolor: '#FFFFFF', border: `1px solid ${tokens.outlineVariant}` }}>
        <Typography variant="caption" color="text.secondary">
          Empresa
        </Typography>
        <Typography variant="body2" sx={{ fontWeight: 700 }} noWrap>
          {tenant?.name ?? 'Sin empresa asignada'}
        </Typography>
        <Stack direction="row" spacing={0.5} sx={{ mt: 0.75, flexWrap: 'wrap', rowGap: 0.5 }}>
          {profile?.portal && (
            <Chip label={profile.portal.type === 'supplier' ? 'Portal de proveedor' : 'Portal de cliente'} size="small" color="secondary" variant="outlined" sx={{ height: 20, fontSize: '0.68rem' }} />
          )}
          {tenant && !profile?.portal && (
            <Chip
              label={tenant.subscription?.plan ? `Plan ${tenant.subscription.plan.name}` : 'Sin plan vigente'}
              size="small"
              color={tenant.subscription?.plan ? 'primary' : 'error'}
              variant="outlined"
              sx={{ height: 20, fontSize: '0.68rem' }}
            />
          )}
          {tenant?.is_demo && <Chip label="Demostrativa" size="small" color="warning" variant="outlined" sx={{ height: 20, fontSize: '0.68rem' }} />}
        </Stack>
      </Box>
      <List component="nav" aria-label="Módulos disponibles" sx={{ px: 0.5 }}>
        {navList(true)}
      </List>
      <List
        component="nav"
        aria-label="Módulos en desarrollo"
        sx={{ px: 0.5 }}
        subheader={
          <ListSubheader sx={{ bgcolor: 'transparent', lineHeight: '32px', fontSize: '0.75rem' }} disableSticky>
            En desarrollo
          </ListSubheader>
        }
      >
        {navList(false)}
      </List>
      <Box sx={{ mt: 'auto', p: 2 }}>
        <Typography variant="caption" color="text.secondary">
          Horarios en hora de Chile
        </Typography>
      </Box>
    </Box>
  )

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <Drawer
        variant={desktop ? 'permanent' : 'temporary'}
        open={desktop || open}
        onClose={() => setOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{ width: { md: DRAWER }, flexShrink: 0, '& .MuiDrawer-paper': { width: DRAWER, boxSizing: 'border-box', borderRight: `1px solid ${tokens.outlineVariant}` } }}
      >
        {drawerContent}
      </Drawer>

      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <AppBar position="sticky" elevation={0} color="inherit" sx={{ bgcolor: 'rgba(255,248,248,0.88)', backdropFilter: 'blur(8px)', borderBottom: `1px solid ${tokens.outlineVariant}` }}>
          <Toolbar sx={{ gap: 1.5, px: { xs: 2, md: 4 } }}>
            {!desktop && (
              <IconButton edge="start" aria-label="Abrir menú" onClick={() => setOpen(true)}>
                <MenuIcon />
              </IconButton>
            )}
            <Box
              component="form"
              role="search"
              onSubmit={(e) => {
                e.preventDefault()
                if (search.trim()) navigate(`/mercado-publico?q=${encodeURIComponent(search.trim())}`)
              }}
              sx={{ flex: 1, maxWidth: 560, display: 'flex', alignItems: 'center', gap: 1, px: 2, height: 44, borderRadius: 22, bgcolor: tokens.surfaceHigh }}
            >
              <Search sx={{ color: 'text.secondary' }} />
              <InputBase
                placeholder="Buscar Compra Ágil por título, organismo o código"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                inputProps={{ 'aria-label': 'Buscar Compras Ágiles', type: 'search' }}
                sx={{ flex: 1, fontSize: '0.9rem' }}
              />
            </Box>
            <Box sx={{ flex: 1 }} />
            <Chip
              icon={<VerifiedOutlined />}
              label="Datos oficiales de ChileCompra"
              size="small"
              variant="outlined"
              color="success"
              sx={{ display: { xs: 'none', lg: 'inline-flex' } }}
            />
            <AlertsBell />
            <Tooltip title="Cuenta">
              <IconButton onClick={(e) => setUserMenu(e.currentTarget)} aria-label="Abrir menú de cuenta">
                <Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main', fontSize: '0.85rem', fontWeight: 700 }}>{initials(userName)}</Avatar>
              </IconButton>
            </Tooltip>
            <Menu anchorEl={userMenu} open={Boolean(userMenu)} onClose={() => setUserMenu(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }}>
              <Box sx={{ px: 2, py: 1 }}>
                <Typography variant="subtitle2">{userName}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {profile?.user.email}
                </Typography>
              </Box>
              <Divider />
              <MenuItem
                onClick={() => {
                  setUserMenu(null)
                  navigate('/mi-cuenta')
                }}
              >
                <ListItemIcon>
                  <KeyOutlined fontSize="small" />
                </ListItemIcon>
                Mi cuenta y contraseña
              </MenuItem>
              <MenuItem
                onClick={() => {
                  setUserMenu(null)
                  void logout()
                }}
              >
                <ListItemIcon>
                  <Logout fontSize="small" />
                </ListItemIcon>
                Cerrar sesión
              </MenuItem>
            </Menu>
          </Toolbar>
        </AppBar>

        <Box component="main" sx={{ flex: 1, width: '100%', maxWidth: 1320, px: { xs: 2, md: 4 }, py: { xs: 3, md: 4 } }}>
          <Outlet />
        </Box>
        <Box component="footer" sx={{ px: { xs: 2, md: 4 }, py: 2, borderTop: `1px solid ${tokens.outlineVariant}` }}>
          <Typography variant="caption" color="text.secondary">
            TIARE SpA, Chile. Fechas y horas en hora de Chile (America/Santiago).
          </Typography>
        </Box>
      </Box>
    </Box>
  )
}
