import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Card from '@mui/material/Card'
import Checkbox from '@mui/material/Checkbox'
import Chip from '@mui/material/Chip'
import Collapse from '@mui/material/Collapse'
import FormControlLabel from '@mui/material/FormControlLabel'
import LinearProgress from '@mui/material/LinearProgress'
import Rating from '@mui/material/Rating'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Add from '@mui/icons-material/Add'
import ReportProblemOutlined from '@mui/icons-material/ReportProblemOutlined'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { tokens } from '../../app/theme'
import { ErrorState } from '../../components/ListStates'
import { ApiError } from '../../services/apiClient'
import { crmApi } from '../../services/crm'
import type { CriterionKey, EvaluationInput } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatDate, todayInChile } from '../../utils/format'

const CRITERIA: Array<{ key: CriterionKey; label: string }> = [
  { key: 'price_score', label: 'Precio' },
  { key: 'quality_score', label: 'Calidad' },
  { key: 'delivery_score', label: 'Plazo de entrega' },
  { key: 'compliance_score', label: 'Cumplimiento de condiciones' },
  { key: 'service_score', label: 'Atención y reclamos' },
]

const blank = (): EvaluationInput => ({ evaluated_on: todayInChile(), price_score: 0, quality_score: 0, delivery_score: 0, compliance_score: 0, service_score: 0, reference: '', had_incident: false, comment: '' })

