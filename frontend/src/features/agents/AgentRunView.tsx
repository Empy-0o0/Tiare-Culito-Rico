import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { tokens } from '../../app/theme'
import { DataOriginTag } from '../../components/DataBadges'
import { DetailSection, Facts } from '../../components/Drawer'
import { ApiError } from '../../services/apiClient'
import { agentsApi, type AgentRunDetail, type CostAnalysis, type CostFacts, type TenderAnalysis, type TenderFacts } from '../../services/agents'
import { formatDateTime, formatMoney } from '../../utils/format'

const SEVERITY_COLOR = {
  alta: 'error',
  media: 'warning',
  baja: 'default',
} as const
const FIT_LABEL = {
  alto: 'Calce alto',
  medio: 'Calce medio',
  bajo: 'Calce bajo',
  sin_datos: 'Sin catálogo para comparar',
}

function Bullets({ items }: { items: string[] }) {
  if (items.length === 0)
    return (
      <Typography variant="body2" color="text.secondary">
        Nada que agregar.
      </Typography>
    )
  return (
    <Box component="ul" sx={{ m: 0, pl: 2.5, '& li': { mb: 0.5 } }}>
      {items.map((t, i) => (
        <Typography component="li" variant="body2" key={i}>
          {t}
        </Typography>
      ))}
    </Box>
  )
}

function Alerts({ alerts }: { alerts: Array<{ level: string; text: string }> }) {
  return (
    <Stack spacing={1} sx={{ mb: 2 }}>
      {alerts.map((a, i) => (
        <Alert key={i} severity={a.level === 'urgent' ? 'error' : a.level === 'warning' ? 'warning' : 'info'} sx={{ py: 0 }}>
          {a.text}
        </Alert>
      ))}
    </Stack>
  )
}

function Risks({ risks }: { risks: Array<{ risk: string; severity: 'baja' | 'media' | 'alta' }> }) {
  if (risks.length === 0)
    return (
      <Typography variant="body2" color="text.secondary">
        Sin riesgos destacados.
      </Typography>
    )
  return (
    <Stack spacing={0.75}>
      {risks.map((r, i) => (
        <Stack key={i} direction="row" spacing={1} alignItems="flex-start">
          <Chip size="small" label={r.severity} color={SEVERITY_COLOR[r.severity]} variant="outlined" sx={{ minWidth: 56 }} />
          <Typography variant="body2">{r.risk}</Typography>
        </Stack>
      ))}
    </Stack>
  )
}

/** Muestra un análisis: hechos calculados (verificables) separados del texto del modelo (estimación IA), y la revisión humana. */
export function AgentRunView({ run, onReviewed }: { run: AgentRunDetail; onReviewed?: (r: AgentRunDetail) => void }) {
  const tender = run.agent.id === 'tender_intelligence_agent'
  return (
    <Box>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5, flexWrap: 'wrap', rowGap: 1 }}>
        <Typography variant="caption" color="text.secondary">
          {run.agent.name} v{run.agent.prompt_version}, {formatDateTime(run.created_at)}
          {run.requested_by ? `, pedido por ${run.requested_by}` : ''}
        </Typography>
      </Stack>

      {tender ? <TenderFactsView f={run.facts as TenderFacts} /> : <CostFactsView f={run.facts as CostFacts} />}

      {run.error && (
        <Alert severity="warning" sx={{ mt: 2 }}>
          El análisis del modelo no se completó: {run.error.message} Los datos calculados arriba siguen siendo válidos.
        </Alert>
      )}
      {run.mode === 'deterministic' && (
        <Alert severity="info" sx={{ mt: 2 }}>
          Análisis sin modelo de IA: solo datos calculados por TIARE.
        </Alert>
      )}

      {run.analysis && (
        <Box
          sx={{
            mt: 2.5,
            p: 2,
            borderRadius: '16px',
            border: `1px dashed ${tokens.primary}`,
            bgcolor: tokens.surface,
          }}
        >
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
            <DataOriginTag origin="ai" />
            <Typography variant="caption" color="text.secondary">
              {run.model}. Puede contener errores: verifica antes de actuar.
            </Typography>
          </Stack>
          {tender ? <TenderAnalysisView a={run.analysis as TenderAnalysis} /> : <CostAnalysisView a={run.analysis as CostAnalysis} />}
        </Box>
      )}

      <ReviewBox run={run} onReviewed={onReviewed} />
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
        {run.disclaimer}
      </Typography>
    </Box>
  )
}

