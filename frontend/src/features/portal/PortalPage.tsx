import Alert from '@mui/material/Alert'
import Box from '@mui/material/Box'
import Card from '@mui/material/Card'
import Chip from '@mui/material/Chip'
import LinearProgress from '@mui/material/LinearProgress'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import ApartmentOutlined from '@mui/icons-material/ApartmentOutlined'
import AssignmentOutlined from '@mui/icons-material/AssignmentOutlined'
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined'
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined'
import RequestQuoteOutlined from '@mui/icons-material/RequestQuoteOutlined'
import VerifiedOutlined from '@mui/icons-material/VerifiedOutlined'
import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { DataTable } from '../../components/DataTable'
import { ErrorState } from '../../components/ListStates'
import { PageHeader } from '../../components/PageHeader'
import { portalApi, type PortalProfile } from '../../services/portal'
import { formatDate, formatDateTime, formatMoney } from '../../utils/format'
import { SectionTitle } from '../settings/SectionTitle'

const QUOTE_STATUS: Record<string, string> = { REQUESTED: 'Solicitada', RECEIVED: 'Recibida', DECLINED: 'Declinada' }
const VALIDITY: Record<string, { label: string; color: 'success' | 'warning' | 'error' | 'default' }> = {
  valid: { label: 'Vigente', color: 'success' },
  expiring: { label: 'Por vencer', color: 'warning' },
  expired: { label: 'Vencido', color: 'error' },
  no_expiry: { label: 'Sin vencimiento', color: 'default' },
}
const qty = (v: string | null) => (v === null ? null : Number(v).toLocaleString('es-CL', { maximumFractionDigits: 3 }))

function Section({ icon, title, subtitle, children }: { icon: ReactNode; title: string; subtitle: string; children: ReactNode }) {
  return (
    <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
      <SectionTitle icon={icon} title={title} subtitle={subtitle} />
      {children}
    </Card>
  )
}

function Empty({ text }: { text: string }) {
  return (
    <Typography variant="body2" color="text.secondary">
      {text}
    </Typography>
  )
}

function Organization({ p }: { p: PortalProfile }) {
  const o = p.organization
  const facts: Array<[string, ReactNode]> = [
    ['Razón social', o.legal_name],
    ['Nombre de fantasía', o.trade_name],
    ['RUT', o.rut],
    ['Correo', o.email],
    ['Teléfono', o.phone],
    ['Dirección', [o.address, o.commune, o.region].filter(Boolean).join(', ') || null],
  ]
  return (
    <Section icon={<ApartmentOutlined />} title="Datos de tu organización" subtitle={`Registrados por ${p.company.name}.`}>
      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: '1fr 1fr 1fr' } }}>
        {facts.map(([k, v]) => (
          <Box key={k}>
            <Typography variant="caption" color="text.secondary">
              {k}
            </Typography>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>
              {v || '—'}
            </Typography>
          </Box>
        ))}
      </Box>
      {o.contacts.length > 0 && (
        <>
          <Typography variant="subtitle2" sx={{ mt: 2.5, mb: 1 }}>
            Contactos
          </Typography>
          <DataTable
            columns={[{ label: 'Nombre' }, { label: 'Cargo', hideOnMobile: true }, { label: 'Correo' }, { label: 'Teléfono', hideOnMobile: true }]}
            rows={o.contacts.map((c, i) => ({ key: i, cells: [c.full_name, c.position ?? '—', c.email ?? '—', c.phone ?? '—'] }))}
          />
        </>
      )}
    </Section>
  )
}

