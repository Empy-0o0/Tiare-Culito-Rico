/** Tonos visuales (ver StatusBadge) por estado de solicitud y de orden interna. */
export const REQUEST_TONE: Record<string, string> = {
  DRAFT: 'draft',
  SUBMITTED: 'pending',
  QUOTING: 'progress',
  PENDING_APPROVAL: 'pending',
  APPROVED: 'approved',
  ORDERED: 'completed',
  CLOSED: 'completed',
  CANCELLED: 'rejected',
}

export const ORDER_TONE: Record<string, string> = {
  ISSUED: 'pending',
  SENT: 'progress',
  PARTIALLY_RECEIVED: 'progress',
  RECEIVED: 'approved',
  CANCELLED: 'rejected',
}

/** Pasos visibles del flujo (CLAUDE.md §5.4). */
export const FLOW_STEPS = [
  { code: 'DRAFT', label: 'Solicitud' },
  { code: 'SUBMITTED', label: 'Enviada' },
  { code: 'QUOTING', label: 'Cotización' },
  { code: 'PENDING_APPROVAL', label: 'Aprobación' },
  { code: 'APPROVED', label: 'Aprobada' },
  { code: 'ORDERED', label: 'Orden' },
  { code: 'CLOSED', label: 'Recepción' },
]

export const stepIndex = (status: string) => Math.max(0, FLOW_STEPS.findIndex((s) => s.code === status))

/** Cantidades decimales del backend ("10.0000") en formato legible. */
export const qty = (v: string | number | null | undefined) =>
  v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString('es-CL', { maximumFractionDigits: 4 })
