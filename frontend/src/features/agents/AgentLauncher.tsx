import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import AutoAwesomeOutlined from '@mui/icons-material/AutoAwesomeOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { ApiError } from '../../services/apiClient'
import { agentsApi, type AgentId, type AgentRunDetail } from '../../services/agents'
import { AgentRunView } from './AgentRunView'

/**
 * Bloque "Analizar con IA" para una ficha (Compra Ágil o solicitud de compra).
 * Muestra el último análisis del caso y permite pedir uno nuevo.
 */
export function AgentLauncher({ agent, subjectType, subjectRef }: { agent: AgentId; subjectType: 'compra_agil' | 'purchase_request'; subjectRef: string }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const catalog = useQuery({
    queryKey: ['agents-catalog'],
    queryFn: agentsApi.catalog,
    staleTime: 60_000,
    enabled: can('agents.read'),
  })
  const last = useQuery({
    queryKey: ['agent-runs', 'last', agent, subjectRef],
    queryFn: () =>
      agentsApi.runs({
        agent,
        subject_type: subjectType,
        subject_ref: subjectRef,
        per_page: 1,
      }),
    enabled: can('agents.read'),
  })
  const [startedId, setStartedId] = useState<string | null>(null)
  const shownId = startedId ?? last.data?.data[0]?.public_id
  const detail = useQuery({
    queryKey: ['agent-run', shownId],
    queryFn: () => agentsApi.run(shownId!),
    enabled: Boolean(shownId),
  })

  const start = useMutation({
    mutationFn: (useModel: boolean) => agentsApi.start(agent, subjectRef, useModel),
    onSuccess: (d) => {
      setError(null)
      qc.setQueryData(['agent-run', d.data.public_id], {
        data: d.data,
        meta: { request_id: '' },
      })
      setStartedId(d.data.public_id)
      void qc.invalidateQueries({ queryKey: ['agent-runs'] })
      void qc.invalidateQueries({ queryKey: ['agents-catalog'] })
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.details ?? {}).flat()[0] ?? e.message) : 'No fue posible ejecutar el análisis.'),
  })

  if (!can('agents.read')) return null
  const info = catalog.data?.data.agents.find((a) => a.id === agent)
  const model = catalog.data?.data.model
  const canRun = info?.can_run ?? false
  const withModel = Boolean(model?.available && model.remaining_today > 0)
  const run = detail.data?.data

  return (
    <Box>
      <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between" sx={{ mb: 1.5, flexWrap: 'wrap', rowGap: 1 }}>
        <Typography variant="body2" color="text.secondary" sx={{ flex: '1 1 240px' }}>
          {info?.purpose ?? 'Análisis de apoyo con datos del caso.'}
        </Typography>
        {canRun && (
          <Button variant={run ? 'outlined' : 'contained'} startIcon={<AutoAwesomeOutlined />} disabled={start.isPending} onClick={() => start.mutate(withModel)}>
            {run ? 'Analizar de nuevo' : withModel ? 'Analizar con IA' : 'Analizar sin IA'}
          </Button>
        )}
      </Stack>
      {model && !model.available && canRun && (
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 1 }}>
          El modelo de IA no está configurado en el servidor: el análisis muestra solo datos calculados.
        </Typography>
      )}
      {model?.available && model.remaining_today === 0 && (
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 1 }}>
          Tu empresa usó los {model.daily_limit} análisis con IA de hoy; puedes analizar sin IA.
        </Typography>
      )}
      {start.isPending && (
        <Box sx={{ mb: 2 }}>
          <LinearProgress sx={{ borderRadius: '4px' }} />
          <Typography variant="caption" color="text.secondary">
            {withModel ? 'Analizando con IA, puede tomar hasta un minuto…' : 'Calculando…'}
          </Typography>
        </Box>
      )}
      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      {run && !start.isPending && (
        <AgentRunView
          run={run}
          onReviewed={(r) => {
            qc.setQueryData<{
              data: AgentRunDetail
              meta: { request_id: string }
            }>(['agent-run', r.public_id], {
              data: r,
              meta: { request_id: '' },
            })
          }}
        />
      )}
      {!run && !last.isLoading && !start.isPending && !canRun && (
        <Typography variant="body2" color="text.secondary">
          Aún no hay análisis para este caso.
        </Typography>
      )}
    </Box>
  )
}