function TenderFactsView({ f }: { f: TenderFacts }) {
  return (
    <>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }}>
        <DataOriginTag origin="official" />
        <Typography variant="caption" color="text.secondary">
          Calculado desde la ficha de {f.source.name}, actualizada el {formatDateTime(f.source.last_fetched_at)}.
        </Typography>
      </Stack>
      <Alerts alerts={f.alerts} />
      <Facts
        rows={[
          ['Estado', f.open ? (f.hours_to_close !== null ? `Abierta, cierra en ${f.hours_to_close} h` : 'Abierta') : 'No recibe cotizaciones'],
          ['Productos y documentos', `${f.items_count} productos, ${f.documents_count} documentos`],
          ['Monto disponible', f.budget.available ? formatMoney(f.budget.available, f.budget.currency ?? 'CLP') : 'No informado'],
        ]}
      />
      <DetailSection title="Checklist preliminar">
        <Bullets items={f.checklist} />
      </DetailSection>
      {f.catalog_matches.length > 0 && (
        <DetailSection title="Coincidencias con tu catálogo">
          <Stack spacing={0.75}>
            {f.catalog_matches.map((m, i) => (
              <Typography key={i} variant="body2">
                <strong>{m.requested}</strong>: {m.catalog.join(', ')}
              </Typography>
            ))}
          </Stack>
          <Typography variant="caption" color="text.secondary">
            Coincidencia por palabras: confirma que el producto cumple lo pedido.
          </Typography>
        </DetailSection>
      )}
    </>
  )
}

function CostFactsView({ f }: { f: CostFacts }) {
  return (
    <>
      <Alerts alerts={f.alerts} />
      <Facts
        rows={[
          ['Cotizaciones comparables', `${f.comparable} de ${f.quotations}`],
          ['Menor total', f.cheapest ? `${formatMoney(f.cheapest.total_clp)}, ${f.cheapest.supplier}` : 'Sin cotizaciones comparables'],
          ['Diferencia mayor vs menor', f.spread ? `${formatMoney(f.spread.amount_clp)} (${f.spread.percent} %)` : '—'],
          ['Menor total vs estimado', f.cheapest_vs_estimate_percent !== null ? `${f.cheapest_vs_estimate_percent > 0 ? '+' : ''}${f.cheapest_vs_estimate_percent} %` : 'Sin estimado en CLP'],
          ['Última comparación', f.comparison ? `${f.comparison.recommended_supplier ?? 'Sin recomendada'}${f.comparison.stale ? ' (desactualizada)' : ''}` : 'Aún no se compara'],
        ]}
      />
      <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 1 }}>
        {f.notes.join(' ')}
      </Typography>
    </>
  )
}

function TenderAnalysisView({ a }: { a: TenderAnalysis }) {
  return (
    <Stack spacing={2}>
      <Typography variant="body2">{a.summary}</Typography>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {FIT_LABEL[a.catalog_fit.level]}
        </Typography>
        <Typography variant="body2">{a.catalog_fit.rationale}</Typography>
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Requisitos observables
        </Typography>
        <Bullets items={a.observable_requirements.map((r) => `${r.requirement} (${r.source.replace('_', ' ')})`)} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Riesgos
        </Typography>
        <Risks risks={a.risks} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Preguntas para el organismo
        </Typography>
        <Bullets items={a.questions_for_buyer} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Pasos adicionales
        </Typography>
        <Bullets items={a.checklist_additions} />
      </Box>
      <Typography variant="caption" color="text.secondary">
        Límites: {a.uncertainty}
      </Typography>
    </Stack>
  )
}

