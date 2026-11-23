import Box from '@mui/material/Box'
import MenuItem from '@mui/material/MenuItem'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import type { ReactNode } from 'react'
import type { Catalogs } from '../../types/api'
import type { ContactInput, OrganizationInput, References } from '../../types/crm'
import { fieldError, type FieldErrors } from '../../utils/fieldError'
import { formatRut, isValidRut } from '../../utils/rut'


/** Grilla de formulario de dos columnas (una en móvil). */
export function FormGrid({ children }: { children: ReactNode }) {
  return <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>{children}</Box>
}

export function FormSectionTitle({ children }: { children: ReactNode }) {
  return (
    <Typography variant="subtitle2" color="primary" sx={{ gridColumn: '1 / -1', mt: 1 }}>
      {children}
    </Typography>
  )
}

interface Props {
  org: OrganizationInput
  contact: ContactInput
  onOrg: (o: OrganizationInput) => void
  onContact: (c: ContactInput) => void
  references?: References
  regions?: Catalogs['regions']
  errors: FieldErrors
  /** En edición el contacto principal no se modifica desde aquí. */
  hideContact?: boolean
}

/** Campos comunes de organización y contacto principal (clientes y proveedores). */
export function OrganizationFields({ org, contact, onOrg, onContact, references, regions, errors, hideContact = false }: Props) {
  const rutInvalid = Boolean(org.rut && !isValidRut(org.rut))
  const rutError = rutInvalid ? 'RUT no válido: revisa el dígito verificador.' : fieldError(errors, 'organization.rut')

  return (
    <FormGrid>
      <FormSectionTitle>Organización</FormSectionTitle>
      <TextField
        label="Razón social"
        required
        autoFocus
        value={org.legal_name}
        onChange={(e) => onOrg({ ...org, legal_name: e.target.value })}
        inputProps={{ maxLength: 200 }}
        error={Boolean(fieldError(errors, 'organization.legal_name'))}
        helperText={fieldError(errors, 'organization.legal_name')}
        sx={{ gridColumn: '1 / -1' }}
      />
      <TextField
        label="RUT"
        placeholder="12.345.678-5"
        value={org.rut ?? ''}
        onChange={(e) => onOrg({ ...org, rut: e.target.value })}
        onBlur={() => org.rut && isValidRut(org.rut) && onOrg({ ...org, rut: formatRut(org.rut) })}
        inputProps={{ maxLength: 14 }}
        error={Boolean(rutError)}
        helperText={rutError ?? 'Opcional. Se valida el dígito verificador.'}
      />
      <TextField select label="Tipo de organización" required value={org.kind} onChange={(e) => onOrg({ ...org, kind: e.target.value })}>
        {(references?.organization_kinds ?? []).map((k) => (
          <MenuItem key={k.code} value={k.code}>
            {k.label}
          </MenuItem>
        ))}
      </TextField>
      <TextField label="Segmento o rubro" value={org.segment ?? ''} onChange={(e) => onOrg({ ...org, segment: e.target.value })} inputProps={{ maxLength: 80 }} />
      <TextField select label="Región" value={org.region_code ?? ''} onChange={(e) => onOrg({ ...org, region_code: e.target.value ? Number(e.target.value) : null })}>
        <MenuItem value="">Sin región</MenuItem>
        {(regions ?? []).map((r) => (
          <MenuItem key={r.code} value={r.code}>
            {r.name}
          </MenuItem>
        ))}
      </TextField>
      <TextField
        label="Correo de la empresa"
        type="email"
        value={org.email ?? ''}
        onChange={(e) => onOrg({ ...org, email: e.target.value })}
        error={Boolean(fieldError(errors, 'organization.email'))}
        helperText={fieldError(errors, 'organization.email')}
      />
      <TextField
        label="Teléfono"
        placeholder="+56 9 ..."
        value={org.phone ?? ''}
        onChange={(e) => onOrg({ ...org, phone: e.target.value })}
        error={Boolean(fieldError(errors, 'organization.phone'))}
        helperText={fieldError(errors, 'organization.phone')}
      />

      {!hideContact && <>
      <FormSectionTitle>Contacto principal (opcional)</FormSectionTitle>
      <TextField
        label="Nombre y apellido"
        value={contact.full_name}
        onChange={(e) => onContact({ ...contact, full_name: e.target.value })}
        error={Boolean(fieldError(errors, 'contact.full_name'))}
        helperText={fieldError(errors, 'contact.full_name')}
      />
      <TextField
        label="Correo del contacto"
        type="email"
        value={contact.email ?? ''}
        onChange={(e) => onContact({ ...contact, email: e.target.value })}
        error={Boolean(fieldError(errors, 'contact.email'))}
        helperText={fieldError(errors, 'contact.email')}
      />
      </>}
    </FormGrid>
  )
}
