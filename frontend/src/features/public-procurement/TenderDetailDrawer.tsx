import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import MuiLink from '@mui/material/Link'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import AccessTime from '@mui/icons-material/AccessTime'
import OpenInNew from '@mui/icons-material/OpenInNew'
import Refresh from '@mui/icons-material/Refresh'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { SourceAttribution, StatusBadge } from '../../components/DataBadges'
import { ErrorState } from '../../components/ListStates'
import { publicProcurementApi } from '../../services/publicProcurement'
import { closingInfo, formatAmount, formatDateTime, tenderStatusClass } from '../../utils/format'
import { OpportunityDocumentsSection } from './OpportunityDocumentsSection'

const yesNo = (v: boolean | null | undefined) => (v === null || v === undefined ? null : v ? 'Sí' : 'No')

const MILESTONES: Array<[string, string]> = [
  ['created_at', 'Creación'],
  ['questions_start_at', 'Inicio de preguntas'],
  ['questions_end_at', 'Cierre de preguntas'],
  ['answers_published_at', 'Publicación de respuestas'],
  ['site_visit_at', 'Visita a terreno'],
  ['documents_due_at', 'Entrega de antecedentes'],
  ['technical_opening_at', 'Apertura técnica'],
  ['economic_opening_at', 'Apertura económica'],
  ['estimated_award_at', 'Adjudicación estimada'],
  ['estimated_signature_at', 'Firma estimada'],
]

/** Línea de tiempo vertical de hitos; el próximo hito se destaca. */
function Milestones({ items }: { items: Array<{ label: string; at: string }> }) {
  const now = Date.now()
  const next = items.findIndex((m) => new Date(m.at).getTime() > now)
  return (
    <Box component="ol" sx={{ listStyle: 'none', m: 0, p: 0, borderLeft: `2px solid ${tokens.outlineVariant}`, ml: 1 }}>
      {items.map((m, i) => {
        const past = new Date(m.at).getTime() <= now
        const isNext = i === next
        return (
          <Box component="li" key={`${m.label}-${m.at}`} sx={{ position: 'relative', pl: 2.5, pb: 1.75 }}>
            <Box
              sx={{
                position: 'absolute',
                left: -7,
                top: 4,
                width: 12,
                height: 12,
                borderRadius: '50%',
                border: `2px solid ${isNext ? tokens.urgent : past ? tokens.primary : tokens.outline}`,
                bgcolor: isNext ? tokens.urgent : past ? tokens.primary : '#fff',
              }}
            />
            <Typography variant="body2" sx={{ fontWeight: isNext ? 700 : 500, color: isNext ? tokens.urgent : undefined }}>
              {m.label}
              {isNext ? ' (próximo)' : ''}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {formatDateTime(m.at)}
            </Typography>
          </Box>
        )
      })}
    </Box>
  )
}

