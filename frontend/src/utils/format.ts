const TZ = 'America/Santiago'

/** Monto en CLP sin decimales (formato es-CL). Acepta strings decimales de la API. */
export function formatMoney(value: string | number | null | undefined, currency = 'CLP'): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(n)) return '—'
  return new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency,
    maximumFractionDigits: currency === 'CLP' ? 0 : 2,
  }).format(n)
}

/** Fecha y hora en zona de negocio (America/Santiago). */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('es-CL', {
    timeZone: TZ,
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d)
}

/** Tiempo restante legible hasta una fecha (para cierres). */
export function timeUntil(iso: string | null | undefined, now: Date = new Date()): string | null {
  if (!iso) return null
  const diffMs = new Date(iso).getTime() - now.getTime()
  if (!Number.isFinite(diffMs)) return null
  if (diffMs <= 0) return 'Cerrado'
  const hours = Math.floor(diffMs / 3_600_000)
  if (hours < 1) return `Cierra en ${Math.max(1, Math.floor(diffMs / 60_000))} min`
  if (hours < 48) return `Cierra en ${hours} h`
  return `Cierra en ${Math.floor(hours / 24)} días`
}

/** Clase visual del prototipo según estado de Compra Ágil. */
export function compraAgilStatusClass(code: string | null | undefined): string {
  switch (code) {
    case 'publicada':
      return 'approved'
    case 'cerrada':
      return 'progress'
    case 'proveedor_seleccionado':
    case 'oc_emitida':
      return 'completed'
    case 'desierta':
    case 'cancelada':
      return 'rejected'
    default:
      return 'draft'
  }
}

/** Solo fecha (dd-mm-aaaa) en hora de Chile. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('es-CL', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' }).format(d)
}

export type ClosingTone = 'soon' | 'normal' | 'closed'

/** Cierre legible con tono visual: "soon" (< 24 h) es la única señal en cobre de la interfaz. */
export function closingInfo(iso: string | null | undefined, now: Date = new Date()): { text: string; tone: ClosingTone } | null {
  if (!iso) return null
  const ms = new Date(iso).getTime() - now.getTime()
  if (!Number.isFinite(ms)) return null
  if (ms <= 0) return { text: 'Cerrada', tone: 'closed' }
  return { text: timeUntil(iso, now) ?? '', tone: ms < 24 * 3_600_000 ? 'soon' : 'normal' }
}

/** Monto con moneda de origen; si la moneda no es ISO válida (p. ej. UTM), se muestra como sufijo. */
export function formatAmount(value: string | number | null | undefined, currency: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const cur = (currency ?? 'CLP').toUpperCase()
  if (['CLP', 'USD', 'EUR'].includes(cur)) return formatMoney(value, cur)
  const n = Number(value)
  if (!Number.isFinite(n)) return '—'
  return `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 2 }).format(n)} ${cur}`
}

/** Estados de licitación V1 (5 Publicada, 6 Cerrada, 7 Desierta, 8 Adjudicada, 18 Revocada, 19 Suspendida). */
export function tenderStatusClass(code: number | null | undefined): string {
  switch (code) {
    case 5:
      return 'approved'
    case 6:
      return 'progress'
    case 8:
      return 'completed'
    case 7:
    case 18:
      return 'rejected'
    case 19:
      return 'pending'
    default:
      return 'draft'
  }
}

/** Estados de OC V1 (4, 5, 6, 9, 12, 13, 14, 15). */
export function orderStatusClass(code: number | null | undefined): string {
  switch (code) {
    case 6:
      return 'approved'
    case 12:
      return 'completed'
    case 9:
      return 'rejected'
    case 4:
    case 13:
      return 'pending'
    case 5:
    case 14:
    case 15:
      return 'progress'
    default:
      return 'draft'
  }
}

/** Hoy en Chile como aaaa-mm-dd (para inputs type=date). */
export function todayInChile(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
}
