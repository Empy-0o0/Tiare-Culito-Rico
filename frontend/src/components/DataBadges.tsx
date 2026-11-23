import Box from '@mui/material/Box'
import Chip from '@mui/material/Chip'
import Link from '@mui/material/Link'
import Typography from '@mui/material/Typography'
import VerifiedOutlined from '@mui/icons-material/VerifiedOutlined'
import type { ReactNode } from 'react'
import { tokens } from '../app/theme'
import { compraAgilStatusClass, formatDateTime } from '../utils/format'
import type { SourceInfo } from '../types/api'

/** Tonos de estado → colores del tema (pares contenedor / texto de Material 3). */
const TONE: Record<string, { bg: string; fg: string }> = {
  approved: { bg: tokens.successContainer, fg: '#0F3D25' },
  progress: { bg: tokens.tertiaryContainer, fg: tokens.onTertiaryContainer },
  pending: { bg: tokens.tertiaryContainer, fg: tokens.onTertiaryContainer },
  completed: { bg: tokens.primaryContainer, fg: tokens.onPrimaryContainer },
  rejected: { bg: tokens.errorContainer, fg: '#410002' },
  draft: { bg: tokens.surfaceHigh, fg: tokens.onSurfaceVariant },
}

/** Chip de estado. `className` recibe el tono (approved, progress, completed, rejected, pending, draft). */
export function StatusBadge({ code, label, className }: { code?: string | null; label: string | null; className?: string }) {
  const tone = className ?? compraAgilStatusClass(code)
  const c = TONE[tone] ?? TONE.draft
  return (
    <Chip
      size="small"
      label={label ?? code ?? 'Sin estado'}
      className={`status-chip tone-${tone}`}
      sx={{ bgcolor: c.bg, color: c.fg }}
    />
  )
}

type Origin = 'official' | 'client' | 'ai' | 'demo'

const ORIGIN_LABEL: Record<Origin, string> = {
  official: 'Dato oficial',
  client: 'Dato ingresado por cliente',
  ai: 'Estimación IA',
  demo: 'Dato demo',
}

/** Etiqueta de procedencia (CLAUDE.md §12). */
export function DataOriginTag({ origin }: { origin: Origin }) {
  return (
    <Chip
      size="small"
      variant="outlined"
      icon={origin === 'official' ? <VerifiedOutlined fontSize="small" /> : undefined}
      label={ORIGIN_LABEL[origin]}
      color={origin === 'official' ? 'success' : origin === 'demo' ? 'warning' : 'default'}
    />
  )
}

/** Atribución obligatoria de ChileCompra + frescura del dato. */
export function SourceAttribution({ source, updatedAt, children }: { source?: SourceInfo; updatedAt?: string | null; children?: ReactNode }) {
  return (
    <Box sx={{ mt: 3, display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1 }}>
      <DataOriginTag origin="official" />
      <Typography variant="caption" color="text.secondary">
        Fuente: <strong>{source?.name ?? 'Dirección ChileCompra / Mercado Público'}</strong>
        {source?.api ? `, ${source.api}` : ''}. Actualizado el {formatDateTime(updatedAt)} (hora de Chile).{' '}
        {source?.portal_url && (
          <Link href={source.portal_url} target="_blank" rel="noopener noreferrer">
            Abrir Mercado Público
          </Link>
        )}
        {children}
      </Typography>
    </Box>
  )
}

export function DemoBanner({ children }: { children?: ReactNode }) {
  return (
    <Box
      role="note"

      sx={{ px: 2, py: 1.5, borderRadius: '12px', bgcolor: tokens.tertiaryContainer, color: tokens.onTertiaryContainer, fontSize: '0.875rem', border: 0 }}
    >
      <strong>Datos demostrativos.</strong> {children ?? 'Esta vista aún usa información ficticia del prototipo.'}
    </Box>
  )
}
