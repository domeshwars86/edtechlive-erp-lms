export type ApiUser = { id: number; name: string; email: string; email_verified_at: string | null }
export type OrganizationMembership = {
  membership_id: number
  role: 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'independent_educator'
  organization: { id: number; name: string; slug: string; type: string; status: string; timezone: string }
}
export type Course = { id: number; title: string; level: string | null; description: string | null; status: string; subject: { id: number; name: string } | null; teacher: { id: number; name: string } | null }
export type Lesson = { id: number; title: string; sort_order: number; content: string | null; external_url: string | null; status: 'draft' | 'published' }
export type DashboardSummary = Record<string, unknown> & { kind?: string }

const baseUrl = (process.env.EXPO_PUBLIC_API_URL ?? '').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(message: string, readonly status: number, readonly code?: string) {
    super(message)
    this.name = 'ApiError'
  }
}

async function request<T>(path: string, token?: string, options: RequestInit = {}): Promise<T> {
  if (!baseUrl) throw new ApiError('Set EXPO_PUBLIC_API_URL to the reachable Laravel API address.', 0)
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  let response: Response
  try {
    response = await fetch(`${baseUrl}${path}`, { ...options, headers })
  } catch {
    throw new ApiError('The account service could not be reached. Check the API address and connection.', 0)
  }
  const body = await response.json().catch(() => ({})) as T & { message?: string; status?: string; errors?: Record<string, string[]> }
  if (!response.ok) {
    const validation = body.errors ? Object.values(body.errors).flat()[0] : undefined
    throw new ApiError(validation || body.message || 'The request could not be completed.', response.status, body.status)
  }
  return body
}

export const mobileApi = {
  login: (email: string, password: string) => request<{ data: ApiUser; token: string; expires_at: string }>('/api/v1/auth/login', undefined, {
    method: 'POST', body: JSON.stringify({ email, password, device_name: 'edTechLive mobile' }),
  }),
  get: <T>(path: string, token: string) => request<T>(path, token),
  post: <T>(path: string, token: string) => request<T>(path, token, { method: 'POST' }),
}
