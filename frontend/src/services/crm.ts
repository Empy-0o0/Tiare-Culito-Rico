import { apiRequest } from './apiClient'
import type { CreatedPortalMeta } from './portal'
import type {
  CategoryDto,
  ContactInput,
  CustomerDto,
  DocumentsMeta,
  EvaluationInput,
  EvaluationsMeta,
  OrganizationInput,
  ProductDto,
  ProductInput,
  References,
  SupplierDocumentDto,
  SupplierDto,
  SupplierEvaluationDto,
} from '../types/crm'
import type { OfferingsMeta, SupplierOfferingDto, SupplierOfferingInput } from '../types/crm'

type Page = { q?: string; status?: string; page?: number; per_page?: number }

export interface SupplierInput {
  organization?: Partial<OrganizationInput>
  contact?: ContactInput
  status?: string
  category_public_ids?: string[]
  coverage_regions?: number[]
  lead_time_days?: number | null
  payment_terms?: string | null
  notes?: string | null
}

const id = (v: string) => encodeURIComponent(v)

export const crmApi = {
  references: () => apiRequest<References>('GET', '/references'),

  customers: (f: Page) => apiRequest<CustomerDto[]>('GET', '/customers', { query: f }),
  customer: (publicId: string) => apiRequest<CustomerDto>('GET', `/customers/${id(publicId)}`),
  createCustomer: (body: { organization: OrganizationInput; contact?: ContactInput; plan?: string; status?: string; portal_access?: { email: string } }) =>
    apiRequest<CustomerDto, CreatedPortalMeta>('POST', '/customers', { body }),
  updateCustomer: (publicId: string, body: { organization?: Partial<OrganizationInput>; plan?: string | null; status?: string; next_action_summary?: string | null }) =>
    apiRequest<CustomerDto>('PATCH', `/customers/${id(publicId)}`, { body }),

  offerings: (supplierId: string) => apiRequest<SupplierOfferingDto[], OfferingsMeta>('GET', `/suppliers/${id(supplierId)}/offerings`),
  createOffering: (supplierId: string, body: SupplierOfferingInput) => apiRequest<SupplierOfferingDto>('POST', `/suppliers/${id(supplierId)}/offerings`, { body }),
  updateOffering: (supplierId: string, offeringId: string, body: Partial<SupplierOfferingInput> & { is_active?: boolean }) =>
    apiRequest<SupplierOfferingDto>('PATCH', `/suppliers/${id(supplierId)}/offerings/${id(offeringId)}`, { body }),
  deleteOffering: (supplierId: string, offeringId: string) => apiRequest<{ deleted: boolean }>('DELETE', `/suppliers/${id(supplierId)}/offerings/${id(offeringId)}`),

  deleteCustomer: (publicId: string) => apiRequest<{ deleted: boolean }>('DELETE', `/customers/${id(publicId)}`),
  deleteSupplier: (publicId: string) => apiRequest<{ deleted: boolean }>('DELETE', `/suppliers/${id(publicId)}`),

  suppliers: (f: Page & { region?: number; category_public_id?: string }) => apiRequest<SupplierDto[]>('GET', '/suppliers', { query: f }),
  supplier: (publicId: string) => apiRequest<SupplierDto>('GET', `/suppliers/${id(publicId)}`),
  createSupplier: (body: SupplierInput & { organization: OrganizationInput; portal_access?: { email: string } }) => apiRequest<SupplierDto, CreatedPortalMeta>('POST', '/suppliers', { body }),
  updateSupplier: (publicId: string, body: SupplierInput) => apiRequest<SupplierDto>('PATCH', `/suppliers/${id(publicId)}`, { body }),

  evaluations: (supplierId: string) => apiRequest<SupplierEvaluationDto[], EvaluationsMeta>('GET', `/suppliers/${id(supplierId)}/evaluations`),
  createEvaluation: (supplierId: string, body: EvaluationInput) =>
    apiRequest<SupplierEvaluationDto, EvaluationsMeta>('POST', `/suppliers/${id(supplierId)}/evaluations`, { body }),

  documents: (supplierId: string) => apiRequest<SupplierDocumentDto[], DocumentsMeta>('GET', `/suppliers/${id(supplierId)}/documents`),
  uploadDocument: (supplierId: string, data: { file: File; type: string; name: string; issued_on?: string; expires_on?: string }) => {
    const form = new FormData()
    form.append('file', data.file)
    form.append('type', data.type)
    form.append('name', data.name)
    if (data.issued_on) form.append('issued_on', data.issued_on)
    if (data.expires_on) form.append('expires_on', data.expires_on)
    return apiRequest<SupplierDocumentDto>('POST', `/suppliers/${id(supplierId)}/documents`, { form })
  },
  archiveDocument: (supplierId: string, documentId: string, archived = true) =>
    apiRequest<SupplierDocumentDto>('PATCH', `/suppliers/${id(supplierId)}/documents/${id(documentId)}`, { body: { archived } }),

  categories: () => apiRequest<CategoryDto[]>('GET', '/categories'),
  createCategory: (name: string) => apiRequest<CategoryDto>('POST', '/categories', { body: { name } }),

  products: (f: Page & { kind?: string; category_public_id?: string }) => apiRequest<ProductDto[]>('GET', '/products', { query: f }),
  createProduct: (body: ProductInput) => apiRequest<ProductDto>('POST', '/products', { body }),
  updateProduct: (publicId: string, body: { [K in keyof ProductInput]?: ProductInput[K] | null } & { is_active?: boolean }) => apiRequest<ProductDto>('PATCH', `/products/${id(publicId)}`, { body }),
}
