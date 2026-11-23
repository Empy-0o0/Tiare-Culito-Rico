/** Reglas visibles de la política de contraseñas (la validación real ocurre en el backend). */
export function passwordChecks(pw: string, current: string, confirm: string) {
  return [
    { ok: pw.length >= 10, label: 'Al menos 10 caracteres' },
    { ok: /[A-Za-z]/.test(pw), label: 'Incluye letras' },
    { ok: /\d/.test(pw), label: 'Incluye números' },
    { ok: pw.length > 0 && pw !== current, label: 'Distinta de la contraseña actual' },
    { ok: pw.length > 0 && pw === confirm, label: 'La confirmación coincide' },
  ]
}
