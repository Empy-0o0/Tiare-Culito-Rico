import Box from '@mui/material/Box'
import { useAuth } from '../../app/AuthContext'
import { DemoBanner } from '../../components/DataBadges'

/** En un tenant demostrativo, todos los registros privados son ficticios. */
export function TenantDataBanner() {
  const { profile } = useAuth()
  if (!profile?.current_tenant?.is_demo) return null
  return (
    <Box sx={{ mb: 2.5 }}>
      <DemoBanner>Estás en la empresa demostrativa: los registros de este módulo son ficticios (portados del prototipo).</DemoBanner>
    </Box>
  )
}
