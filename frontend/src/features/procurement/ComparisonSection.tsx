import Accordion from '@mui/material/Accordion'
import AccordionDetails from '@mui/material/AccordionDetails'
import AccordionSummary from '@mui/material/AccordionSummary'
import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import Slider from '@mui/material/Slider'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import ExpandMore from '@mui/icons-material/ExpandMore'
import InsightsOutlined from '@mui/icons-material/InsightsOutlined'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { tokens } from '../../app/theme'
import { DataOriginTag } from '../../components/DataBadges'
import { FormDialog } from '../../components/FormDialog'
import { ApiError } from '../../services/apiClient'
import { procurementApi } from '../../services/procurement'
import type { ComparisonRow, PurchaseRequestDetail } from '../../types/procurement'
import { formatDateTime, formatMoney } from '../../utils/format'
import { AgentLauncher } from '../agents/AgentLauncher'

const pct = (v: number) => `${Math.round(v * 100)} %`

function ScoreBar({ label, value }: { label: string; value: number }) {
  return (
    <Box sx={{ display: 'grid', gridTemplateColumns: '64px 1fr 36px', alignItems: 'center', gap: 1 }}>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <LinearProgress variant="determinate" value={value * 100} sx={{ height: 6, borderRadius: '3px', bgcolor: tokens.surfaceHigh }} aria-label={label} />
      <Typography variant="caption" className="num">
        {pct(value)}
      </Typography>
    </Box>
  )
}

