import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import WorkspacePremiumOutlined from '@mui/icons-material/WorkspacePremiumOutlined'
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline'
import { tokens } from '../../app/theme'
import type { CompanyDto } from '../../types/crm'
import { formatDate } from '../../utils/format'
import { SectionTitle } from './SectionTitle'

/** Plan contratado por la empresa activa. Solo lectura: los cambios de plan los hace TIARE. */
export function PlanCard({ company }: { company: CompanyDto }) {
  const sub = company.subscription
  const plan = sub?.plan

  if (!sub || !plan) {
    return (
      <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <SectionTitle icon={<WorkspacePremiumOutlined />} title="Plan contratado" subtitle="Módulos y límites habilitados para tu empresa." />
        <Alert severity="warning">Tu empresa no tiene un plan vigente, por eso los módulos de trabajo no están disponibles. Contacta a TIARE para activarlo.</Alert>
      </Card>
    )
  }

  const seatsPct = company.seats.max ? Math.min(100, Math.round((company.seats.used / company.seats.max) * 100)) : null

  return (
    <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
      <SectionTitle icon={<WorkspacePremiumOutlined />} title={`Plan ${plan.name}`} subtitle={plan.description ?? 'Módulos y límites habilitados para tu empresa.'} />
      <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '2fr 1fr' } }}>
        <Box>
          <Typography variant="overline" color="text.secondary">
            Módulos incluidos
          </Typography>
          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 1, mt: 0.5 }}>
            {plan.modules.map((m) => (
              <Chip key={m.key} icon={<CheckCircleOutline />} label={m.name} size="small" sx={{ bgcolor: tokens.primaryContainer, color: tokens.onPrimaryContainer, '& .MuiChip-icon': { color: tokens.primary } }} />
            ))}
          </Stack>
        </Box>
        <Stack spacing={1.5}>
          <Box>
            <Typography variant="overline" color="text.secondary">
              Vigencia
            </Typography>
            <Typography variant="body2">
              <Chip label={sub.status_label} size="small" color={sub.status === 'active' ? 'success' : 'default'} sx={{ mr: 1, height: 20 }} />
              Desde {formatDate(sub.starts_on)} {sub.ends_on ? `hasta ${formatDate(sub.ends_on)}` : '· sin fecha de término'}
            </Typography>
          </Box>
          <Box>
            <Typography variant="overline" color="text.secondary">
              Personas con acceso
            </Typography>
            <Typography variant="body2">
              {company.seats.used} {company.seats.max ? `de ${company.seats.max}` : '· sin límite'}
            </Typography>
            {seatsPct !== null && <LinearProgress variant="determinate" value={seatsPct} color={seatsPct >= 100 ? 'error' : 'primary'} sx={{ mt: 0.75, borderRadius: '4px' }} />}
          </Box>
        </Stack>
      </Box>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 2 }}>
        Para cambiar de plan o ampliar el número de personas, contacta a tu ejecutivo TIARE.
      </Typography>
    </Card>
  )
}