/** Ficha de licitación (API V1 por código). */
export function TenderDetailDrawer({ code, onClose }: { code: string | null; onClose: () => void }) {
  const qc = useQueryClient()
  const query = useQuery({
    queryKey: ['tender', code],
    queryFn: () => publicProcurementApi.getTender(code as string),
    enabled: Boolean(code),
    retry: false,
  })
  const refresh = async () => {
    if (!code) return
    qc.setQueryData(['tender', code], await publicProcurementApi.getTender(code, true))
    void qc.invalidateQueries({ queryKey: ['tenders'] })
  }

  const t = query.data?.data
  const meta = query.data?.meta
  const closing = t?.status.code === 5 ? closingInfo(t.dates.closes_at) : null
  const d = t?.details

  const milestones = [
    ...MILESTONES.filter(([k]) => d?.dates?.[k]).map(([k, label]) => ({ label, at: d!.dates![k] })),
    ...(t?.dates.published_at ? [{ label: 'Publicación', at: t.dates.published_at }] : []),
    ...(t?.dates.closes_at ? [{ label: 'Cierre de ofertas', at: t.dates.closes_at }] : []),
    ...(t?.dates.awarded_at ? [{ label: 'Adjudicación', at: t.dates.awarded_at }] : []),
  ].sort((a, b) => a.at.localeCompare(b.at))

  return (
    <Drawer
      open={Boolean(code)}
      onClose={onClose}
      code={code ?? ''}
      title={t?.title}
      footer={
        <Button variant="outlined" startIcon={<Refresh />} onClick={() => void refresh()} disabled={query.isFetching}>
          {query.isFetching ? 'Consultando…' : 'Actualizar desde Mercado Público'}
        </Button>
      }
    >
      {query.isLoading && (
        <Stack spacing={1.5}>
          <Skeleton variant="rounded" height={32} width="60%" />
          <Skeleton variant="rounded" height={200} />
        </Stack>
      )}
      {query.error && <ErrorState error={query.error} what="la ficha de la licitación" />}
      {t && meta && (
        <>
          {meta.stale && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              Mostrando la copia guardada. {meta.warning}
            </Alert>
          )}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
            <StatusBadge label={t.status.label} className={tenderStatusClass(t.status.code)} />
            {t.type.code && <Chip size="small" variant="outlined" label={t.type.label ? `${t.type.code}: ${t.type.label}` : t.type.code} />}
            {d?.is_public === false && <Chip size="small" variant="outlined" label="Licitación privada" />}
            {closing && closing.tone !== 'closed' && (
              <Chip
                size="small"
                icon={<AccessTime sx={{ fontSize: 16 }} />}
                label={closing.text}
                sx={closing.tone === 'soon' ? { bgcolor: tokens.tertiaryContainer, color: tokens.urgent, '& .MuiChip-icon': { color: tokens.urgent } } : undefined}
              />
            )}
          </Stack>
          {t.description && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, whiteSpace: 'pre-line' }}>
              {t.description}
            </Typography>
          )}

          <Facts
            rows={[
              ['Monto estimado', <span className="num">{formatAmount(t.budget.estimated, t.budget.currency)}</span>],
              ['Estimación', t.budget.estimation],
              ['Cierre de ofertas', formatDateTime(t.dates.closes_at)],
              ['Publicación', formatDateTime(t.dates.published_at)],
              [
                'Organismo',
                t.buyer.name && (
                  <>
                    {t.buyer.name}
                    {t.buyer.code && (
                      <MuiLink component={Link} to={`/mercado-publico/licitaciones?buyer_code=${t.buyer.code}`} onClick={onClose} sx={{ display: 'block', fontWeight: 500, mt: 0.5 }}>
                        Ver sus licitaciones de hoy
                      </MuiLink>
                    )}
                  </>
                ),
              ],
              ['Unidad de compra', t.buyer.unit],
              ['RUT unidad', t.buyer.rut],
              ['Región', t.buyer.region_name],
              ['Dirección de entrega', t.delivery_address],
              ['Duración del contrato', d?.contract_duration],
              ['Modalidad de pago', d?.payment_modality],
              ['Etapas', d?.stages],
              ['Toma de razón (Contraloría)', yesNo(d?.requires_comptroller_review)],
              ['Permite subcontratación', yesNo(d?.subcontracting_allowed)],
              ['Renovable', yesNo(d?.renewable)],
              ['Ampliación automática de plazo', yesNo(d?.auto_extension)],
              ['Reclamos del organismo', d?.complaints_count],
            ]}
          />

          {milestones.length > 0 && (
            <DetailSection title="Hitos del proceso">
              <Milestones items={milestones} />
            </DetailSection>
          )}

          {t.items && t.items.length > 0 && (
            <DetailSection title={`Ítems (${t.items.length})`}>
              <DataTable
                columns={[{ label: 'Producto o servicio' }, { label: 'Cantidad', align: 'right' }, { label: 'Adjudicado a' }]}
                rows={t.items.map((i) => ({
                  key: i.line_number,
                  cells: [
                    <>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {i.name ?? '—'}
                      </Typography>
                      {i.description && (
                        <Typography variant="caption" color="text.secondary" component="div">
                          {i.description}
                        </Typography>
                      )}
                      {i.category && (
                        <Typography variant="caption" color="text.secondary">
                          {i.category}
                        </Typography>
                      )}
                    </>,
                    <span className="num">
                      {i.quantity ?? '—'} {i.unit_of_measure}
                    </span>,
                    i.award ? (
                      <>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {i.award.supplier_name}
                        </Typography>
                        <Typography variant="caption" color="text.secondary" className="num">
                          {i.award.supplier_rut}
                          {i.award.unit_price ? `, ${formatAmount(i.award.unit_price, t.budget.currency)} c/u` : ''}
                        </Typography>
                      </>
                    ) : (
                      <Typography variant="body2" color="text.secondary">
                        —
                      </Typography>
                    ),
                  ],
                }))}
              />
            </DetailSection>
          )}

          {d?.award && (
            <DetailSection title="Adjudicación">
              <Facts
                rows={[
                  ['Acto administrativo', [d.award.act_type, d.award.act_number].filter(Boolean).join(' N° ') || null],
                  ['Fecha del acto', d.award.act_date ? formatDateTime(d.award.act_date) : null],
                  ['Proveedores adjudicados', d.award.awarded_suppliers],
                ]}
              />
              {d.award.record_url && (
                <Button href={d.award.record_url} target="_blank" rel="noopener noreferrer" endIcon={<OpenInNew />} variant="text">
                  Ver acta de adjudicación
                </Button>
              )}
            </DetailSection>
          )}

          <OpportunityDocumentsSection sourceType="licitacion" code={t.code} />

          <Alert severity="info" sx={{ mt: 3 }}>
            Bases, anexos, foro y presentación de ofertas están en mercadopublico.cl. Busca el código <strong className="code">{t.code}</strong>.
          </Alert>
          <SourceAttribution source={meta.source} updatedAt={t.freshness.detail_fetched_at ?? t.freshness.last_fetched_at} />
        </>
      )}
    </Drawer>
  )
}
