export type ApiUser = { id: number; name: string; email: string; email_verified_at: string | null }
export type OrganizationMembership = {
  membership_id: number
  role: 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'independent_educator'
  organization: { id: number; name: string; slug: string; type: string; status: string; timezone: string }
}
export type OrganizationList = { data: OrganizationMembership[]; preferred_organization_id: number | null }
export type Course = { id: number; title: string; level: string | null; description: string | null; status: string; subject: { id: number; name: string } | null; teacher: { id: number; name: string } | null }
export type Lesson = { id: number; title: string; sort_order: number; content: string | null; external_url: string | null; status: 'draft' | 'published' }
export type AssignmentSubmission = { id: number; assignment_id: number; answer: string; submitted_at: string | null; awarded_points: number | null; feedback: string | null; graded_at: string | null }
export type LearnerSubmission = AssignmentSubmission & { student: { id: number; name: string; admission_number: string | null } }
export type CourseAssignment = { id: number; course_id: number; title: string; instructions: string | null; due_at: string | null; max_points: number; status: 'draft' | 'published'; submission: AssignmentSubmission | null; submissions?: LearnerSubmission[] }
export type QuizQuestion = { id: number; prompt: string; options: string[]; points: number; sort_order: number; question_type: 'single_choice' | 'multiple_select' | 'short_answer'; difficulty: 'easy' | 'medium' | 'hard' }
export type QuizAttempt = { id: number; attempt_number: number; answers: Record<number, number | number[] | string> | null; manual_grades?: Record<number, { awarded_points: number; feedback: string | null; graded_at: string | null }> | null; review_status: 'not_required' | 'pending' | 'partial' | 'graded'; result_outcome: 'not_set' | 'pending_review' | 'passed' | 'failed'; score: number | null; max_score: number | null; started_at: string; submitted_at: string | null }
export type CourseQuiz = { id: number; course_id: number; title: string; instructions: string | null; time_limit_minutes: number | null; max_attempts: number; opens_at: string | null; closes_at: string | null; negative_marking_rate: string | number; pass_percent: number | null; status: 'draft' | 'published'; questions: QuizQuestion[]; attempts_used: number | null; latest_result: QuizAttempt | null }
export type QuizRun = { attempt: QuizAttempt; quiz: Pick<CourseQuiz, 'id' | 'title' | 'instructions' | 'time_limit_minutes'>; questions: QuizQuestion[] }
export type TeachingAssignment = { id: number; academic_session_id: number; academic_section_id: number; subject_id: number; teacher_id: number; academic_session: { id: number; name: string }; academic_section: { id: number; name: string; academic_class: { id: number; name: string } }; subject: { id: number; name: string } }
export type AttendanceStudent = { id: number; name: string; admission_number: string | null; status: 'present' | 'absent' | 'late' | 'excused' | null; note: string | null }
export type FamilyStudent = { id: number; name: string; admission_number: string | null; academic_class: { id: number; name: string } | null; academic_section: { id: number; name: string } | null; academic_session: { id: number; name: string } | null; relationship?: string; is_primary?: boolean }
export type StudentAttendance = { id: number; date: string; status: 'present' | 'absent' | 'late' | 'excused'; note: string | null; section: { id: number; name: string; class: string }; session: { id: number; name: string } }
export type AssessmentResult = { id: number; title: string; course: string; score: string | null; max_score: string | null; attempt_number?: number; review_status?: 'not_required' | 'pending' | 'partial' | 'graded'; result_outcome?: 'not_set' | 'pending_review' | 'passed' | 'failed'; feedback?: string | null; completed_at: string | null }
export type PublishedExamResult = { id: number; exam: string; session: string; class_section: string; subject: string; exam_on: string; score: string | null; max_marks: string; outcome: string; remarks: string | null }
export type AssessmentResults = { exams: PublishedExamResult[]; quizzes: AssessmentResult[]; assignments: AssessmentResult[] }
export type StudentProgress = { data: { course_id: number; title: string; lessons: number; completed_lessons: number; completion_percent: number }[]; summary: { courses: number; lessons: number; completed_lessons: number; completion_percent: number } }
export type EnrollmentReport = { summary: { active_students: number; unassigned_students: number; students_with_published_courses: number; active_course_enrollments: number }; sessions: { session_id: number; session: string; is_current: boolean; is_active: boolean; starts_on: string | null; ends_on: string | null; active_students: number; active_course_enrollments: number; sections: { section_id: number; class: string; section: string; active: boolean; capacity: number | null; active_students: number; active_course_enrollments: number }[] }[] }
export type TeacherWorkloadReport = { as_of: string; sessions: string[]; summary: { active_teachers: number; teachers_with_assignments: number; section_assignments: number; teacher_learner_assignments: number; weekly_timetable_slots: number; weekly_scheduled_hours: number }; teachers: { teacher_id: number; teacher: string; section_assignments: number; active_learners: number; weekly_timetable_slots: number; weekly_scheduled_hours: number }[] }
export type AssessmentReport = { summary: { published_quizzes: number; submitted_attempts: number; in_progress_attempts: number; awaiting_review: number; learner_quiz_participations: number; average_score_percent: number | null; quizzes_with_submissions: number }; quizzes: { quiz_id: number; course: string; quiz: string; submitted_attempts: number; in_progress_attempts: number; awaiting_review: number; learners: number; average_score_percent: number | null }[]; assignment_summary: { published_assignments: number; submissions: number; graded_submissions: number; awaiting_review: number; learner_submissions: number; average_score_percent: number | null }; assignments: { assignment_id: number; course: string; assignment: string; learners: number; submissions: number; graded_submissions: number; awaiting_review: number; average_score_percent: number | null }[] }
export type ExamSectionSummary = { exam_id: number; exam: string; session: string; class_section: string; paper_count: number; subject_count: number; student_count: number; result_count: number; graded_count: number; absent_count: number; excused_count: number; average_percent: number | null }
export type ExamSeriesComparison = { exam_id: number; exam: string; series: string; exam_on: string; session: string; class_section: string; subject: string; result_count: number; graded_count: number; absent_count: number; excused_count: number; average_percent: number | null; previous_exam: string | null; change_percentage_points: number | null }
export type SchoolExamPaper = { id: number; section_id: number; section: string; subject_id: number; subject: string; exam_on: string; max_marks: string; paper_sections: { id: number; title: string; max_marks: string; position: number }[]; results_recorded: number }
export type SchoolExam = { id: number; title: string; status: 'draft' | 'published'; starts_on: string; ends_on: string; session_id: number; session: string; papers: SchoolExamPaper[] }
export type SchoolExamMark = { student_id: number; name: string; admission_number: string; outcome: 'graded' | 'absent' | 'excused' | null; score: string | null; remarks: string | null; section_scores: Record<string, string> }
export type FamilyHomework = { id: number; title: string; course: string; due_at: string | null; max_points: string; submitted_at: string | null; score: string | null; feedback: string | null; status: 'pending' | 'overdue' | 'submitted' | 'graded' }
export type FamilyFee = { id: number; title: string; amount: string; paid_amount: string; balance: string; due_on: string | null; status: 'unpaid' | 'partial' | 'paid'; student: { id: number; name: string; admission_number: string | null } | null }
export type StudyMaterial = { id: number; title: string; filename: string; mime_type: string; course: { id: number; title: string } }
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
  absoluteUrl: (path: string) => `${baseUrl}${path}`,
  login: (email: string, password: string) => request<{ data: ApiUser; token: string; expires_at: string }>('/api/v1/auth/login', undefined, {
    method: 'POST', body: JSON.stringify({ email, password, device_name: 'edTechLive mobile' }),
  }),
  get: <T>(path: string, token: string) => request<T>(path, token),
  post: <T>(path: string, token: string, data?: unknown) => request<T>(path, token, { method: 'POST', ...(data === undefined ? {} : { body: JSON.stringify(data) }) }),
  patch: <T>(path: string, token: string, data: unknown) => request<T>(path, token, { method: 'PATCH', body: JSON.stringify(data) }),
  put: <T>(path: string, token: string, data: unknown) => request<T>(path, token, { method: 'PUT', body: JSON.stringify(data) }),
  delete: <T>(path: string, token: string, data?: unknown) => request<T>(path, token, { method: 'DELETE', ...(data === undefined ? {} : { body: JSON.stringify(data) }) }),
}
