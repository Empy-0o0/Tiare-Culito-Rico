/** Validación de RUT chileno (módulo 11). Espejo de App\Support\ChileanRut; el backend valida siempre. */
export function cleanRut(rut: string): string {
  return rut.replace(/[^0-9kK]/g, '').toUpperCase()
}

export function rutCheckDigit(body: string): string {
  let sum = 0
  let factor = 2
  for (let i = body.length - 1; i >= 0; i--) {
    sum += Number(body[i]) * factor
    factor = factor === 7 ? 2 : factor + 1
  }
  const dv = 11 - (sum % 11)
  return dv === 11 ? '0' : dv === 10 ? 'K' : String(dv)
}

export function isValidRut(rut: string): boolean {
  const m = /^(\d{7,8})([0-9K])$/.exec(cleanRut(rut))
  return Boolean(m && rutCheckDigit(m[1]) === m[2])
}

export function formatRut(rut: string): string {
  const clean = cleanRut(rut)
  if (clean.length < 2) return rut
  const body = clean.slice(0, -1).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${body}-${clean.slice(-1)}`
}
