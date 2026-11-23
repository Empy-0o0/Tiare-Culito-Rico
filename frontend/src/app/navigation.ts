import type { SvgIconComponent } from '@mui/icons-material'
import AdminPanelSettingsOutlined from '@mui/icons-material/AdminPanelSettingsOutlined'
import AccountBalanceOutlined from '@mui/icons-material/AccountBalanceOutlined'
import AssignmentOutlined from '@mui/icons-material/AssignmentOutlined'
import AutoAwesomeOutlined from '@mui/icons-material/AutoAwesomeOutlined'
import BalanceOutlined from '@mui/icons-material/BalanceOutlined'
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined'
import InsightsOutlined from '@mui/icons-material/InsightsOutlined'
import LocalShippingOutlined from '@mui/icons-material/LocalShippingOutlined'
import PeopleOutline from '@mui/icons-material/PeopleOutline'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import RouteOutlined from '@mui/icons-material/RouteOutlined'
import WarehouseOutlined from '@mui/icons-material/WarehouseOutlined'
import SettingsOutlined from '@mui/icons-material/SettingsOutlined'
import SpaceDashboardOutlined from '@mui/icons-material/SpaceDashboardOutlined'

export interface NavItem {
  path: string
  label: string
  muiIcon: SvgIconComponent
  /** true = conectado a datos reales; false = módulo planificado (no simula operaciones). */
  live: boolean
  /** Permiso efectivo requerido para mostrarlo (el backend ya cruza rol y plan contratado). */
  permission?: string
  /** Se muestra si tiene al menos uno de estos permisos. */
  anyPermission?: string[]
}

export const NAV_ITEMS: NavItem[] = [
  { path: '/', label: 'Resumen', muiIcon: SpaceDashboardOutlined, live: true },
  { path: '/mercado-publico', label: 'Mercado Público', muiIcon: AccountBalanceOutlined, live: true, permission: 'public_procurement.read' },
  { path: '/clientes', label: 'Clientes', muiIcon: PeopleOutline, live: true, permission: 'customers.read' },
  { path: '/proveedores', label: 'Proveedores', muiIcon: LocalShippingOutlined, live: true, permission: 'suppliers.read' },
  { path: '/solicitudes', label: 'Solicitudes de compra', muiIcon: AssignmentOutlined, live: true, permission: 'purchase_requests.read' },
  { path: '/cotizaciones', label: 'Cotizaciones', muiIcon: BalanceOutlined, live: true, permission: 'purchase_requests.read' },
  { path: '/ordenes', label: 'Órdenes de compra', muiIcon: ReceiptLongOutlined, live: true, permission: 'purchase_orders.read' },
  { path: '/catalogo', label: 'Catálogo', muiIcon: Inventory2Outlined, live: true, permission: 'products.read' },
  { path: '/inventario', label: 'Inventario', muiIcon: WarehouseOutlined, live: true, permission: 'inventory.read' },
  { path: '/logistica', label: 'Logística', muiIcon: RouteOutlined, live: true, permission: 'shipments.read' },
  { path: '/agentes', label: 'Agentes IA', muiIcon: AutoAwesomeOutlined, live: true, permission: 'agents.read' },
  { path: '/informes', label: 'Analítica e informes', muiIcon: InsightsOutlined, live: true, permission: 'reports.read' },
  { path: '/configuracion', label: 'Configuración', muiIcon: SettingsOutlined, live: true },
  { path: '/admin', label: 'Administración TIARE', muiIcon: AdminPanelSettingsOutlined, live: true, anyPermission: ['platform.tenants.manage', 'platform.users.manage', 'platform.roles.manage'] },
]

/** Fase del plan (docs/ARCHITECTURE.md §4) en que se habilita cada módulo. */
export const MODULE_PHASE: Record<string, string> = {
}
