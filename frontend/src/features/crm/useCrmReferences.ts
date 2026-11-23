import { useQuery } from '@tanstack/react-query'
import { crmApi } from '../../services/crm'
import { publicProcurementApi } from '../../services/publicProcurement'

/** Listas de referencia para formularios (estados, tipos, unidades y regiones). */
export function useCrmReferences() {
  const refs = useQuery({ queryKey: ['references'], queryFn: crmApi.references, staleTime: Infinity })
  // Las regiones provienen del catálogo documentado por ChileCompra.
  const regions = useQuery({ queryKey: ['pp-catalogs'], queryFn: publicProcurementApi.catalogs, staleTime: Infinity, retry: false })

  return { references: refs.data?.data, regions: regions.data?.data.regions }
}