/** Portal de cliente o proveedor: solo su propio perfil, en modo lectura (TD-028). */
export function PortalPage() {
  const q = useQuery({ queryKey: ['portal-profile'], queryFn: portalApi.profile })
  if (q.isLoading) return <LinearProgress />
  if (q.error || !q.data) return <ErrorState error={q.error} what="tu perfil" />
  const p = q.data.data
  const isSupplier = p.type === 'supplier'

  return (
    <>
      <PageHeader title={p.organization.legal_name ?? 'Mi perfil'} subtitle={`${isSupplier ? 'Portal de proveedor' : 'Portal de cliente'} · ${p.company.name}`} />
      <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }}>
        {p.status.label && <Chip label={`Estado: ${p.status.label}`} color="primary" variant="outlined" />}
        {p.plan && <Chip label={`Plan ${p.plan}`} variant="outlined" />}
        {isSupplier && p.lead_time_days !== null && p.lead_time_days !== undefined && <Chip label={`Plazo habitual: ${p.lead_time_days} días`} variant="outlined" />}
        {isSupplier && p.payment_terms && <Chip label={`Pago: ${p.payment_terms}`} variant="outlined" />}
      </Stack>
      <Alert severity="info" sx={{ mb: 3 }}>
        {q.data.meta.note}
      </Alert>

      <Organization p={p} />

      {!isSupplier && (
        <Section icon={<AssignmentOutlined />} title="Tus solicitudes de compra" subtitle="Estado de las compras que gestiona la empresa para ti.">
          {(p.purchase_requests ?? []).length === 0 ? (
            <Empty text="Aún no hay solicitudes de compra asociadas." />
          ) : (
            <DataTable
              columns={[{ label: 'N°' }, { label: 'Solicitud' }, { label: 'Estado' }, { label: 'Se necesita', hideOnMobile: true }, { label: 'Estimado', align: 'right', hideOnMobile: true }]}
              rows={(p.purchase_requests ?? []).map((r) => ({
                key: r.number,
                cells: [r.number, r.title, <Chip size="small" label={r.status.label} />, formatDate(r.needed_by), r.estimated_total ? formatMoney(r.estimated_total, r.currency) : '—'],
              }))}
            />
          )}
        </Section>
      )}

      {isSupplier && (
        <>
          <Section icon={<Inventory2Outlined />} title="Insumos, servicios y capacidad" subtitle="Lo que la empresa tiene registrado que entregas.">
            {(p.categories ?? []).length > 0 && (
              <Stack direction="row" spacing={0.75} sx={{ mb: 1.5, flexWrap: 'wrap', rowGap: 0.75 }}>
                {(p.categories ?? []).map((c) => (
                  <Chip key={c} size="small" label={c} />
                ))}
                {(p.coverage_regions ?? []).map((r) => (
                  <Chip key={r} size="small" variant="outlined" label={r} />
                ))}
              </Stack>
            )}
            {(p.offerings ?? []).length === 0 ? (
              <Empty text="Aún no hay insumos ni servicios registrados." />
            ) : (
              <DataTable
                columns={[{ label: 'Insumo o servicio' }, { label: 'Capacidad' }, { label: 'Pedido mínimo', hideOnMobile: true }, { label: 'Plazo', hideOnMobile: true }, { label: 'Confirmada', hideOnMobile: true }]}
                rows={(p.offerings ?? []).map((o, i) => ({
                  key: i,
                  cells: [
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {o.name}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {o.kind}
                      </Typography>
                    </Box>,
                    o.capacity_quantity ? `${qty(o.capacity_quantity)} ${o.unit?.toLowerCase() ?? 'unidades'} ${o.capacity_period ?? ''}` : 'No informada',
                    o.min_order_quantity ? qty(o.min_order_quantity) : '—',
                    o.lead_time_days !== null ? `${o.lead_time_days} días` : '—',
                    o.capacity_confirmed_on ? formatDate(o.capacity_confirmed_on) : 'Sin confirmar',
                  ],
                }))}
              />
            )}
          </Section>

          <Section icon={<VerifiedOutlined />} title="Tus documentos" subtitle="Certificados y documentos registrados, con su vigencia.">
            {(p.documents ?? []).length === 0 ? (
              <Empty text="No hay documentos registrados." />
            ) : (
              <DataTable
                columns={[{ label: 'Documento' }, { label: 'Tipo', hideOnMobile: true }, { label: 'Vence' }, { label: 'Vigencia', align: 'right' }]}
                rows={(p.documents ?? []).map((d, i) => {
                  const v = VALIDITY[d.validity] ?? { label: d.validity, color: 'default' as const }
                  return { key: i, cells: [d.name, d.type, d.expires_on ? formatDate(d.expires_on) : '—', <Chip size="small" color={v.color} variant="outlined" label={v.label} />] }
                })}
              />
            )}
          </Section>

          <Section icon={<RequestQuoteOutlined />} title="Solicitudes de cotización" subtitle="Cotizaciones que te ha pedido la empresa.">
            {(p.quotation_requests ?? []).length === 0 ? (
              <Empty text="No tienes solicitudes de cotización." />
            ) : (
              <DataTable
                columns={[{ label: 'Solicitud' }, { label: 'Estado' }, { label: 'Plazo para cotizar', hideOnMobile: true }, { label: 'Recibida', hideOnMobile: true }]}
                rows={(p.quotation_requests ?? []).map((r, i) => ({
                  key: i,
                  cells: [`${r.request_number ?? ''} · ${r.title ?? ''}`, <Chip size="small" label={QUOTE_STATUS[r.status] ?? r.status} />, formatDate(r.due_on), formatDateTime(r.created_at)],
                }))}
              />
            )}
          </Section>

          <Section icon={<ReceiptLongOutlined />} title="Órdenes de compra" subtitle="Órdenes emitidas a tu nombre.">
            {(p.purchase_orders ?? []).length === 0 ? (
              <Empty text="No tienes órdenes de compra." />
            ) : (
              <DataTable
                columns={[{ label: 'N°' }, { label: 'Estado' }, { label: 'Total', align: 'right' }, { label: 'Emitida', hideOnMobile: true }, { label: 'Entrega esperada', hideOnMobile: true }]}
                rows={(p.purchase_orders ?? []).map((o) => ({
                  key: o.number,
                  cells: [o.number, <Chip size="small" label={o.status.label} />, formatMoney(o.total, o.currency), formatDateTime(o.issued_at), formatDate(o.expected_delivery_on)],
                }))}
              />
            )}
          </Section>
        </>
      )}
    </>
  )
}