function CostAnalysisView({ a }: { a: CostAnalysis }) {
  return (
    <Stack spacing={2}>
      <Typography variant="body2">{a.summary}</Typography>
      {a.quotation_notes.map((q) => (
        <Box key={q.supplier}>
          <Typography variant="subtitle2" gutterBottom>
            {q.supplier}
          </Typography>
          <Box
            sx={{
              display: 'grid',
              gap: 1.5,
              gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' },
            }}
          >
            <Box>
              <Typography variant="caption" color="text.secondary">
                A favor
              </Typography>
              <Bullets items={q.strengths} />
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">
                En contra
              </Typography>
              <Bullets items={q.weaknesses} />
            </Box>
          </Box>
        </Box>
      ))}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Riesgos
        </Typography>
        <Risks risks={a.risks} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Preguntas para proveedores
        </Typography>
        <Bullets items={a.questions_for_suppliers.map((q) => `${q.supplier}: ${q.question}`)} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Dónde negociar
        </Typography>
        <Bullets items={a.negotiation_points} />
      </Box>
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Datos que faltan
        </Typography>
        <Bullets items={a.data_gaps} />
      </Box>
      <Typography variant="caption" color="text.secondary">
        Límites: {a.uncertainty}
      </Typography>
    </Stack>
  )
}

function ReviewBox({ run, onReviewed }: { run: AgentRunDetail; onReviewed?: (r: AgentRunDetail) => void }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [discarding, setDiscarding] = useState(false)
  const [comment, setComment] = useState('')
  const [error, setError] = useState<string | null>(null)
  const review = useMutation({
    mutationFn: (verdict: 'USEFUL' | 'DISCARDED') => agentsApi.review(run.public_id, verdict, comment.trim()),
    onSuccess: (d) => {
      void qc.invalidateQueries({ queryKey: ['agent-runs'] })
      setDiscarding(false)
      onReviewed?.(d.data)
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible guardar la revisión.'),
  })

  if (run.review.status !== 'PENDING') {
    return (
      <Alert icon={<CheckCircleOutline />} severity={run.review.status === 'USEFUL' ? 'success' : 'info'} sx={{ mt: 2 }}>
        {run.review.status === 'USEFUL' ? 'Marcado como útil' : 'Descartado'} por {run.review.by} el {formatDateTime(run.review.at)}.{run.review.comment ? ` "${run.review.comment}"` : ''}
      </Alert>
    )
  }
  if (!can('agents.review')) return null

  return (
    <Box sx={{ mt: 2, p: 1.5, borderRadius: '12px', bgcolor: tokens.surfaceHigh }}>
      <Typography variant="body2" sx={{ mb: 1 }}>
        ¿Te sirvió este análisis? Tu revisión queda registrada y no cambia la solicitud ni la oportunidad.
      </Typography>
      {error && (
        <Alert severity="error" sx={{ mb: 1 }}>
          {error}
        </Alert>
      )}
      {discarding ? (
        <Stack spacing={1}>
          <TextField size="small" label="Por qué lo descartas" value={comment} onChange={(e) => setComment(e.target.value)} sx={{ bgcolor: tokens.surface }} />
          <Stack direction="row" spacing={1}>
            <Button size="small" variant="contained" color="error" disabled={comment.trim().length < 3 || review.isPending} onClick={() => review.mutate('DISCARDED')}>
              Descartar análisis
            </Button>
            <Button size="small" onClick={() => setDiscarding(false)}>
              Volver
            </Button>
          </Stack>
        </Stack>
      ) : (
        <Stack direction="row" spacing={1}>
          <Button size="small" variant="contained" disabled={review.isPending} onClick={() => review.mutate('USEFUL')}>
            Marcar como útil
          </Button>
          <Button size="small" onClick={() => setDiscarding(true)}>
            Descartar
          </Button>
        </Stack>
      )}
    </Box>
  )
}
