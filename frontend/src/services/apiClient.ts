import type { ApiEnvelope, ApiErrorBody } from '../types/api'

/**
 * Cliente de la API interna TIARE. Sesión Sanctum por cookie (sin tokens en localStorage).
 * Nunca llama a dominios de Mercado Público.
 */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details?: Record<string, string[]>,
    public readonly retryAfterSeconds?: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

const BASE = '/api/v1'

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfReady = false

export async function ensureCsrfCookie(): Promise<void> {
  if (csrfReady && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  csrfReady = true
}

type Query = Record<string, string | number | boolean | Array<string | number> | null | undefined>

export function toQueryString(query?: Query): string {
  if (!query) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === '' || value === false) continue
    if (Array.isArray(value)) {
      if (value.length) params.set(key, value.join(','))
    } else {
      params.set(key, value === true ? '1' : String(value))
    }
  }
  const s = params.toString()
  return s ? `?${s}` : ''
}

export async function apiRequest<T, M = ApiEnvelope<T>['meta']>(
  method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
  path: string,
  options: { query?: Query; body?: unknown; form?: FormData } = {},
): Promise<ApiEnvelope<T, M>> {
  if (method !== 'GET') await ensureCsrfCookie()

  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  // Con FormData el navegador fija Content-Type (multipart con boundary).
  if (options.body !== undefined && !options.form) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf

  let response: Response
  try {
    response = await fetch(`${BASE}${path}${toQueryString(options.query)}`, {
      method,
      headers,
      credentials: 'include',
      body: options.form ?? (options.body !== undefined ? JSON.stringify(options.body) : undefined),
    })
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'No hay conexión con el servidor de TIARE.')
  }

  const text = await response.text()
  const json = text ? (JSON.parse(text) as unknown) : null

  if (!response.ok) {
    const body = json as ApiErrorBody | null
    const retry = Number(response.headers.get('Retry-After'))
    throw new ApiError(
      response.status,
      body?.error?.code ?? 'HTTP_ERROR',
      body?.error?.message ?? `Error ${response.status}`,
      body?.error?.details,
      Number.isFinite(retry) && retry > 0 ? retry : undefined,
    )
  }

  return json as ApiEnvelope<T, M>
}
