import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Card from '@mui/material/Card'
import CardContent from '@mui/material/CardContent'
import Chip from '@mui/material/Chip'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link as RouterLink } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { Drawer } from '../../components/Drawer'
import { FilterBar, SelectField } from '../../components/FilterBar'
import { EmptyState, ErrorState, ListSkeleton, Pager } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { agentsApi, type AgentRunDetail, type AgentRunSummary } from '../../services/agents'
import { formatDateTime } from '../../utils/format'
import { AgentRunView } from './AgentRunView'

const REVIEW_LABEL = {
  PENDING: 'Sin revisar',
  USEFUL: 'Útil',
  DISCARDED: 'Descartado',
}

/** Agentes IA: qué hace cada agente, estado del modelo e historial de análisis con su revisión. */
export function AgentsPage() {
  const [agent, setAgent] = useState('')
  const [review, setReview] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<string | null>(null)
  const catalog = useQuery({
    queryKey: ['agents-catalog'],
    queryFn: agentsApi.catalog,
  })
  const runs = useQuery({
    queryKey: ['agent-runs', 'list', agent, review, page],
    queryFn: () =>
      agentsApi.runs({
        agent: agent || undefined,
        review_status: review || undefined,
        page,
        per_page: 20,
      }),
    placeholderData: keepPreviousData,
  })
  const model = catalog.data?.data.model
  const rows = runs.data?.data ?? []

  return (
    <>
      <PageHeader title="Agentes IA" subtitle="Análisis de apoyo sobre tus oportunidades y compras. Siempre decide una persona." />

      {model && (
        <Alert severity={model.available ? 'success' : 'info'} sx={{ mb: 2.5 }}>
          {model.available
            ? `Modelo activo: ${model.name}. Quedan ${model.remaining_today} de ${model.daily_limit} análisis con IA hoy para tu empresa.`
            : 'El modelo de IA no está configurado en el servidor. Los agentes entregan solo datos calculados por TIARE (sin texto generado).'}
        </Alert>
      )}

      {catalog.error && <ErrorState error={catalog.error} what="los agentes" />}
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
          mb: 4,
        }}
      >
        {(catalog.data?.data.agents ?? []).map((a) => (
          <Card key={a.id} variant="outlined">
            <CardContent>
              <Stack direction="row" justifyContent="space-between" alignItems="baseline" sx={{ mb: 1 }}>
                <Typography variant="h6">{a.name}</Typography>
                <Typography variant="caption" color="text.secondary">
                  v{a.prompt_version}
                </Typography>
              </Stack>
              <Typography variant="body2" sx={{ mb: 1.5 }}>
                {a.purpose}
              </Typography>
              <Typography variant="subtitle2">Usa</Typography>
              <Box component="ul" sx={{ mt: 0.5, mb: 1.5, pl: 2.5 }}>
                {a.data_used.map((d) => (
                  <Typography component="li" variant="body2" key={d}>
                    {d}
                  </Typography>
                ))}
              </Box>
              <Typography variant="subtitle2">No hace</Typography>
              <Box component="ul" sx={{ mt: 0.5, mb: 1.5, pl: 2.5 }}>
                {a.limits.map((d) => (
                  <Typography component="li" variant="body2" key={d}>
                    {d}
                  </Typography>
                ))}
              </Box>
              <Typography variant="caption" color="text.secondary">
                {a.subject_type === 'compra_agil' ? (
                  <>
                    Se ejecuta desde la ficha de una Compra Ágil en <RouterLink to="/mercado-publico">Mercado Público</RouterLink>.
                  </>
                ) : (
                  <>
                    Se ejecuta desde la pestaña Comparación de una <RouterLink to="/solicitudes">solicitud de compra</RouterLink>.
                  </>
                )}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Box>

      <Typography variant="h6" sx={{ mb: 1.5 }}>
        Historial de análisis
      </Typography>
      <FilterBar count={runs.data?.meta.pagination ? `${runs.data.meta.pagination.total} análisis` : undefined}>
        <SelectField
          label="Agente"
          value={agent}
          onChange={(v) => (setAgent(v), setPage(1))}
          allLabel="Todos"
          options={(catalog.data?.data.agents ?? []).map((a) => ({
            value: a.id,
            label: a.name,
          }))}
        />
        <SelectField
          label="Revisión"
          value={review}
          onChange={(v) => (setReview(v), setPage(1))}
          allLabel="Todas"
          options={Object.entries(REVIEW_LABEL).map(([value, label]) => ({
            value,
            label,
          }))}
        />
      </FilterBar>
      {runs.error && <ErrorState error={runs.error} what="el historial" />}
      {runs.isLoading && <ListSkeleton rows={3} />}
      {!runs.isLoading && rows.length === 0 && <EmptyState title="Aún no hay análisis">Abre una Compra Ágil o una solicitud con cotizaciones y usa "Analizar".</EmptyState>}
      {rows.length > 0 && (
        <DataTable
          columns={[{ label: 'Caso' }, { label: 'Agente', hideOnMobile: true }, { label: 'Fecha', hideOnMobile: true }, { label: 'Revisión', align: 'right' }]}
          rows={rows.map((r) => ({
            key: r.public_id,
            onClick: () => setSelected(r.public_id),
            label: `Abrir análisis de ${r.subject.label ?? r.subject.ref}`,
            cells: [
              <>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {r.subject.label ?? r.subject.ref}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {r.mode === 'llm' ? `Con IA (${r.model ?? 'modelo'})` : 'Sin IA'}
                  {r.status === 'PARTIAL' ? ', modelo no respondió' : ''}
                </Typography>
              </>,
              r.agent.name,
              <Typography variant="caption">{formatDateTime(r.created_at)}</Typography>,
              <ReviewChip status={r.review_status} />,
            ],
          }))}
        />
      )}
      {runs.data?.meta.pagination && <Pager page={runs.data.meta.pagination.page} lastPage={runs.data.meta.pagination.last_page} onChange={setPage} />}
      <RunDrawer runId={selected} onClose={() => setSelected(null)} />
    </>
  )
}

function ReviewChip({ status }: { status: AgentRunSummary['review_status'] }) {
  return (
    <Chip
      size="small"
      label={REVIEW_LABEL[status]}
      sx={status === 'USEFUL' ? { bgcolor: tokens.successContainer } : status === 'DISCARDED' ? { bgcolor: tokens.surfaceHigh } : { bgcolor: tokens.tertiaryContainer }}
    />
  )
}

function RunDrawer({ runId, onClose }: { runId: string | null; onClose: () => void }) {
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['agent-run', runId],
    queryFn: () => agentsApi.run(runId!),
    enabled: Boolean(runId),
  })
  const run = q.data?.data
  return (
    <Drawer open={Boolean(runId)} onClose={onClose} code={run?.agent.name ?? ''} title={run?.subject.label ?? undefined} headingVariant="name">
      {q.isLoading && <ListSkeleton rows={3} />}
      {q.error && <ErrorState error={q.error} what="el análisis" />}
      {run && (
        <AgentRunView
          run={run}
          onReviewed={(r) => {
            qc.setQueryData<{ data: AgentRunDetail; meta: unknown }>(['agent-run', r.public_id], { data: r, meta: q.data?.meta })
          }}
        />
      )}
    </Drawer>
  )
}
