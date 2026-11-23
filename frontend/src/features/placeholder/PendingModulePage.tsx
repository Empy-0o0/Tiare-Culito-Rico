import Avatar from '@mui/material/Avatar'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import Typography from '@mui/material/Typography'
import ArrowBack from '@mui/icons-material/ArrowBack'
import { Link, useLocation } from 'react-router-dom'
import { MODULE_PHASE, NAV_ITEMS } from '../../app/navigation'
import { tokens } from '../../app/theme'
import { PageHeader } from '../../components/PageHeader'

/** Módulos planificados. No simula operaciones. */
export function PendingModulePage() {
  const { pathname } = useLocation()
  const item = NAV_ITEMS.find((n) => n.path === pathname)
  const Icon = item?.muiIcon

  return (
    <>
      <PageHeader title={item?.label ?? 'Módulo'} />
      <Card sx={{ p: { xs: 3, sm: 5 }, textAlign: 'center', bgcolor: tokens.surfaceLow, borderStyle: 'dashed' }}>
        {Icon && (
          <Avatar sx={{ width: 64, height: 64, mx: 'auto', mb: 2, bgcolor: tokens.primaryContainer, color: tokens.primary }}>
            <Icon />
          </Avatar>
        )}
        <Typography variant="h3" gutterBottom>
          Este módulo está en desarrollo
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 520, mx: 'auto', mb: 2 }}>
          Todavía no está conectado a datos reales, así que no muestra ni simula operaciones. La maqueta de referencia con datos ficticios sigue en la carpeta
          TIARE_PROTOTIPO_HTML_CSS_JS.
        </Typography>
        <Chip label={`Planificado: ${MODULE_PHASE[pathname] ?? 'fase posterior'}`} sx={{ bgcolor: tokens.tertiaryContainer, color: tokens.onTertiaryContainer, mb: 3 }} />
        <div>
          <Button variant="outlined" startIcon={<ArrowBack />} component={Link} to="/">
            Volver al resumen
          </Button>
        </div>
      </Card>
    </>
  )
}
