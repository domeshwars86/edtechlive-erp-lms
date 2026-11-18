const apiBaseUrl = (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/$/, '')

type ApiErrorBody = {
  message?: string
  errors?: Record<string, string[]>
  status?: string
}

export class ApiError extends Error {
  status: number
  code?: string

  constructor(message: string, status: number, code?: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

function xsrfCookie(): string | undefined {
  if (typeof document === 'undefined') return undefined
  const value = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')
  return value ? decodeURIComponent(value) : undefined
}

async function ensureCsrfCookie(): Promise<void> {
  const response = await fetch(`${apiBaseUrl}/sanctum/csrf-cookie`, { credentials: 'include' })
  if (!response.ok) throw new ApiError('Could not prepare a secure session. Please try again.', response.status)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method || 'GET').toUpperCase()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')

  let response: Response
  try {
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      await ensureCsrfCookie()
      const token = xsrfCookie()
      if (token) headers.set('X-XSRF-TOKEN', token)
    }

    response = await fetch(`${apiBaseUrl}${path}`, {
      ...options,
      method,
      headers,
      credentials: 'include',
    })
  } catch (reason) {
    if (reason instanceof ApiError) throw reason
    throw new ApiError('The account service could not be reached. Check your connection and try again.', 0)
  }

  const body = await response.json().catch(() => ({})) as ApiErrorBody & T
  if (!response.ok) {
    const validationMessage = body.errors ? Object.values(body.errors).flat()[0] : undefined
    throw new ApiError(validationMessage || body.message || 'The request could not be completed.', response.status, body.status)
  }
  return body as T
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, data?: unknown) => request<T>(path, {
    method: 'POST',
    body: data === undefined ? undefined : JSON.stringify(data),
  }),
}

export type ApiUser = {
  id: number
  name: string
  email: string
  email_verified_at: string | null
}

export type OrganizationMembership = {
  membership_id: number
  role: 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'independent_educator'
  organization: {
    id: number
    name: string
    slug: string
    type: string
    status: string
    timezone: string
  }
}

export type OrganizationListResponse = {
  data: OrganizationMembership[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
