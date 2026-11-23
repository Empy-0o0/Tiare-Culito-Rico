/** Quita strings vacíos y undefined para no enviar campos en blanco al backend. */
export function compact<T extends object>(obj: T): T {
  return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v !== undefined)) as T
}

/** Para ediciones: los strings vacíos se envían como null (borran el valor) en vez de omitirse. */
export function emptyToNull<T extends object>(obj: T): { [K in keyof T]: T[K] | null } {
  return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== undefined).map(([k, v]) => [k, v === '' ? null : v])) as { [K in keyof T]: T[K] | null }
}