/** Comparación explicable de costo total y propuesta de compra (pasos 5–6). */
export function ComparisonSection({ r, onChange }: { r: PurchaseRequestDetail; onChange: (d: PurchaseRequestDetail) => void }) {
  const qc = useQueryClient()
  const c = r.comparison
  const [weights, setWeights] = useState(c?.methodology.weights ?? { price: 0.6, delivery: 0.25, supplier: 0.15 })
  const [proposing, setProposing] = useState<ComparisonRow | null>(null)
  const [justification, setJustification] = useState('')
  const [error, setError] = useState<string | null>(null)
  const fail = (e: unknown) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible completar la acción.')

  const compare = useMutation({ mutationFn: () => procurementApi.compare(r.public_id, weights), onSuccess: (d) => onChange(d.data), onError: fail })
  const propose = useMutation({
    mutationFn: () => procurementApi.propose(r.public_id, { quotation_public_id: proposing!.quotation_public_id, justification: justification || undefined }),
    onSuccess: (d) => {
      setProposing(null)
      setJustification('')
      onChange(d.data)
      void qc.invalidateQueries({ queryKey: ['purchase-requests'] })
    },
    onError: fail,
  })

  const sum = weights.price + weights.delivery + weights.supplier
  const notRecommended = proposing && c?.recommended_quotation_public_id && proposing.quotation_public_id !== c.recommended_quotation_public_id

  return (
    <Stack spacing={2.5}>
      {error && (
        <Alert severity="error" onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {r.actions.compare && (
        <Card sx={{ p: 2.5 }}>
          <Typography variant="h4" component="h3">
            Comparar cotizaciones
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
            Ajusta cuánto pesa cada criterio. El precio usa el costo total en CLP (IVA y despacho incluidos).
          </Typography>
          <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' }, px: 1 }}>
            {(
              [
                ['price', 'Precio'],
                ['delivery', 'Plazo de entrega'],
                ['supplier', 'Evaluación del proveedor'],
              ] as const
            ).map(([k, label]) => (
              <Box key={k}>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {label}: {pct(sum ? weights[k] / sum : 0)}
                </Typography>
                <Slider value={weights[k]} min={0} max={1} step={0.05} onChange={(_, v) => setWeights({ ...weights, [k]: v as number })} aria-label={`Peso de ${label}`} />
              </Box>
            ))}
          </Box>
          <Button variant="contained" startIcon={<InsightsOutlined />} onClick={() => compare.mutate()} disabled={compare.isPending || r.quotations.length === 0 || sum === 0}>
            {compare.isPending ? 'Comparando…' : c ? 'Volver a comparar' : 'Comparar cotizaciones'}
          </Button>
          {r.quotations.length === 0 && (
            <Typography variant="caption" color="text.secondary" sx={{ ml: 1.5 }}>
              Registra al menos una cotización.
            </Typography>
          )}
        </Card>
      )}

      {!c ? (
        <Typography variant="body2" color="text.secondary">
          Aún no se ha generado una comparación.
        </Typography>
      ) : (
        <>
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <Typography variant="h4" component="h3">
              Resultado
            </Typography>
            <Chip size="small" variant="outlined" label="Cálculo de TIARE" />
            <Typography variant="caption" color="text.secondary">
              Generada {formatDateTime(c.created_at)} por {c.generated_by ?? '—'} (versión {c.methodology.request_version} de la solicitud)
            </Typography>
          </Stack>

          {!c.recommended_quotation_public_id && <Alert severity="warning">Ninguna cotización es elegible: todas están vencidas, descartadas o incompletas.</Alert>}

          {c.results.map((row, idx) => {
            const recommended = row.quotation_public_id === c.recommended_quotation_public_id
            return (
              <Card key={row.quotation_public_id} sx={{ p: 2, borderColor: recommended ? tokens.success : undefined, borderWidth: recommended ? 2 : 1, opacity: row.eligible ? 1 : 0.7 }}>
                <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1.4fr 1fr auto' }, alignItems: 'center' }}>
                  <Box>
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Typography className="num" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                        {row.eligible ? `#${idx + 1}` : '—'}
                      </Typography>
                      <Typography variant="subtitle1">{row.supplier}</Typography>
                      {recommended && <Chip size="small" color="success" label="Recomendada" />}
                    </Stack>
                    <Typography variant="body2" className="num" sx={{ fontWeight: 700, mt: 0.5 }}>
                      {formatMoney(row.total_clp)}
                      <Typography component="span" variant="caption" color="text.secondary">
                        {' '}
                        {[row.delivery_days != null ? `${row.delivery_days} días` : 'plazo no informado', row.supplier_rating ? `evaluación ${Number(row.supplier_rating).toFixed(1)}/5` : 'sin evaluaciones', row.warranty_months ? `garantía ${row.warranty_months} meses` : null]
                          .filter(Boolean)
                          .join(', ')}
                      </Typography>
                    </Typography>
                    {!row.eligible && (
                      <Typography variant="caption" color="error">
                        Excluida: {row.excluded_reasons.join(', ')}
                      </Typography>
                    )}
                  </Box>
                  {row.scores ? (
                    <Stack spacing={0.5}>
                      <ScoreBar label="Precio" value={row.scores.price} />
                      <ScoreBar label="Plazo" value={row.scores.delivery} />
                      <ScoreBar label="Proveedor" value={row.scores.supplier} />
                      <Typography variant="caption" sx={{ fontWeight: 700 }}>
                        Puntaje {pct(row.scores.total)}
                      </Typography>
                    </Stack>
                  ) : (
                    <Box />
                  )}
                  {r.actions.propose && row.eligible && (
                    <Button variant={recommended ? 'contained' : 'outlined'} onClick={() => setProposing(row)}>
                      Proponer esta compra
                    </Button>
                  )}
                </Box>
              </Card>
            )
          })}

          <Accordion disableGutters sx={{ borderRadius: '12px !important', '&:before': { display: 'none' } }} variant="outlined">
            <AccordionSummary expandIcon={<ExpandMore />}>
              <Typography variant="subtitle2">Metodología y supuestos</Typography>
            </AccordionSummary>
            <AccordionDetails>
              <Typography variant="body2" sx={{ mb: 1 }}>
                {c.methodology.formula}
              </Typography>
              <Typography variant="body2" sx={{ mb: 1 }}>
                Pesos usados: precio {pct(c.methodology.weights.price)}, plazo {pct(c.methodology.weights.delivery)}, proveedor {pct(c.methodology.weights.supplier)}.
              </Typography>
              <Box component="ul" sx={{ m: 0, pl: 2.5 }}>
                {c.methodology.assumptions.map((a) => (
                  <Typography component="li" variant="body2" key={a}>
                    {a}
                  </Typography>
                ))}
              </Box>
              <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
                La recomendación es un apoyo: la decisión la toma una persona autorizada.
              </Typography>
            </AccordionDetails>
          </Accordion>
        </>
      )}

      <FormDialog
        open={Boolean(proposing)}
        title="Proponer compra para aprobación"
        onClose={() => setProposing(null)}
        onSubmit={() => propose.mutate()}
        submitLabel="Enviar a aprobación"
        submitting={propose.isPending}
        canSubmit={!notRecommended || justification.trim().length >= 5}
        error={null}
      >
        {proposing && (
          <>
            <Typography variant="body1">
              <strong>{proposing.supplier}</strong> por <strong className="num">{formatMoney(proposing.total_clp)}</strong>
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
              La solicitud pasará a "Esperando aprobación". No podrás aprobarla tú: debe hacerlo otra persona con permiso y límite suficiente.
            </Typography>
            {notRecommended && (
              <Alert severity="warning" sx={{ mb: 2 }}>
                No es la cotización recomendada: explica por qué la eliges.
              </Alert>
            )}
            <TextField label={notRecommended ? 'Justificación' : 'Justificación (opcional)'} required={Boolean(notRecommended)} value={justification} onChange={(e) => setJustification(e.target.value)} multiline minRows={3} fullWidth inputProps={{ maxLength: 2000 }} />
            <Box sx={{ mt: 1.5 }}>
              <DataOriginTag origin="client" />
            </Box>
          </>
        )}
      </FormDialog>

      {r.quotations.some((q) => q.status !== 'DISCARDED') && (
        <Card variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>
            Análisis con agente IA
          </Typography>
          <AgentLauncher agent="cost_intelligence_agent" subjectType="purchase_request" subjectRef={r.public_id} />
        </Card>
      )}
    </Stack>
  )
}
