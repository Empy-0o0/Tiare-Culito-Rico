import Paper from '@mui/material/Paper'
import Table from '@mui/material/Table'
import TableBody from '@mui/material/TableBody'
import TableCell from '@mui/material/TableCell'
import TableContainer from '@mui/material/TableContainer'
import TableHead from '@mui/material/TableHead'
import TableRow from '@mui/material/TableRow'
import type { ReactNode } from 'react'
import { tokens } from '../app/theme'

interface Column {
  label: string
  align?: 'left' | 'right'
  /** Oculta la columna en pantallas angostas. */
  hideOnMobile?: boolean
}

interface Row {
  key: string | number
  cells: ReactNode[]
  /** Si se indica, toda la fila abre el detalle (también con Enter). */
  onClick?: () => void
  label?: string
}

/** Tabla compacta para fichas y listados (MUI). */
export function DataTable({ columns, rows }: { columns: Column[]; rows: Row[] }) {
  const hide = (i: number) => (columns[i]?.hideOnMobile ? { display: { xs: 'none', md: 'table-cell' } } : {})
  return (
    <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: '12px', borderColor: tokens.outlineVariant }}>
      <Table size="small">
        <TableHead>
          <TableRow>
            {columns.map((c, i) => (
              <TableCell key={c.label || i} align={c.align} sx={hide(i)}>
                {c.label}
              </TableCell>
            ))}
          </TableRow>
        </TableHead>
        <TableBody>
          {rows.map((r) => (
            <TableRow
              key={r.key}
              hover
              onClick={r.onClick}
              onKeyDown={r.onClick ? (e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), r.onClick?.()) : undefined}
              tabIndex={r.onClick ? 0 : undefined}
              role={r.onClick ? 'button' : undefined}
              aria-label={r.label}
              sx={{ '&:last-child td': { border: 0 }, cursor: r.onClick ? 'pointer' : undefined, '&:focus-visible': { outline: `2px solid ${tokens.primary}`, outlineOffset: -2 } }}
            >
              {r.cells.map((cell, i) => (
                <TableCell key={i} align={columns[i]?.align} sx={{ verticalAlign: 'top', py: 1.25, ...hide(i) }}>
                  {cell}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  )
}