/** Evaluaciones de desempeño: resumen por criterio, historial y formulario (requiere suppliers.evaluate). */
export function SupplierEvaluationsPanel({ supplierId, canEvaluate }: { supplierId: string; canEvaluate: boolean }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<EvaluationInput>(blank)
  const [errors, setErrors] = useState<FieldErrors>({})
  const query = useQuery({ queryKey: ['supplier-evaluations', supplierId], queryFn: () => crmApi.evaluations(supplierId) })
  const save = useMutation({
    mutationFn: () => crmApi.createEvaluation(supplierId, { ...form, reference: form.reference || undefined, comment: form.comment || undefined }),
    onSuccess: () => {
      setOpen(false)
      setForm(blank())
      setErrors({})
      void qc.invalidateQueries({ queryKey: ['supplier-evaluations', supplierId] })
      void qc.invalidateQueries({ queryKey: ['suppliers'] })
    },
    onError: (e) => setErrors(e instanceof ApiError ? (e.details ?? {}) : {}),
  })

  const summary = query.data?.meta.summary
  const complete = CRITERIA.every((c) => form[c.key] >= 1) && (!form.had_incident || (form.comment ?? '').trim().length > 0)

  return (
    <Box>
      {query.isLoading && <LinearProgress sx={{ borderRadius: '4px' }} />}
      {query.error && <ErrorState error={query.error} what="las evaluaciones" />}

      {summary && (
        <Card sx={{ p: 2, mb: 2, bgcolor: '#fff' }}>
          {summary.count === 0 ? (
            <Typography variant="body2" color="text.secondary">
              Aún no hay evaluaciones. Registra una después de cada compra o servicio para construir el historial del proveedor.
            </Typography>
          ) : (
            <>
              <Stack direction="row" spacing={2} alignItems="center">
                <Typography className="num" sx={{ fontSize: '2.2rem', fontWeight: 700, color: 'primary.main', lineHeight: 1 }}>
                  {Number(summary.overall).toLocaleString('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
                </Typography>
                <Box>
                  <Rating value={Number(summary.overall)} precision={0.1} readOnly size="small" />
                  <Typography variant="caption" color="text.secondary" component="div">
                    {summary.count} {summary.count === 1 ? 'evaluación' : 'evaluaciones'}
                    {summary.incidents ? `, ${summary.incidents} con incidente` : ''}. Última: {formatDate(`${summary.last_evaluated_on}T12:00:00`)}
                  </Typography>
                </Box>
              </Stack>
              <Stack spacing={0.75} sx={{ mt: 2 }}>
                {CRITERIA.map((c) => {
                  const v = Number(summary.by_criterion[c.key] ?? 0)
                  return (
                    <Box key={c.key} sx={{ display: 'grid', gridTemplateColumns: '170px 1fr 36px', alignItems: 'center', gap: 1 }}>
                      <Typography variant="caption">{c.label}</Typography>
                      <LinearProgress variant="determinate" value={(v / 5) * 100} sx={{ height: 6, borderRadius: '3px', bgcolor: tokens.surfaceHigh }} aria-label={c.label} />
                      <Typography variant="caption" className="num" sx={{ fontWeight: 700, textAlign: 'right' }}>
                        {v.toFixed(1)}
                      </Typography>
                    </Box>
                  )
                })}
              </Stack>
            </>
          )}
          {query.data?.meta.methodology && (
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>
              {query.data.meta.methodology}
            </Typography>
          )}
        </Card>
      )}

      {canEvaluate && !open && (
        <Button variant="outlined" startIcon={<Add />} onClick={() => setOpen(true)} sx={{ mb: 2 }}>
          Registrar evaluación
        </Button>
      )}

      <Collapse in={open} unmountOnExit>
        <Card component="form" sx={{ p: 2, mb: 2, bgcolor: '#fff' }} onSubmit={(e) => (e.preventDefault(), complete && save.mutate())}>
          <Typography variant="subtitle2" sx={{ mb: 1.5 }}>
            Nueva evaluación (1 = deficiente, 5 = excelente)
          </Typography>
          <Stack spacing={1}>
            {CRITERIA.map((c) => (
              <Stack key={c.key} direction="row" alignItems="center" justifyContent="space-between" spacing={1}>
                <Typography variant="body2" id={`lbl-${c.key}`}>
                  {c.label}
                </Typography>
                <Rating
                  name={c.key}
                  value={form[c.key] || null}
                  onChange={(_, v) => setForm({ ...form, [c.key]: v ?? 0 })}
                  aria-labelledby={`lbl-${c.key}`}
                  getLabelText={(v) => `${v} de 5`}
                />
              </Stack>
            ))}
          </Stack>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, mt: 2 }}>
            <TextField
              label="Fecha"
              type="date"
              value={form.evaluated_on}
              onChange={(e) => setForm({ ...form, evaluated_on: e.target.value })}
              InputLabelProps={{ shrink: true }}
              inputProps={{ max: todayInChile() }}
              error={Boolean(fieldError(errors, 'evaluated_on'))}
              helperText={fieldError(errors, 'evaluated_on')}
            />
            <TextField label="Referencia (OC, pedido)" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} inputProps={{ maxLength: 120 }} />
          </Box>
          <FormControlLabel
            sx={{ mt: 1 }}
            control={<Checkbox checked={Boolean(form.had_incident)} onChange={(e) => setForm({ ...form, had_incident: e.target.checked })} />}
            label="Hubo un incidente (atraso, producto defectuoso, reclamo)"
          />
          <TextField
            label={form.had_incident ? 'Describe el incidente' : 'Comentario (opcional)'}
            required={form.had_incident}
            value={form.comment}
            onChange={(e) => setForm({ ...form, comment: e.target.value })}
            multiline
            minRows={2}
            fullWidth
            inputProps={{ maxLength: 2000 }}
            error={Boolean(fieldError(errors, 'comment'))}
            helperText={fieldError(errors, 'comment')}
            sx={{ mt: 1 }}
          />
          {save.error && !Object.keys(errors).length && (
            <Alert severity="error" sx={{ mt: 1.5 }}>
              {save.error instanceof ApiError ? save.error.message : 'No fue posible guardar la evaluación.'}
            </Alert>
          )}
          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 2 }}>
            <Button onClick={() => setOpen(false)}>Cancelar</Button>
            <Button type="submit" variant="contained" disabled={!complete || save.isPending}>
              {save.isPending ? 'Guardando…' : 'Guardar evaluación'}
            </Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
            Las evaluaciones no se editan ni se borran: si te equivocas, registra una nueva.
          </Typography>
        </Card>
      </Collapse>

      <Stack spacing={1}>
        {(query.data?.data ?? []).map((e) => (
          <Card key={e.public_id} sx={{ p: 1.5, bgcolor: '#fff' }}>
            <Stack direction="row" alignItems="center" spacing={1} flexWrap="wrap" useFlexGap>
              <Rating value={Number(e.overall)} precision={0.1} readOnly size="small" />
              <Typography variant="body2" className="num" sx={{ fontWeight: 700 }}>
                {Number(e.overall).toFixed(1)}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {formatDate(`${e.evaluated_on}T12:00:00`)}
                {e.reference ? `, ${e.reference}` : ''}
                {e.evaluated_by ? `, por ${e.evaluated_by}` : ''}
              </Typography>
              {e.had_incident && <Chip size="small" icon={<ReportProblemOutlined />} label="Incidente" color="error" variant="outlined" />}
            </Stack>
            {e.comment && (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75 }}>
                {e.comment}
              </Typography>
            )}
          </Card>
        ))}
      </Stack>
    </Box>
  )
}
