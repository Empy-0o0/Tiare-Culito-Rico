import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { MyAccountPage } from '../features/account/MyAccountPage'
import { PortalPage } from '../features/portal/PortalPage'
import { AdminAccountsPage } from '../features/admin/AdminAccountsPage'
import { AdminLayout } from '../features/admin/AdminLayout'
import { AdminRolesPage } from '../features/admin/AdminRolesPage'
import { AdminTenantsPage } from '../features/admin/AdminTenantsPage'
import { AgentsPage } from '../features/agents/AgentsPage'
import { InventoryPage } from '../features/inventory/InventoryPage'
import { LogisticsPage } from '../features/logistics/LogisticsPage'
import { LoginPage } from '../features/auth/LoginPage'
import { ResetPasswordPage } from '../features/auth/ResetPasswordPage'
import { SettingsPage } from '../features/settings/SettingsPage'
import { CatalogPage } from '../features/crm/CatalogPage'
import { CustomersPage } from '../features/crm/CustomersPage'
import { SuppliersPage } from '../features/crm/SuppliersPage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { PendingModulePage } from '../features/placeholder/PendingModulePage'
import { PurchaseOrdersPage as InternalOrdersPage } from '../features/procurement/PurchaseOrdersPage'
import { PurchaseRequestDetailPage } from '../features/procurement/PurchaseRequestDetailPage'
import { PurchaseRequestsPage } from '../features/procurement/PurchaseRequestsPage'
import { ReportsPage } from '../features/reports/ReportsPage'
import { BuyersPage } from '../features/public-procurement/BuyersPage'
import { CompraAgilMonitorPage } from '../features/public-procurement/CompraAgilMonitorPage'
import { PublicProcurementLayout } from '../features/public-procurement/PublicProcurementLayout'
import { PurchaseOrdersPage } from '../features/public-procurement/PurchaseOrdersPage'
import { SupplierLookupPage } from '../features/public-procurement/SupplierLookupPage'
import { SavedSearchesPage } from '../features/public-procurement/SavedSearchesPage'
import { TendersPage } from '../features/public-procurement/TendersPage'
import { AuthProvider, useAuth } from './AuthContext'
import { Layout } from './Layout'
import { NAV_ITEMS } from './navigation'

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, refetchOnWindowFocus: false, retry: 1 } },
})

function RequireAuth({ children }: { children: ReactNode }) {
  const { profile, loading } = useAuth()
  const { pathname } = useLocation()
  if (loading) return <p className="muted page-loading">Cargando…</p>
  if (!profile) return <Navigate to="/login" replace />
  // Contraseña de inicio pendiente: solo puede ir a cambiarla (el backend bloquea lo demás).
  if (profile.must_change_password && pathname !== '/mi-cuenta') return <Navigate to="/mi-cuenta" replace />
  // Cuenta de portal de cliente/proveedor: solo su perfil y su cuenta (TD-028). El backend igual bloquea lo demás.
  if (profile.portal && pathname !== '/portal' && pathname !== '/mi-cuenta') return <Navigate to="/portal" replace />
  return <>{children}</>
}

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/restablecer" element={<ResetPasswordPage />} />
            <Route
              element={
                <RequireAuth>
                  <Layout />
                </RequireAuth>
              }
            >
              <Route index element={<DashboardPage />} />
              <Route path="mercado-publico" element={<PublicProcurementLayout />}>
                <Route index element={<CompraAgilMonitorPage />} />
                <Route path="busquedas" element={<SavedSearchesPage />} />
                <Route path="licitaciones" element={<TendersPage />} />
                <Route path="ordenes-de-compra" element={<PurchaseOrdersPage />} />
                <Route path="organismos" element={<BuyersPage />} />
                <Route path="proveedores" element={<SupplierLookupPage />} />
              </Route>
              <Route path="clientes" element={<CustomersPage />} />
              <Route path="proveedores" element={<SuppliersPage />} />
              <Route path="catalogo" element={<CatalogPage />} />
              <Route path="configuracion" element={<SettingsPage />} />
              <Route path="mi-cuenta" element={<MyAccountPage />} />
              <Route path="portal" element={<PortalPage />} />
              <Route path="solicitudes" element={<PurchaseRequestsPage />} />
              <Route path="solicitudes/:id" element={<PurchaseRequestDetailPage />} />
              <Route path="cotizaciones" element={<PurchaseRequestsPage mode="quoting" />} />
              <Route path="ordenes" element={<InternalOrdersPage />} />
              <Route path="informes" element={<ReportsPage />} />
              <Route path="inventario" element={<InventoryPage />} />
              <Route path="logistica" element={<LogisticsPage />} />
              <Route path="agentes" element={<AgentsPage />} />
              <Route path="admin" element={<AdminLayout />}>
                <Route index element={<AdminTenantsPage />} />
                <Route path="cuentas" element={<AdminAccountsPage />} />
                <Route path="roles" element={<AdminRolesPage />} />
              </Route>
              {NAV_ITEMS.filter((n) => !n.live).map((n) => (
                <Route key={n.path} path={n.path.slice(1)} element={<PendingModulePage />} />
              ))}
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
