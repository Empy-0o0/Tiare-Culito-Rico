export type FieldErrors = Record<string, string[] | undefined>

/** Primer mensaje de validación del backend para un campo, o undefined. */
export const fieldError = (errors: FieldErrors, name: string): string | undefined => errors[name]?.[0]
