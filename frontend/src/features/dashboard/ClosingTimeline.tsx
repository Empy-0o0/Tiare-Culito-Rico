import { useMemo, useState } from 'react'
import type { CompraAgil } from '../../types/api'
import { formatDateTime, formatMoney } from '../../utils/format'

const HOURS = 72
const TZ = 'America/Santiago'

interface Props {
  items: CompraAgil[]
  now: Date
  onOpen: (code: string) => void
}

/**
 * Línea de cierres de las próximas 72 horas. Posición = hora de cierre; altura = monto disponible
 * (escala logarítmica, para que montos chicos sigan visibles). Cobre = cierra en menos de 24 h.
 */
export function ClosingTimeline({ items, now, onOpen }: Props) {
  const [tip, setTip] = useState<{ item: CompraAgil; x: number; y: number } | null>(null)
  const start = now.getTime()
  const end = start + HOURS * 3_600_000

  const marks = useMemo(() => {
    const visible = items.filter((i) => {
      const t = i.dates.closes_at ? new Date(i.dates.closes_at).getTime() : NaN
      return t > start && t <= end
    })
    const amounts = visible.map((i) => Number(i.budget.available_clp ?? i.budget.available ?? 0)).filter((n) => n > 0)
    const maxLog = Math.log10(Math.max(10, ...amounts))
    return visible.map((i) => {
      const t = new Date(i.dates.closes_at as string).getTime()
      const amount = Number(i.budget.available_clp ?? i.budget.available ?? 0)
      return {
        item: i,
        left: ((t - start) / (end - start)) * 100,
        height: 14 + (amount > 0 ? (Math.log10(amount) / maxLog) * 136 : 0),
        soon: t - start < 24 * 3_600_000,
      }
    })
  }, [items, start, end])

  // Medianoches de Chile dentro de la ventana, como divisiones de día.
  const days = useMemo(() => {
    const out: Array<{ left: number; label: string }> = []
    const fmt = new Intl.DateTimeFormat('es-CL', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'short' })
    const hourFmt = new Intl.DateTimeFormat('en-US', { timeZone: TZ, hour: 'numeric', hourCycle: 'h23' })
    for (let t = Math.ceil(start / 3_600_000) * 3_600_000; t <= end; t += 3_600_000) {
      if (Number(hourFmt.format(new Date(t))) === 0) out.push({ left: ((t - start) / (end - start)) * 100, label: fmt.format(new Date(t)) })
    }
    return out
  }, [start, end])

  return (
    <div className="timeline" onMouseLeave={() => setTip(null)}>
      <div className="timeline-track" role="list" aria-label="Compras Ágiles que cierran en las próximas 72 horas">
        {days.map((d) => (
          <div key={d.left} className="timeline-day" style={{ left: `${d.left}%` }}>
            <span>{d.label}</span>
          </div>
        ))}
        <div className="timeline-now" style={{ left: 0 }} />
        {marks.map((m) => (
          <button
            key={m.item.public_id}
            role="listitem"
            className={`timeline-mark${m.soon ? ' soon' : ''}`}
            style={{ left: `${m.left}%`, height: m.height }}
            aria-label={`${m.item.code}: ${m.item.title}. Cierra ${formatDateTime(m.item.dates.closes_at)}`}
            onClick={() => onOpen(m.item.code)}
            onMouseEnter={() => setTip({ item: m.item, x: m.left, y: 168 - m.height })}
            onFocus={() => setTip({ item: m.item, x: m.left, y: 168 - m.height })}
            onBlur={() => setTip(null)}
          />
        ))}
        {tip && (
          <div className="timeline-tip" style={{ left: `clamp(150px, ${tip.x}%, calc(100% - 150px))`, top: tip.y - 8 }}>
            <strong>{tip.item.code}</strong>
            {tip.item.title}
            <br />
            {formatMoney(tip.item.budget.available_clp ?? tip.item.budget.available)}, cierra {formatDateTime(tip.item.dates.closes_at)}
          </div>
        )}
      </div>
      <div className="timeline-axis" />
      <div className="timeline-legend">
        <span>{marks.length} cierres en 72 horas</span>
        <span>{marks.filter((m) => m.soon).length} en menos de 24 horas (cobre)</span>
        <span>Altura de cada barra: monto disponible</span>
      </div>
    </div>
  )
}
