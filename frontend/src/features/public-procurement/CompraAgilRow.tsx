import Chip from '@mui/material/Chip'
import { StatusBadge } from '../../components/DataBadges'
import { ResultCard } from '../../components/ResultCard'
import type { CompraAgil } from '../../types/api'
import { closingInfo, formatDateTime, formatMoney } from '../../utils/format'

/** Tarjeta de Compra Ágil: código, estado, organismo, monto y plazo de cierre. */
export function CompraAgilRow({ item, selected, onOpen }: { item: CompraAgil; selected?: boolean; onOpen: (code: string) => void }) {
  const closing = item.status.code === 'publicada' ? closingInfo(item.dates.closes_at) : null

  return (
    <ResultCard
      code={item.code}
      status={<StatusBadge code={item.status.code} label={item.status.label} />}
      tags={item.call_number === 2 ? <Chip size="small" variant="outlined" label="Segundo llamado" /> : undefined}
      title={item.title}
      subtitle={`${item.buyer.name ?? 'Organismo no informado'}${item.buyer.region_name ? `, ${item.buyer.region_name}` : ''}`}
      amount={formatMoney(item.budget.available_clp ?? item.budget.available, item.budget.currency ?? 'CLP')}
      amountLabel={item.offers_count != null ? `${item.offers_count} ofertas recibidas` : 'Monto disponible'}
      closing={closing}
      dateText={formatDateTime(item.dates.closes_at)}
      dateLabel="Cierre"
      selected={selected}
      onOpen={() => onOpen(item.code)}
    />
  )
}
