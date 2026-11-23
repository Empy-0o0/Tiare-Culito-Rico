import { useSearchParams } from 'react-router-dom'

export const SOURCE_KEYS = ['date', 'source_status', 'buyer_code', 'supplier_code'] as const

/** Lee y escribe filtros en la URL (las fichas y enlaces desde Organismos/Proveedores son compartibles). */
export function useUrlFilters() {
  const [params, setParams] = useSearchParams()
  const get = (k: string) => params.get(k) ?? ''
  const update = (patch: Record<string, string | null>, resetPage = true) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '') next.delete(k)
      else next.set(k, v)
    }
    if (resetPage && !('page' in patch)) next.delete('page')
    setParams(next, { replace: true })
  }
  const hasSourceQuery = SOURCE_KEYS.some((k) => params.get(k))
  return { params, get, update, hasSourceQuery }
}
