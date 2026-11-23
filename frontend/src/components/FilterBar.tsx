import Box from '@mui/material/Box'
import FormControl from '@mui/material/FormControl'
import InputAdornment from '@mui/material/InputAdornment'
import InputLabel from '@mui/material/InputLabel'
import MenuItem from '@mui/material/MenuItem'
import Select from '@mui/material/Select'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Search from '@mui/icons-material/Search'
import type { ReactNode } from 'react'

/** Fila de filtros: búsqueda + selectores + contador. Se apila en móvil. */
export function FilterBar({ children, count }: { children: ReactNode; count?: string }) {
  return (
    <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.25, mb: 2 }}>
      {children}
      {count && (
        <Typography variant="body2" color="text.secondary" className="num" sx={{ ml: { sm: 'auto' } }}>
          {count}
        </Typography>
      )}
    </Box>
  )
}

export function SearchField({ value, onChange, placeholder, label }: { value: string; onChange: (v: string) => void; placeholder: string; label: string }) {
  return (
    <TextField
      type="search"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      inputProps={{ 'aria-label': label, maxLength: 120 }}
      sx={{ flex: '1 1 280px', maxWidth: { sm: 420 } }}
      InputProps={{
        startAdornment: (
          <InputAdornment position="start">
            <Search fontSize="small" />
          </InputAdornment>
        ),
      }}
    />
  )
}

export interface Option {
  value: string
  label: string
}

export function SelectField({ label, value, onChange, options, allLabel, minWidth = 180 }: { label: string; value: string; onChange: (v: string) => void; options: Option[]; allLabel?: string; minWidth?: number }) {
  const id = `sf-${label.replace(/\s+/g, '-').toLowerCase()}`
  return (
    <FormControl size="small" sx={{ minWidth, flex: { xs: '1 1 160px', sm: '0 0 auto' } }}>
      <InputLabel id={id}>{label}</InputLabel>
      <Select labelId={id} label={label} value={value} onChange={(e) => onChange(String(e.target.value))} MenuProps={{ PaperProps: { sx: { maxHeight: 360 } } }}>
        {allLabel !== undefined && <MenuItem value="">{allLabel}</MenuItem>}
        {options.map((o) => (
          <MenuItem key={o.value} value={o.value}>
            {o.label}
          </MenuItem>
        ))}
      </Select>
    </FormControl>
  )
}
