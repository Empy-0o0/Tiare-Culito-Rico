import Alert from '@mui/material/Alert'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import AccessTime from '@mui/icons-material/AccessTime'
import Refresh from '@mui/icons-material/Refresh'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { tokens } from '../../app/theme'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { SourceAttribution, StatusBadge } from '../../components/DataBadges'
import { ErrorState } from '../../components/ListStates'
import { publicProcurementApi } from '../../services/publicProcurement'
import { AgentLauncher } from '../agents/AgentLauncher'
import { OpportunityDocumentsSection } from './OpportunityDocumentsSection'
import { closingInfo, formatDateTime, formatMoney } from '../../utils/format'

/** Ficha de Compra Ágil: estado, cierre, presupuesto, comprador, productos, cotizaciones, documentos y fuente. */
export function CompraAgilDetailDrawer({ code, onClose }: { code: string | null; onClose: () => void }) {
  const qc = useQueryClient()
  const query = useQuery({
    queryKey: ['compra-agil', code],
    queryFn: () => publicProcurementApi.getCompraAgil(code as string),
    enabled: Boolean(code),
    retry: false,
  })

  const refresh = async () => {
    if (!code) return
    qc.setQueryData(['compra-agil', code], await publicProcurementApi.getCompraAgil(code, true))
  }

  const ca = query.data?.data
  const meta = query.data?.meta
  const closing = ca?.status.code === 'publicada' ? closingInfo(ca.dates.closes_at) : null
  const money = (v: string | null) => formatMoney(v, ca?.budget.currency ?? 'CLP')

  return (
    <Drawer
      open={Boolean(code)}
      onClose={onClose}
      code={code ?? ''}
      title={ca?.title}
      footer={
        <Button variant="outlined" startIcon={<Refresh />} onClick={() => void refresh()} disabled={query.isFetching}>
          {query.isFetching ? 'Consultando…' : 'Actualizar desde Mercado Público'}
        </Button>
      }
    >
      {query.isLoading && (
        <Stack spacing={1.5}>
          <Skeleton variant="rounded" height={32} width="60%" />
          <Skeleton variant="rounded" height={160} />
          <Skeleton variant="rounded" height={120} />
        </Stack>
      )}
      {query.error && <ErrorState error={query.error} what="la ficha" />}
      {ca && meta && (
        <>
          {meta.stale && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              Mostrando la copia guardada. {meta.warning}
            </Alert>
          )}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
            <StatusBadge code={ca.status.code} label={ca.status.label} />
            {ca.call_number && <Chip size="small" variant="outlined" label={ca.call_number === 2 ? 'Segundo llamado' : 'Primer llamado'} />}
            {closing && closing.tone !== 'closed' && (
              <Chip
                size="small"
                icon={<AccessTime sx={{ fontSize: 16 }} />}
                label={closing.text}
                sx={closing.tone === 'soon' ? { bgcolor: tokens.tertiaryContainer, color: tokens.urgent, '& .MuiChip-icon': { color: tokens.urgent } } : undefined}
              />
            )}
          </Stack>
          {ca.description && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, whiteSpace: 'pre-line' }}>
              {ca.description}
            </Typography>
          )}

          <Facts
            rows={[
              ['Monto disponible', <span className="num">{money(ca.budget.available_clp ?? ca.budget.available)}</span>],
              ['Tipo de presupuesto', ca.budget.type],
              ['Cierre', formatDateTime(ca.dates.closes_at)],
              ['Publicación', formatDateTime(ca.dates.published_at)],
              ['Cierre segundo llamado', ca.dates.second_call_closes_at ? formatDateTime(ca.dates.second_call_closes_at) : null],
              ['Ofertas recibidas', ca.offers_count],
              ['Organismo', ca.buyer.name],
              ['RUT organismo', ca.buyer.rut],
              ['Unidad de compra', ca.buyer.unit],
              ['Región', ca.buyer.region_name],
              ['Dirección de entrega', ca.delivery?.address],
              ['Plazo de entrega', ca.delivery?.days != null ? `${ca.delivery.days} días` : null],
              ['Orden de compra', !ca.purchase_order.known ? 'Sin verificar' : ca.purchase_order.issued ? `Emitida (ID ${ca.purchase_order.source_id})` : 'No emitida'],
              ['Motivo de cancelación', ca.reasons.cancellation],
              ['Motivo de deserción', ca.reasons.deserted],
            ]}
          />

          {ca.items && ca.items.length > 0 && (
            <DetailSection title={`Productos solicitados (${ca.items.length})`}>
              <DataTable
                columns={[{ label: 'Producto' }, { label: 'Cantidad', align: 'right' }]}
                rows={ca.items.map((i) => ({
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
                      {i.product_code && (
                        <Typography variant="caption" color="text.secondary">
                          Código ONU {i.product_code}
                        </Typography>
                      )}
                    </>,
                    <span className="num">
                      {i.quantity ?? '—'} {i.unit_of_measure}
                    </span>,
                  ],
                }))}
              />
            </DetailSection>
          )}

          {ca.quotes && ca.quotes.length > 0 && (
            <DetailSection title={`Proveedores que cotizan (${ca.quotes.length})`}>
              <DataTable
                columns={[{ label: 'Proveedor' }, { label: 'Neto', align: 'right' }, { label: 'Total', align: 'right' }]}
                rows={ca.quotes.map((q, idx) => ({
                  key: `${q.supplier_rut}-${idx}`,
                  cells: [
                    <>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {q.supplier_name ?? '—'}
                      </Typography>
                      <Stack direction="row" spacing={0.75} alignItems="center">
                        <Typography variant="caption" color="text.secondary">
                          {q.supplier_rut}
                        </Typography>
                        {q.is_small_business && <Chip size="small" label="Empresa de menor tamaño" sx={{ height: 20, fontSize: '0.68rem' }} />}
                      </Stack>
                    </>,
                    <span className="num">{money(q.net_amount)}</span>,
                    <span className="num">{money(q.total_amount)}</span>,
                  ],
                }))}
              />
            </DetailSection>
          )}

          <OpportunityDocumentsSection sourceType="compra_agil" code={ca.code} />

          <DetailSection title="Análisis con agente IA">
            <AgentLauncher agent="tender_intelligence_agent" subjectType="compra_agil" subjectRef={ca.code} />
          </DetailSection>

          <SourceAttribution source={meta.source} updatedAt={ca.freshness.detail_fetched_at ?? ca.freshness.last_fetched_at} />
        </>
      )}
    </Drawer>
  )
}
