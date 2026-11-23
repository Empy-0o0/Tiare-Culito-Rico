import { apiRequest } from './apiClient'
import type { ApiMeta } from '../types/api'

export type OpportunitySourceType = 'compra_agil' | 'licitacion'

export interface OpportunityFileDto {
  public_id: string
  name: string
  official_document_id: string | null
  file: { name: string; mime_type: string; size_bytes: number }
  download_url: string
  uploaded_by: string | null
  uploaded_at: string | null
  data_origin: 'tenant'
}

export interface OpportunityFilesMeta extends ApiMeta {
  accepted: string
  official_download_available: boolean
  official_download_note: string
  portal_url: string
  can_upload: boolean
  /** "Descargar todo" en ZIP; null si aún no hay copias (TD-030). */
  archive_url: string | null
}

/** Documentos de un proceso: adjuntos oficiales (solo nombre) + copias que la empresa subió. */
export const opportunityFilesApi = {
  list: (sourceType: OpportunitySourceType, code: string) =>
    apiRequest<{ official: { id: string; name: string | null }[]; files: OpportunityFileDto[] }, OpportunityFilesMeta>('GET', '/public-procurement/files', { query: { source_type: sourceType, code } }),
  upload: (sourceType: OpportunitySourceType, code: string, data: { file: File; name?: string; official_document_id?: string }) => {
    const form = new FormData()
    form.append('source_type', sourceType)
    form.append('code', code)
    form.append('file', data.file)
    if (data.name) form.append('name', data.name)
    if (data.official_document_id) form.append('official_document_id', data.official_document_id)
    return apiRequest<OpportunityFileDto & { duplicate: boolean; matched: { id: string; score: number } | null }>('POST', '/public-procurement/files', { form })
  },
  remove: (publicId: string) => apiRequest<{ deleted: boolean }>('DELETE', `/public-procurement/files/${encodeURIComponent(publicId)}`),
}
