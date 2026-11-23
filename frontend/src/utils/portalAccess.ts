import type { PartyType } from '../services/portal'

export const emailOk = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())

/** Qué ve cada portal (se muestra al crear y gestionar el acceso). */
export const PORTAL_SCOPE: Record<PartyType, string> = {
  customer: 'Verá sus datos, contactos y el estado de sus solicitudes de compra.',
  supplier: 'Verá sus datos, insumos y capacidad, documentos, solicitudes de cotización y órdenes de compra. No ve sus evaluaciones.',
}

export function portalFieldsValid(v: { enabled: boolean; email: string }): boolean {
  return !v.enabled || emailOk(v.email)
}
