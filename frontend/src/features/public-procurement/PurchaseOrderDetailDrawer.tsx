import Alert from '@mui/material/Alert'
import Button from '@mui/material/Button'
import Chip from '@mui/material/Chip'
import MuiLink from '@mui/material/Link'
import Skeleton from '@mui/material/Skeleton'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import Refresh from '@mui/icons-material/Refresh'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { DetailSection, Drawer, Facts } from '../../components/Drawer'
import { SourceAttribution, StatusBadge } from '../../components/DataBadges'
import { ErrorState } from '../../components/ListStates'
import { publicProcurementApi } from '../../services/publicProcurement'
import { formatAmount, formatDateTime, orderStatusClass } from '../../utils/format'

const TYPE_LABEL: Record<string, string> = { SE: 'Sin emisión automática', CM: 'Convenio Marco' }

/** Ficha de orden de compra pública (API V1 por código). No confundir con órdenes internas de TIARE. */
export function PurchaseOrderDetailDrawer({ code, onClose }: { code: string | null; onClose: () => void }) {
  const qc = useQueryClient()
  const query = useQuery({
    queryKey: ['purchase-order', code],
    queryFn: () => publicProcurementApi.getPurchaseOrder(code as string),
    enabled: Boolean(code),
    retry: false,
  })
  const refresh = async () => {
    if (!code) return
    qc.setQueryData(['purchase-order', code], await publicProcurementApi.getPurchaseOrder(code, true))
    void qc.invalidateQueries({ queryKey: ['purchase-orders'] })
  }

  const o = query.data?.data
  const meta = query.data?.meta
  const money = (v: string | null | undefined) => formatAmount(v ?? null, o?.currency)
  const det = o?.details ?? {}

  return (
    <Drawer
      open={Boolean(code)}
      onClose={onClose}
      code={code ?? ''}
      title={o?.title ?? undefined}
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
      {query.error && <ErrorState error={query.error} what="la orden de compra" />}
      {o && meta && (
        <>
          {meta.stale && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              Mostrando la copia guardada. {meta.warning}
            </Alert>
          )}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
            <StatusBadge label={o.status.label} className={orderStatusClass(o.status.code)} />
            {o.type.label && <Chip size="small" variant="outlined" label={TYPE_LABEL[o.type.label] ?? o.type.label} />}
            {o.supplier_status && <Chip size="small" variant="outlined" label={`Proveedor: ${o.supplier_status}`} />}
          </Stack>
          {o.description && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, whiteSpace: 'pre-line' }}>
              {o.description}
            </Typography>
          )}

          <Facts
            rows={[
              ['Total', <span className="num">{money(o.amounts.total)}</span>],
              ['Neto', <span className="num">{money(o.amounts.net)}</span>],
              ['Impuestos', o.amounts.tax ? <span className="num">{`${money(o.amounts.tax)}${o.amounts.tax_rate ? ` (${Number(o.amounts.tax_rate)} %)` : ''}`}</span> : null],
              ['Emisión', formatDateTime(o.dates.issued_at)],
              ['Envío al proveedor', o.dates.sent_at ? formatDateTime(o.dates.sent_at) : null],
              ['Aceptación', o.dates.accepted_at ? formatDateTime(o.dates.accepted_at) : null],
              ['Cancelación', o.dates.cancelled_at ? formatDateTime(o.dates.cancelled_at) : null],
              ['Comprador', o.buyer.name],
              ['Unidad de compra', o.buyer.unit],
              ['Región del comprador', o.buyer.region],
              ['Proveedor', o.supplier.name ? `${o.supplier.name}${o.supplier.rut ? ` (${o.supplier.rut})` : ''}` : null],
              ['Código de proveedor', o.supplier.code],
              [
                'Licitación asociada',
                o.tender_code && (
                  <MuiLink component={Link} className="code" to={`/mercado-publico/licitaciones?ficha=${encodeURIComponent(o.tender_code)}`} onClick={onClose}>
                    {o.tender_code}
                  </MuiLink>
                ),
              ],
              ['Forma de pago', det.payment_terms as string | null],
              ['Despacho', det.dispatch_type as string | null],
              ['Financiamiento', det.funding as string | null],
              [
                'Calificación del proveedor',
                det.supplier_rating_avg ? `${Number(det.supplier_rating_avg).toLocaleString('es-CL')} (${det.supplier_rating_count ?? 0} evaluaciones)` : null,
              ],
            ]}
          />

          {o.items && o.items.length > 0 && (
            <DetailSection title={`Ítems (${o.items.length})`}>
              <DataTable
                columns={[{ label: 'Producto' }, { label: 'Cantidad', align: 'right' }, { label: 'Precio neto', align: 'right' }, { label: 'Total', align: 'right' }]}
                rows={o.items.map((i) => ({
                  key: i.line_number,
                  cells: [
                    <>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {i.supplier_spec ?? i.buyer_spec ?? '—'}
                      </Typography>
                      {i.buyer_spec && i.supplier_spec && (
                        <Typography variant="caption" color="text.secondary" component="div">
                          Solicitado: {i.buyer_spec}
                        </Typography>
                      )}
                      {i.category && (
                        <Typography variant="caption" color="text.secondary">
                          {i.category}
                        </Typography>
                      )}
                    </>,
                    <span className="num">
                      {i.quantity ?? '—'} {i.unit}
                    </span>,
                    <span className="num">{formatAmount(i.unit_net_price, i.currency ?? o.currency)}</span>,
                    <span className="num">{formatAmount(i.total, i.currency ?? o.currency)}</span>,
                  ],
                }))}
              />
            </DetailSection>
          )}

          <SourceAttribution source={meta.source} updatedAt={o.freshness.detail_fetched_at ?? o.freshness.last_fetched_at} />
        </>
      )}
    </Drawer>
  )
}
