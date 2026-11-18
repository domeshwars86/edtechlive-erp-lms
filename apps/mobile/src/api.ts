export type ApiUser = { id: number; name: string; email: string; email_verified_at: string | null }
export type OrganizationMembership = {
  membership_id: number
  role: 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'independent_educator'
  organization: { id: number; name: string; slug: string; type: string; status: string; timezone: string }
}
export type Course = { id: number; title: string; level: string | null; description: string | null; status: string; subject: { id: number; name: string } | null; teacher: { id: number; name: string } | null }
export type Lesson = { id: number; title: string; sort_order: number; content: string | null; external_url: string | null; status: 'draft' | 'published' }
export type AssignmentSubmission = { id: number; assignment_id: number; answer: string; submitted_at: string | null; awarded_points: number | null; feedback: string | null; graded_at: string | null }
export type LearnerSubmission = AssignmentSubmission & { student: { id: number; name: string; admission_number: string | null } }
export type CourseAssignment = { id: number; course_id: number; title: string; instructions: string | null; due_at: string | null; max_points: number; status: 'draft' | 'published'; submission: AssignmentSubmission | null; submissions?: LearnerSubmission[] }
export type QuizQuestion = { id: number; prompt: string; options: string[]; points: number; sort_order: number }
export type QuizAttempt = { id: number; attempt_number: number; answers: Record<number, number> | null; score: number | null; max_score: number | null; started_at: string; submitted_at: string | null }
export type CourseQuiz = { id: number; course_id: number; title: string; instructions: string | null; time_limit_minutes: number | null; max_attempts: number; status: 'draft' | 'published'; questions: QuizQuestion[]; attempts_used: number | null; latest_result: QuizAttempt | null }
export type QuizRun = { attempt: QuizAttempt; quiz: Pick<CourseQuiz, 'id' | 'title' | 'instructions' | 'time_limit_minutes'>; questions: QuizQuestion[] }
export type TeachingAssignment = { id: number; academic_session_id: number; academic_section_id: number; subject_id: number; teacher_id: number; academic_session: { id: number; name: string }; academic_section: { id: number; name: string; academic_class: { id: number; name: string } }; subject: { id: number; name: string } }
export type AttendanceStudent = { id: number; name: string; admission_number: string | null; status: 'present' | 'absent' | 'late' | 'excused' | null; note: string | null }
export type FamilyStudent = { id: number; name: string; admission_number: string | null; academic_class: { id: number; name: string } | null; academic_section: { id: number; name: string } | null; academic_session: { id: number; name: string } | null; relationship?: string; is_primary?: boolean }
export type StudentAttendance = { id: number; date: string; status: 'present' | 'absent' | 'late' | 'excused'; note: string | null; section: { id: number; name: string; class: string }; session: { id: number; name: string } }
export type AssessmentResult = { id: number; title: string; course: string; score: string | null; max_score: string | null; attempt_number?: number; feedback?: string | null; completed_at: string | null }
export type AssessmentResults = { quizzes: AssessmentResult[]; assignments: AssessmentResult[] }
export type StudentProgress = { data: { course_id: number; title: string; lessons: number; completed_lessons: number; completion_percent: number }[]; summary: { courses: number; lessons: number; completed_lessons: number; completion_percent: number } }
export type FamilyFee = { id: number; title: string; amount: string; paid_amount: string; balance: string; due_on: string | null; status: 'unpaid' | 'partial' | 'paid'; student: { id: number; name: string; admission_number: string | null } | null }
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
  post: <T>(path: string, token: string, data?: unknown) => request<T>(path, token, { method: 'POST', ...(data === undefined ? {} : { body: JSON.stringify(data) }) }),
  patch: <T>(path: string, token: string, data: unknown) => request<T>(path, token, { method: 'PATCH', body: JSON.stringify(data) }),
  put: <T>(path: string, token: string, data: unknown) => request<T>(path, token, { method: 'PUT', body: JSON.stringify(data) }),
}
