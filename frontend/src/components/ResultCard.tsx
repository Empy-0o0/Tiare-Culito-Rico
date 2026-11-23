import Box from '@mui/material/Box'
import Card from '@mui/material/Card'
import CardActionArea from '@mui/material/CardActionArea'
import Chip from '@mui/material/Chip'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import AccessTime from '@mui/icons-material/AccessTime'
import ChevronRight from '@mui/icons-material/ChevronRight'
import type { ReactNode } from 'react'
import { tokens } from '../app/theme'
import type { ClosingTone } from '../utils/format'

interface Props {
  code: string
  status: ReactNode
  title: string
  subtitle?: ReactNode
  amount: string
  amountLabel: string
  /** Cierre con tono (soon = menos de 24 h) o una fecha simple. */
  closing?: { text: string; tone: ClosingTone } | null
  dateText: string
  dateLabel: string
  tags?: ReactNode
  selected?: boolean
  onOpen: () => void
}

/**
 * Tarjeta de resultado (Compra Ágil, licitación, OC). Toda la tarjeta abre la ficha.
 * Jerarquía: código + estado, título, organismo; a la derecha monto y plazo.
 */
export function ResultCard({ code, status, title, subtitle, amount, amountLabel, closing, dateText, dateLabel, tags, selected, onOpen }: Props) {
  const soon = closing?.tone === 'soon'
  return (
    <Card
      sx={{
        borderColor: selected ? 'primary.main' : soon ? tokens.urgent : undefined,
        borderLeftWidth: soon ? 4 : 1,
        bgcolor: selected ? tokens.primaryContainer : '#FFFFFF',
        transition: 'box-shadow 120ms, border-color 120ms',
        '&:hover': { boxShadow: '0 6px 20px -10px rgba(152,64,97,0.45)', borderColor: 'primary.light' },
      }}
    >
      <CardActionArea onClick={onOpen} aria-current={selected ? 'true' : undefined} sx={{ p: { xs: 2, sm: 2.5 } }}>
        <Box sx={{ display: 'grid', gap: { xs: 1.5, md: 3 }, gridTemplateColumns: { xs: '1fr', md: 'minmax(0,1fr) 170px 170px 24px' }, alignItems: 'center' }}>
          <Box sx={{ minWidth: 0 }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mb: 0.75 }}>
              <Typography className="code" sx={{ fontSize: '1.05rem', color: 'primary.dark' }}>
                {code}
              </Typography>
              {status}
              {tags}
            </Stack>
            <Typography
              variant="subtitle1"
              sx={{ lineHeight: 1.35, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}
            >
              {title}
            </Typography>
            {subtitle && (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.25 }}>
                {subtitle}
              </Typography>
            )}
          </Box>
          <Box sx={{ textAlign: { md: 'right' } }}>
            <Typography className="num" sx={{ fontWeight: 700, fontSize: '1.05rem' }}>
              {amount}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {amountLabel}
            </Typography>
          </Box>
          <Box sx={{ textAlign: { md: 'right' } }}>
            {closing && closing.tone !== 'closed' ? (
              <Chip
                size="small"
                icon={<AccessTime sx={{ fontSize: 16 }} />}
                label={closing.text}
                className={`closing-chip tone-${closing.tone}`}
                sx={
                  soon
                    ? { bgcolor: tokens.tertiaryContainer, color: tokens.urgent, '& .MuiChip-icon': { color: tokens.urgent } }
                    : { bgcolor: tokens.surfaceHigh, color: tokens.onSurfaceVariant }
                }
              />
            ) : (
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {closing?.tone === 'closed' ? 'Cerrada' : dateText}
              </Typography>
            )}
            <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.25 }}>
              {closing && closing.tone !== 'closed' ? dateText : dateLabel}
            </Typography>
          </Box>
          <ChevronRight sx={{ color: 'text.secondary', display: { xs: 'none', md: 'block' } }} />
        </Box>
      </CardActionArea>
    </Card>
  )
}
