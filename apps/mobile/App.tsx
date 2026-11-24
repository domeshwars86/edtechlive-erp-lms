import { useCallback, useEffect, useRef, useState } from 'react'
import { ActivityIndicator, Alert, Linking, Platform, Pressable, ScrollView, StatusBar, StyleSheet, Text, TextInput, View } from 'react-native'
import * as SecureStore from 'expo-secure-store'
import { ApiError, mobileApi, type ApiUser, type AssessmentReport, type AssessmentResults, type AttendanceStudent, type Course, type CourseAssignment, type CourseQuiz, type DashboardSummary, type EnrollmentReport, type ExamSectionSummary, type ExamSeriesComparison, type FamilyFee, type FamilyHomework, type FamilyStudent, type Lesson, type OrganizationList, type OrganizationMembership, type QuizRun, type SchoolExam, type SchoolExamMark, type SchoolExamPaper, type StudentAttendance, type StudentProgress, type StudyMaterial, type TeacherWorkloadReport, type TeachingAssignment } from './src/api'
import { AssessmentReportPanel, CourseProgressReportPanel, EnrollmentReportPanel, ExamSectionReportPanel, TeacherWorkloadReportPanel } from './src/ReportPanels'
import { NotificationPreferencesPanel } from './src/NotificationPreferencesPanel'
import { MembershipAccessPanel } from './src/MembershipAccessPanel'
import { mobilePushTokenKey } from './src/pushRegistration'

const TOKEN_KEY = 'edtechlive.mobile.token'
const ORGANIZATION_KEY = 'edtechlive.mobile.organization'
const roleNames: Record<OrganizationMembership['role'], string> = {
  admin: 'Administrator', management: 'Management', teacher: 'Teacher', student: 'Student', parent: 'Parent / guardian', independent_educator: 'Independent educator',
}
type MobileNotice = { id: string; type: string; title: string; body: string; resource_type: string | null; resource_id: number | null; course_id: number | null; read_at: string | null; created_at: string | null }
type MobileMeeting = { id: number; kind: 'class' | 'management'; title: string; description: string | null; starts_at: string; ends_at: string; meeting_url: string; status: 'scheduled' | 'cancelled'; section: { id: number; name: string; class: string | null } | null; course: { id: number; title: string } | null; creator: { id: number; name: string } | null }
type MobileRecording = { id: number; title: string; description: string | null; external_url: string | null; download_url: string | null; status: 'draft' | 'published' | 'withdrawn' }
type MobileMeetingSection = { id: number; name: string; academic_session_id: number; academic_class: { name: string } }
type AttendanceStatus = 'present' | 'absent' | 'late' | 'excused'
type MobileAttendanceStudent = AttendanceStudent & { originalStatus: AttendanceStudent['status']; correctionReason: string }
type MobileRosterStudent = { id: number; name: string; admission_number: string | null; academic_class: { id: number; name: string } | null; academic_section: { id: number; name: string } | null }
type MobileRosterTeacher = { id: number; name: string; employee_number: string | null; specialization: string | null }
type MobileRosterKind = 'students' | 'teachers'
type MobileInvitation = { id: number; email: string; role: OrganizationMembership['role']; status: 'pending' | 'accepted' | 'expired' | 'revoked'; expires_at: string; accepted_at: string | null; revoked_at: string | null }
type MobileAttendanceReport = { from: string; to: string; totals: { records: number; present: number; late: number; absent: number; excused: number; attendance_rate: number }; sections: { section_id: number; class: string; section: string; records: number; attendance_rate: number; absent: number }[] }
type MobileCourseProgressReport = { data: { course_id: number; title: string; lessons: number; learners: number; average_percent: number; completed_learners: number }[]; summary: { courses: number; learners: number; average_percent: number } }
type MobileFinanceReport = { as_of: string; currency: 'INR'; collections_period: { from: string; to: string; receipt_count: number; received_amount: string; expense_count: number; expense_amount: string; net_cash_flow: string }; summary: { fee_records: number; unpaid_records: number; partial_records: number; paid_records: number; issued_amount: string; received_amount: string; expense_amount: string; net_cash_flow: string; outstanding_amount: string; overdue_records: number; overdue_balance: string } }
const attendanceStatuses: AttendanceStatus[] = ['present', 'absent', 'late', 'excused']
const examOutcomes: { value: SchoolExamMark['outcome']; label: string }[] = [
  { value: 'graded', label: 'Graded' }, { value: 'absent', label: 'Absent' }, { value: 'excused', label: 'Excused' },
]
function localDate() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}` }
function localDateTimeInput(hoursAhead: number) { const value = new Date(); value.setMinutes(0, 0, 0); value.setHours(value.getHours() + hoursAhead); return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}T${String(value.getHours()).padStart(2, '0')}:${String(value.getMinutes()).padStart(2, '0')}` }
function organizationDate(timezone: string) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(part => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}
function shiftDate(value: string, days: number) {
  const date = new Date(`${value}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}
function isIsoDate(value: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
}
function appendRosterItems<T extends { id: number }>(current: T[], incoming: T[]) {
  const existing = new Set(current.map(item => item.id))
  return [...current, ...incoming.filter(item => !existing.has(item.id))]
}

export default function App() {
  const [token, setToken] = useState('')
  const [user, setUser] = useState<ApiUser | null>(null)
  const [memberships, setMemberships] = useState<OrganizationMembership[]>([])
  const [active, setActive] = useState<OrganizationMembership | null>(null)
  const workspaceContextRef = useRef<string | null>(null)
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [notices, setNotices] = useState<MobileNotice[]>([])
  const [noticePage, setNoticePage] = useState(1)
  const [noticeLastPage, setNoticeLastPage] = useState(1)
  const [noticeLoading, setNoticeLoading] = useState(false)
  const [meetings, setMeetings] = useState<MobileMeeting[]>([])
  const [meetingSections, setMeetingSections] = useState<MobileMeetingSection[]>([])
  const [meetingFormOpen, setMeetingFormOpen] = useState(false)
  const [meetingKind, setMeetingKind] = useState<'class' | 'management'>('class')
  const [meetingSectionId, setMeetingSectionId] = useState('')
  const [meetingTitle, setMeetingTitle] = useState('')
  const [meetingDescription, setMeetingDescription] = useState('')
  const [meetingStartsAt, setMeetingStartsAt] = useState(() => localDateTimeInput(24))
  const [meetingEndsAt, setMeetingEndsAt] = useState(() => localDateTimeInput(25))
  const [meetingUrl, setMeetingUrl] = useState('')
  const [meetingRecordings, setMeetingRecordings] = useState<Record<number, MobileRecording[]>>({})
  const [studentProfile, setStudentProfile] = useState<FamilyStudent | null>(null)
  const [familyChildren, setFamilyChildren] = useState<Array<FamilyStudent & { attendance: StudentAttendance[]; results: AssessmentResults; progress: StudentProgress; homework: FamilyHomework[] }>>([])
  const [ownAttendance, setOwnAttendance] = useState<StudentAttendance[]>([])
  const [ownResults, setOwnResults] = useState<AssessmentResults>({ exams: [], quizzes: [], assignments: [] })
  const [ownProgress, setOwnProgress] = useState<StudentProgress | null>(null)
  const [ownHomework, setOwnHomework] = useState<FamilyHomework[]>([])
  const [familyFees, setFamilyFees] = useState<FamilyFee[]>([])
  const [rosterStudents, setRosterStudents] = useState<MobileRosterStudent[]>([])
  const [rosterTeachers, setRosterTeachers] = useState<MobileRosterTeacher[]>([])
  const [rosterStudentTotal, setRosterStudentTotal] = useState(0)
  const [rosterTeacherTotal, setRosterTeacherTotal] = useState(0)
  const [rosterStudentPage, setRosterStudentPage] = useState(1)
  const [rosterStudentLastPage, setRosterStudentLastPage] = useState(1)
  const [rosterTeacherPage, setRosterTeacherPage] = useState(1)
  const [rosterTeacherLastPage, setRosterTeacherLastPage] = useState(1)
  const [rosterStudentSearch, setRosterStudentSearch] = useState('')
  const [rosterTeacherSearch, setRosterTeacherSearch] = useState('')
  const [rosterStudentLoading, setRosterStudentLoading] = useState(false)
  const [rosterTeacherLoading, setRosterTeacherLoading] = useState(false)
  const [rosterStudentError, setRosterStudentError] = useState('')
  const [rosterTeacherError, setRosterTeacherError] = useState('')
  const rosterRequestRef = useRef<Record<MobileRosterKind, number>>({ students: 0, teachers: 0 })
  const [invitations, setInvitations] = useState<MobileInvitation[]>([])
  const [invitationsLoading, setInvitationsLoading] = useState(false)
  const [invitationFormOpen, setInvitationFormOpen] = useState(false)
  const [invitationEmail, setInvitationEmail] = useState('')
  const [invitationRole, setInvitationRole] = useState<'management' | 'teacher' | 'student' | 'parent'>('parent')
  const [invitationStudentIds, setInvitationStudentIds] = useState<number[]>([])
  const [invitationSaving, setInvitationSaving] = useState(false)
  const [attendanceReport, setAttendanceReport] = useState<MobileAttendanceReport | null>(null)
  const [attendanceFrom, setAttendanceFrom] = useState(localDate())
  const [attendanceTo, setAttendanceTo] = useState(localDate())
  const [attendanceReportLoading, setAttendanceReportLoading] = useState(false)
  const [attendanceReportError, setAttendanceReportError] = useState('')
  const attendanceReportRequestRef = useRef(0)
  const [courseProgressReport, setCourseProgressReport] = useState<MobileCourseProgressReport | null>(null)
  const [assessmentReport, setAssessmentReport] = useState<AssessmentReport | null>(null)
  const [enrollmentReport, setEnrollmentReport] = useState<EnrollmentReport | null>(null)
  const [teacherWorkloadReport, setTeacherWorkloadReport] = useState<TeacherWorkloadReport | null>(null)
  const [examSectionSummaries, setExamSectionSummaries] = useState<ExamSectionSummary[]>([])
  const [examSeriesComparisons, setExamSeriesComparisons] = useState<ExamSeriesComparison[]>([])
  const [financeReport, setFinanceReport] = useState<MobileFinanceReport | null>(null)
  const [teacherExams, setTeacherExams] = useState<SchoolExam[]>([])
  const [selectedExamPaper, setSelectedExamPaper] = useState<{ exam: SchoolExam; paper: SchoolExamPaper } | null>(null)
  const [examMarks, setExamMarks] = useState<SchoolExamMark[]>([])
  const [studyMaterials, setStudyMaterials] = useState<StudyMaterial[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [selectedCourse, setSelectedCourse] = useState<Course | null>(null)
  const [lessons, setLessons] = useState<Lesson[]>([])
  const [assignments, setAssignments] = useState<CourseAssignment[]>([])
  const [quizzes, setQuizzes] = useState<CourseQuiz[]>([])
  const [quizRun, setQuizRun] = useState<QuizRun | null>(null)
  const [quizAnswers, setQuizAnswers] = useState<Record<number, number[] | string>>({})
  const [assignmentDrafts, setAssignmentDrafts] = useState<Record<number, string>>({})
  const [gradingDrafts, setGradingDrafts] = useState<Record<number, { score: string; feedback: string }>>({})
  const [teachingAssignments, setTeachingAssignments] = useState<TeachingAssignment[]>([])
  const [attendanceSection, setAttendanceSection] = useState<TeachingAssignment | null>(null)
  const [attendanceDate, setAttendanceDate] = useState(localDate())
  const [attendanceStudents, setAttendanceStudents] = useState<MobileAttendanceStudent[]>([])
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [verificationNeeded, setVerificationNeeded] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [profileName, setProfileName] = useState('')
  const [profileEmail, setProfileEmail] = useState('')
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [verificationUrl, setVerificationUrl] = useState('')

  const fetchAttendanceReport = useCallback(async (from: string, to: string) => {
    if (!active || !token) return
    if (!isIsoDate(from) || !isIsoDate(to)) {
      setAttendanceReportError('Enter dates in YYYY-MM-DD format.')
      return
    }
    if (from > to) {
      setAttendanceReportError('The end date must be on or after the start date.')
      return
    }
    const requestId = ++attendanceReportRequestRef.current
    const workspaceContext = `${user?.id ?? ''}:${active.organization.id}`
    setAttendanceReportLoading(true)
    setAttendanceReportError('')
    try {
      const base = `/api/v1/organizations/${active.organization.id}`
      const result = await mobileApi.get<{ data: MobileAttendanceReport }>(`${base}/reports/attendance?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, token)
      if (requestId === attendanceReportRequestRef.current && workspaceContextRef.current === workspaceContext) setAttendanceReport(result.data)
    } catch (reason) {
      if (requestId === attendanceReportRequestRef.current && workspaceContextRef.current === workspaceContext) {
        setAttendanceReportError(reason instanceof Error ? reason.message : 'Could not load the attendance report.')
      }
    } finally {
      if (requestId === attendanceReportRequestRef.current && workspaceContextRef.current === workspaceContext) setAttendanceReportLoading(false)
    }
  }, [active, token, user?.id])

  const loadRoster = useCallback(async (kind: MobileRosterKind, page = 1, search = '', append = false) => {
    if (!active || !token) return
    const requestId = ++rosterRequestRef.current[kind]
    const workspaceContext = `${user?.id ?? ''}:${active.organization.id}`
    const setLoading = kind === 'students' ? setRosterStudentLoading : setRosterTeacherLoading
    const setRosterError = kind === 'students' ? setRosterStudentError : setRosterTeacherError
    setLoading(true)
    setRosterError('')
    try {
      const base = `/api/v1/organizations/${active.organization.id}`
      const endpoint = `${base}/${kind}?status=active&per_page=20&page=${page}${search.trim() ? `&search=${encodeURIComponent(search.trim())}` : ''}`
      const result = await mobileApi.get<{ data: Array<MobileRosterStudent | MobileRosterTeacher>; meta: { current_page: number; last_page: number; total: number } }>(endpoint, token)
      if (requestId !== rosterRequestRef.current[kind] || workspaceContextRef.current !== workspaceContext) return
      if (kind === 'students') {
        setRosterStudents(current => append ? appendRosterItems(current, result.data as MobileRosterStudent[]) : result.data as MobileRosterStudent[])
        setRosterStudentTotal(result.meta.total); setRosterStudentPage(result.meta.current_page); setRosterStudentLastPage(result.meta.last_page)
      } else {
        setRosterTeachers(current => append ? appendRosterItems(current, result.data as MobileRosterTeacher[]) : result.data as MobileRosterTeacher[])
        setRosterTeacherTotal(result.meta.total); setRosterTeacherPage(result.meta.current_page); setRosterTeacherLastPage(result.meta.last_page)
      }
    } catch (reason) {
      if (requestId === rosterRequestRef.current[kind] && workspaceContextRef.current === workspaceContext) {
        setRosterError(reason instanceof Error ? reason.message : `Could not load the ${kind} roster.`)
      }
    } finally {
      if (requestId === rosterRequestRef.current[kind] && workspaceContextRef.current === workspaceContext) setLoading(false)
    }
  }, [active, token, user?.id])

  const loadInvitations = useCallback(async () => {
    if (!active || !token) return
    setInvitationsLoading(true)
    try {
      const result = await mobileApi.get<{ data: MobileInvitation[] }>(`/api/v1/organizations/${active.organization.id}/invitations?per_page=50`, token)
      if (workspaceContextRef.current === `${user?.id ?? ''}:${active.organization.id}`) setInvitations(result.data)
    } catch (reason) {
      if (workspaceContextRef.current === `${user?.id ?? ''}:${active.organization.id}`) setError(reason instanceof Error ? reason.message : 'Could not load invitations.')
    } finally {
      if (workspaceContextRef.current === `${user?.id ?? ''}:${active.organization.id}`) setInvitationsLoading(false)
    }
  }, [active, token, user?.id])

  async function sendInvitation() {
    if (!active || !token) return
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(invitationEmail.trim())) { setError('Enter a valid invitation email address.'); return }
    if (invitationRole === 'parent' && invitationStudentIds.length === 0) { setError('Select at least one active student for this parent invitation.'); return }
    setInvitationSaving(true); setError(''); setSuccess('')
    try {
      const payload = { email: invitationEmail.trim(), role: invitationRole, ...(invitationRole === 'parent' ? { student_ids: invitationStudentIds } : {}) }
      await mobileApi.post<{ data: MobileInvitation }>(`/api/v1/organizations/${active.organization.id}/invitations`, token, payload)
      setInvitationEmail(''); setInvitationStudentIds([]); setInvitationFormOpen(false)
      setSuccess('Invitation created. The recipient needs the invitation email to join; check the workspace mail settings if it does not arrive.')
      await loadInvitations()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not send this invitation.') }
    finally { setInvitationSaving(false) }
  }

  async function revokeInvitation(invitation: MobileInvitation) {
    if (!active || !token) return
    Alert.alert('Revoke invitation?', `The pending invitation for ${invitation.email} will no longer work.`, [
      { text: 'Keep invitation', style: 'cancel' },
      { text: 'Revoke', style: 'destructive', onPress: () => { void (async () => {
        setInvitationSaving(true); setError(''); setSuccess('')
        try {
          await mobileApi.delete<{ message: string }>(`/api/v1/organizations/${active.organization.id}/invitations/${invitation.id}`, token)
          setSuccess(`Invitation for ${invitation.email} was revoked.`)
          await loadInvitations()
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not revoke this invitation.') }
        finally { setInvitationSaving(false) }
      })() } },
    ])
  }

  function applyAttendanceRange(from = attendanceFrom, to = attendanceTo) {
    setAttendanceFrom(from)
    setAttendanceTo(to)
    void fetchAttendanceReport(from, to)
  }

  useEffect(() => {
    let cancelled = false
    async function restore() {
      try {
        const saved = await SecureStore.getItemAsync(TOKEN_KEY)
        if (!saved) return
        const me = await mobileApi.get<{ data: ApiUser }>('/api/v1/me', saved)
        const organizationList = await mobileApi.get<OrganizationList>('/api/v1/organizations?per_page=100', saved)
        if (cancelled) return
        setToken(saved); setUser(me.data); setMemberships(organizationList.data)
        const remembered = await SecureStore.getItemAsync(ORGANIZATION_KEY)
        const selection = organizationList.data.find(item => item.organization.id === organizationList.preferred_organization_id)
          ?? organizationList.data.find(item => String(item.organization.id) === remembered)
          ?? (organizationList.data.length === 1 ? organizationList.data[0] : null)
        if (selection) {
          workspaceContextRef.current = `${me.data.id}:${selection.organization.id}`
          setActive(selection)
        }
      } catch {
        await SecureStore.deleteItemAsync(TOKEN_KEY)
        await SecureStore.deleteItemAsync(ORGANIZATION_KEY)
      } finally {
        if (!cancelled) setBusy(false)
      }
    }
    void restore()
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!token || !active) { setSummary(null); setCourses([]); return }
    let cancelled = false
    setBusy(true); setError(''); setSelectedCourse(null)
    attendanceReportRequestRef.current += 1
    rosterRequestRef.current.students += 1; rosterRequestRef.current.teachers += 1
    setAttendanceReportLoading(false); setAttendanceReportError('')
    setRosterStudentLoading(false); setRosterTeacherLoading(false); setRosterStudentError(''); setRosterTeacherError('')
    setInvitations([]); setInvitationsLoading(false); setInvitationFormOpen(false); setInvitationEmail(''); setInvitationStudentIds([])
    setRosterStudentSearch(''); setRosterTeacherSearch(''); setRosterStudentPage(1); setRosterStudentLastPage(1); setRosterTeacherPage(1); setRosterTeacherLastPage(1)
    setSummary(null); setNotices([]); setNoticePage(1); setNoticeLastPage(1); setNoticeLoading(false); setMeetings([]); setMeetingSections([]); setMeetingRecordings({}); setStudentProfile(null); setFamilyChildren([]); setOwnAttendance([]); setOwnResults({ exams: [], quizzes: [], assignments: [] }); setOwnProgress(null); setOwnHomework([]); setFamilyFees([]); setStudyMaterials([]); setRosterStudents([]); setRosterTeachers([]); setRosterStudentTotal(0); setRosterTeacherTotal(0); setAttendanceReport(null); setCourseProgressReport(null); setAssessmentReport(null); setEnrollmentReport(null); setTeacherWorkloadReport(null); setExamSectionSummaries([]); setExamSeriesComparisons([]); setFinanceReport(null); setTeacherExams([]); setSelectedExamPaper(null); setExamMarks([]); setCourses([]); setLessons([]); setAssignments([]); setQuizzes([]); setQuizRun(null); setTeachingAssignments([]); setAttendanceSection(null); setAttendanceStudents([]); setMeetingFormOpen(false)
    const base = `/api/v1/organizations/${active.organization.id}`
    if (['admin', 'management', 'independent_educator'].includes(active.role)) {
      const today = organizationDate(active.organization.timezone)
      setAttendanceFrom(today); setAttendanceTo(today)
      void fetchAttendanceReport(today, today)
      void loadRoster('students')
      void loadRoster('teachers')
      void loadInvitations()
    }
    const tasks: Promise<void>[] = [mobileApi.get<{ data: DashboardSummary }>(`${base}/dashboard/summary`, token)
      .then(result => { if (!cancelled) setSummary(result.data) }),
    mobileApi.get<{ data: MobileNotice[]; meta: { current_page: number; last_page: number } }>(`${base}/notifications?per_page=10&page=1`, token)
      .then(result => { if (!cancelled) { setNotices(result.data); setNoticePage(result.meta.current_page); setNoticeLastPage(result.meta.last_page) } }),
    mobileApi.get<{ data: MobileMeeting[] }>(`${base}/meetings?per_page=100`, token)
      .then(async result => {
        if (cancelled) return
        setMeetings(result.data)
        if (active.role === 'student' || active.role === 'parent') {
          const cutoff = Date.now() - 30 * 24 * 60 * 60 * 1000
          const recent = result.data.filter(item => item.status === 'scheduled' && new Date(item.ends_at).getTime() >= cutoff)
            .sort((left, right) => new Date(right.starts_at).getTime() - new Date(left.starts_at).getTime()).slice(0, 10)
          const entries = await Promise.all(recent.map(async item => {
            try {
              const recordings = await mobileApi.get<{ data: MobileRecording[] }>(`${base}/meetings/${item.id}/recordings`, token)
              return [item.id, recordings.data] as const
            } catch { return [item.id, []] as const }
          }))
          if (!cancelled) setMeetingRecordings(Object.fromEntries(entries))
        }
      })]
    if (active.role !== 'parent') {
      tasks.push(mobileApi.get<{ data: Course[] }>(`${base}/courses?per_page=100`, token)
        .then(result => { if (!cancelled) setCourses(result.data) }))
    }
    if (active.role === 'admin' || active.role === 'management' || active.role === 'independent_educator') {
      tasks.push(mobileApi.get<{ data: { id: number; name: string }[] }>(`${base}/classes?per_page=100`, token)
        .then(async result => {
          const sections = await Promise.all(result.data.map(async item => {
            const response = await mobileApi.get<{ data: { id: number; name: string; academic_session_id: number | null }[] }>(`${base}/classes/${item.id}/sections?per_page=100`, token)
            return response.data.filter(section => section.academic_session_id !== null).map(section => ({ ...section, academic_session_id: section.academic_session_id as number, academic_class: { name: item.name } }))
          }))
          if (!cancelled) setMeetingSections(sections.flat())
        }))
    }
    if (active.role === 'student' || active.role === 'parent') {
      tasks.push(mobileApi.get<{ data: StudyMaterial[] }>(`${base}/documents?per_page=100`, token)
        .then(result => { if (!cancelled) setStudyMaterials(result.data) }))
    }
    if (active.role === 'teacher') {
      tasks.push(mobileApi.get<{ data: TeachingAssignment[] }>(`${base}/teaching-assignments?per_page=100`, token)
        .then(result => { if (!cancelled) setTeachingAssignments(result.data) }))
      tasks.push(mobileApi.get<{ data: SchoolExam[] }>(`${base}/exams`, token)
        .then(result => { if (!cancelled) setTeacherExams(result.data) }))
      tasks.push(Promise.all([
        mobileApi.get<{ data: ExamSeriesComparison[] }>(`${base}/reports/exams/comparisons`, token),
        mobileApi.get<{ data: ExamSectionSummary[] }>(`${base}/reports/exams/sections`, token),
      ]).then(([comparisonResult, sectionResult]) => { if (!cancelled) { setExamSeriesComparisons(comparisonResult.data); setExamSectionSummaries(sectionResult.data) } })
        .catch(() => { if (!cancelled) setExamSeriesComparisons([]) }))
    }
    if (['admin', 'management', 'independent_educator', 'teacher'].includes(active.role)) {
      tasks.push(mobileApi.get<MobileCourseProgressReport>(`${base}/reports/course-progress`, token)
        .then(result => { if (!cancelled) setCourseProgressReport(result) }))
    }
    if (['admin', 'management', 'independent_educator', 'teacher'].includes(active.role)) {
      tasks.push(mobileApi.get<{ data: AssessmentReport }>(`${base}/reports/assessments`, token)
        .then(result => { if (!cancelled) setAssessmentReport(result.data) }))
    }
    if (active.role === 'independent_educator') {
      tasks.push(Promise.all([
        mobileApi.get<{ data: ExamSectionSummary[] }>(`${base}/reports/exams/sections`, token),
        mobileApi.get<{ data: ExamSeriesComparison[] }>(`${base}/reports/exams/comparisons`, token),
        mobileApi.get<{ data: MobileFinanceReport }>(`${base}/reports/finance`, token),
      ]).then(([examResult, comparisonResult, financeResult]) => {
        if (!cancelled) {
          setExamSectionSummaries(examResult.data)
          setExamSeriesComparisons(comparisonResult.data)
          setFinanceReport(financeResult.data)
        }
      }))
    }
    if (['admin', 'management', 'independent_educator'].includes(active.role)) {
      tasks.push(Promise.all([
        mobileApi.get<{ data: EnrollmentReport }>(`${base}/reports/enrollment`, token),
        mobileApi.get<{ data: TeacherWorkloadReport }>(`${base}/reports/workload`, token),
      ]).then(([enrollmentResult, workloadResult]) => {
        if (!cancelled) { setEnrollmentReport(enrollmentResult.data); setTeacherWorkloadReport(workloadResult.data) }
      }))
    }
    if (active.role === 'admin' || active.role === 'management') {
      tasks.push(Promise.all([
        mobileApi.get<{ data: ExamSectionSummary[] }>(`${base}/reports/exams/sections`, token),
        mobileApi.get<{ data: ExamSeriesComparison[] }>(`${base}/reports/exams/comparisons`, token).catch(() => ({ data: [] as ExamSeriesComparison[] })),
        mobileApi.get<{ data: MobileFinanceReport }>(`${base}/reports/finance`, token),
      ]).then(([examResult, comparisonResult, financeResult]) => {
        if (!cancelled) {
          setExamSectionSummaries(examResult.data)
          setExamSeriesComparisons(comparisonResult.data)
          setFinanceReport(financeResult.data)
        }
      }))
    }
    if (active.role === 'student') {
      tasks.push(Promise.all([
        mobileApi.get<{ data: FamilyStudent }>(`${base}/my/student`, token),
        mobileApi.get<{ data: StudentAttendance[] }>(`${base}/my/student/attendance?per_page=10`, token),
        mobileApi.get<{ data: AssessmentResults }>(`${base}/my/student/results`, token),
        mobileApi.get<StudentProgress>(`${base}/my/student/progress`, token),
        mobileApi.get<{ data: FamilyHomework[] }>(`${base}/my/student/homework?per_page=50`, token),
        mobileApi.get<{ data: FamilyFee[] }>(`${base}/fees?per_page=50`, token),
      ]).then(([profileResult, attendanceResult, resultHistory, courseProgress, homeworkResult, feeResult]) => {
        if (!cancelled) { setStudentProfile(profileResult.data); setOwnAttendance(attendanceResult.data); setOwnResults(resultHistory.data); setOwnProgress(courseProgress); setOwnHomework(homeworkResult.data); setFamilyFees(feeResult.data) }
      }))
    }
    if (active.role === 'parent') {
      tasks.push((async () => {
        const childResult = await mobileApi.get<{ data: FamilyStudent[] }>(`${base}/my/children?per_page=100`, token)
        const [feeResult, ...childData] = await Promise.all([
          mobileApi.get<{ data: FamilyFee[] }>(`${base}/fees?per_page=100`, token),
          ...childResult.data.flatMap(child => [
            mobileApi.get<{ data: StudentAttendance[] }>(`${base}/my/children/${child.id}/attendance?per_page=10`, token),
            mobileApi.get<{ data: AssessmentResults }>(`${base}/my/children/${child.id}/results`, token),
            mobileApi.get<StudentProgress>(`${base}/my/children/${child.id}/progress`, token),
            mobileApi.get<{ data: FamilyHomework[] }>(`${base}/my/children/${child.id}/homework?per_page=50`, token),
          ]),
        ])
        if (!cancelled) {
          setFamilyChildren(childResult.data.map((child, index) => ({ ...child, attendance: (childData[index * 4] as { data: StudentAttendance[] }).data, results: (childData[index * 4 + 1] as { data: AssessmentResults }).data, progress: childData[index * 4 + 2] as StudentProgress, homework: (childData[index * 4 + 3] as { data: FamilyHomework[] }).data })))
          setFamilyFees(feeResult.data)
        }
      })())
    }
    Promise.all(tasks).catch(reason => {
      if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load your learning workspace.')
    }).finally(() => { if (!cancelled) setBusy(false) })
    return () => { cancelled = true }
  }, [active, token, fetchAttendanceReport, loadRoster, loadInvitations])

  async function signIn() {
    setBusy(true); setError(''); setSuccess(''); setVerificationNeeded(false)
    try {
      const result = await mobileApi.login(email.trim(), password)
      const organizationList = await mobileApi.get<OrganizationList>('/api/v1/organizations?per_page=100', result.token)
      await SecureStore.setItemAsync(TOKEN_KEY, result.token)
      setToken(result.token); setUser(result.data); setMemberships(organizationList.data)
      const preferred = organizationList.data.find(item => item.organization.id === organizationList.preferred_organization_id)
      if (preferred) await chooseOrganization(preferred, result.token, result.data.id)
      else if (organizationList.data.length === 1) await chooseOrganization(organizationList.data[0], result.token, result.data.id)
      setPassword('')
    } catch (reason) {
      if (reason instanceof ApiError && reason.code === 'email_verification_required') {
        setVerificationNeeded(true)
        setError('Verify your email before signing in. Request a new link below.')
      } else setError(reason instanceof Error ? reason.message : 'Could not sign in.')
    } finally { setBusy(false) }
  }

  async function resendVerification() {
    if (!email.trim()) { setError('Enter the email address for your account first.'); return }
    setBusy(true); setError(''); setSuccess('')
    try {
      await mobileApi.post('/api/v1/auth/email/verification-notification', '', { email: email.trim() })
      setSuccess('If this address has an unverified account, a new verification link has been sent.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not request a verification link.') }
    finally { setBusy(false) }
  }

  async function chooseOrganization(membership: OrganizationMembership, currentToken = token, accountId = user?.id) {
    if (currentToken) await mobileApi.patch<{ data: OrganizationMembership }>('/api/v1/me/organization', currentToken, { organization_id: membership.organization.id })
    await SecureStore.setItemAsync(ORGANIZATION_KEY, String(membership.organization.id))
    workspaceContextRef.current = accountId === undefined ? null : `${accountId}:${membership.organization.id}`
    setActive(membership); setError(''); setMeetingKind('class'); setMeetingSectionId(''); setMeetingFormOpen(false)
    if (currentToken) setToken(currentToken)
  }

  async function openCourse(course: Course) {
    if (!active || !token) return
    setBusy(true); setError('')
    try {
      const base = `/api/v1/organizations/${active.organization.id}/courses/${course.id}`
      const [lessonResult, assignmentResult, quizResult] = await Promise.all([
        mobileApi.get<{ data: Lesson[] }>(`${base}/lessons`, token),
        mobileApi.get<{ data: CourseAssignment[] }>(`${base}/assignments`, token),
        mobileApi.get<{ data: CourseQuiz[] }>(`${base}/quizzes`, token),
      ])
      const instructor = active.role !== 'student'
      const withSubmissions = instructor ? await Promise.all(assignmentResult.data.map(async assignment => {
        const result = await mobileApi.get<{ data: NonNullable<CourseAssignment['submissions']> }>(`/api/v1/organizations/${active.organization.id}/assignments/${assignment.id}/submissions`, token)
        return { ...assignment, submissions: result.data }
      })) : assignmentResult.data
      setSelectedCourse(course); setLessons(lessonResult.data); setAssignments(withSubmissions); setQuizzes(quizResult.data); setQuizRun(null)
      setAssignmentDrafts(Object.fromEntries(assignmentResult.data.map(item => [item.id, item.submission?.answer ?? ''])))
      setGradingDrafts(Object.fromEntries(withSubmissions.flatMap(item => (item.submissions ?? []).map(submission => [submission.id, { score: submission.awarded_points == null ? '' : String(submission.awarded_points), feedback: submission.feedback ?? '' }]))))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not open this course.') }
    finally { setBusy(false) }
  }

  async function openStudyMaterial(material: StudyMaterial) {
    if (!active || !token) return
    setBusy(true); setError('')
    try {
      const base = `/api/v1/organizations/${active.organization.id}/assets/${material.id}/download-link`
      const result = await mobileApi.post<{ data: { path: string; expires_at: string } }>(base, token)
      await Linking.openURL(mobileApi.absoluteUrl(result.data.path))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not open this study material.') }
    finally { setBusy(false) }
  }

  async function openMeetingRecording(meetingId: number, recording: MobileRecording) {
    if (!active || !token) return
    setBusy(true); setError('')
    try {
      if (recording.external_url) {
        await Linking.openURL(recording.external_url)
      } else {
        const base = `/api/v1/organizations/${active.organization.id}/meetings/${meetingId}/recordings/${recording.id}/download-link`
        const result = await mobileApi.post<{ data: { path: string; expires_at: string } }>(base, token)
        await Linking.openURL(mobileApi.absoluteUrl(result.data.path))
      }
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not open this recording.') }
    finally { setBusy(false) }
  }

  async function submitAssignment(assignment: CourseAssignment) {
    if (!active || !token) return
    setBusy(true); setError('')
    try {
      const result = await mobileApi.post<{ data: CourseAssignment['submission'] }>(`/api/v1/organizations/${active.organization.id}/assignments/${assignment.id}/submissions`, token, { answer: assignmentDrafts[assignment.id] ?? '' })
      setAssignments(items => items.map(item => item.id === assignment.id ? { ...item, submission: result.data } : item))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit this assignment.') }
    finally { setBusy(false) }
  }

  async function gradeSubmission(submissionId: number) {
    if (!active || !token) return
    const draft = gradingDrafts[submissionId]
    if (!draft || draft.score.trim() === '' || !Number.isFinite(Number(draft.score))) { setError('Enter a valid score before grading.'); return }
    setBusy(true); setError('')
    try {
      const result = await mobileApi.patch<{ data: NonNullable<CourseAssignment['submissions']>[number] }>(`/api/v1/organizations/${active.organization.id}/submissions/${submissionId}/grade`, token, { awarded_points: Number(draft.score), feedback: draft.feedback })
      setAssignments(items => items.map(item => ({ ...item, submissions: item.submissions?.map(submission => submission.id === submissionId ? { ...submission, ...result.data } : submission) })))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not grade this submission.') }
    finally { setBusy(false) }
  }

  async function loadExamPaper(exam: SchoolExam, paper: SchoolExamPaper) {
    if (!active || !token) return
    setBusy(true); setError(''); setSuccess('')
    try {
      const result = await mobileApi.get<{ data: SchoolExamMark[] }>(`/api/v1/organizations/${active.organization.id}/exam-papers/${paper.id}/results`, token)
      setSelectedExamPaper({ exam, paper })
      setExamMarks(result.data.map(mark => ({ ...mark, section_scores: mark.section_scores ?? {} })))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load this exam roster.') }
    finally { setBusy(false) }
  }

  function updateExamMark(studentId: number, update: Partial<SchoolExamMark>) {
    setExamMarks(current => current.map(mark => mark.student_id === studentId ? { ...mark, ...update } : mark))
  }

  async function saveExamMarks() {
    if (!active || !token || !selectedExamPaper || selectedExamPaper.exam.status !== 'draft') return
    const { exam, paper } = selectedExamPaper
    const records = examMarks.filter(mark => mark.outcome !== null).map(mark => ({
      student_id: mark.student_id,
      outcome: mark.outcome as Exclude<SchoolExamMark['outcome'], null>,
      score: mark.outcome === 'graded' ? mark.score : null,
      remarks: mark.remarks?.trim() || null,
      section_scores: mark.outcome === 'graded' ? paper.paper_sections.map(section => ({
        section_id: section.id, score: mark.section_scores[String(section.id)] ?? '',
      })) : [],
    }))
    if (!records.length) { setError('Choose graded, absent, or excused for at least one learner.'); return }
    const invalidTotals = records.filter(record => record.outcome === 'graded' && (record.score === null || record.score.trim() === '' || !Number.isFinite(Number(record.score)) || Number(record.score) < 0 || Number(record.score) > Number(paper.max_marks)))
    if (invalidTotals.length) { setError('Enter a valid total score within the paper maximum for every graded learner.'); return }
    const invalidSections = examMarks.filter(mark => mark.outcome === 'graded').some(mark => {
      const scores = paper.paper_sections.map(section => ({ section, value: mark.section_scores[String(section.id)] ?? '' }))
      if (scores.some(item => item.value.trim() === '' || !Number.isFinite(Number(item.value)) || Number(item.value) < 0 || Number(item.value) > Number(item.section.max_marks))) return true
      const partsTotal = scores.reduce((total, item) => total + Math.round(Number(item.value) * 100), 0)
      return paper.paper_sections.length > 0 && partsTotal !== Math.round(Number(mark.score ?? 0) * 100)
    })
    if (invalidSections) { setError('Enter each paper-section score within its maximum, and make sure section scores add up to the total.'); return }

    setBusy(true); setError(''); setSuccess('')
    const base = `/api/v1/organizations/${active.organization.id}`
    try {
      await mobileApi.put(`${base}/exam-papers/${paper.id}/results`, token, { records })
      const [examResult, markResult] = await Promise.all([
        mobileApi.get<{ data: SchoolExam[] }>(`${base}/exams`, token),
        mobileApi.get<{ data: SchoolExamMark[] }>(`${base}/exam-papers/${paper.id}/results`, token),
      ])
      setTeacherExams(examResult.data)
      const refreshedExam = examResult.data.find(item => item.id === exam.id) ?? exam
      const refreshedPaper = refreshedExam.papers.find(item => item.id === paper.id) ?? paper
      setSelectedExamPaper({ exam: refreshedExam, paper: refreshedPaper })
      setExamMarks(markResult.data.map(mark => ({ ...mark, section_scores: mark.section_scores ?? {} })))
      setSuccess('Exam marks saved. Managers can publish results after every active learner has an outcome.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save exam marks.') }
    finally { setBusy(false) }
  }

  async function startQuiz(quiz: CourseQuiz) {
    if (!active || !token) return
    setBusy(true); setError('')
    try {
      const result = await mobileApi.post<{ data: QuizRun }>(`/api/v1/organizations/${active.organization.id}/quizzes/${quiz.id}/attempts`, token)
      setQuizRun(result.data); setQuizAnswers({})
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not start this quiz.') }
    finally { setBusy(false) }
  }

  async function submitQuiz() {
    if (!active || !token || !quizRun) return
    setBusy(true); setError('')
    try {
      const answers = Object.entries(quizAnswers).map(([questionId, selectedOptions]) => {
        const question = quizRun.questions.find(item => item.id === Number(questionId))
        if (question?.question_type === 'short_answer') return { question_id: Number(questionId), answer_text: typeof selectedOptions === 'string' ? selectedOptions : '' }
        const selected = Array.isArray(selectedOptions) ? selectedOptions : []
        return question?.question_type === 'multiple_select'
          ? { question_id: Number(questionId), selected_options: selected }
          : { question_id: Number(questionId), selected_option: selected[0] }
      })
      const result = await mobileApi.patch<{ data: QuizRun['attempt'] }>(`/api/v1/organizations/${active.organization.id}/quizzes/${quizRun.quiz.id}/attempts/${quizRun.attempt.id}`, token, { answers })
      setQuizzes(items => items.map(item => item.id === quizRun.quiz.id ? { ...item, attempts_used: (item.attempts_used ?? 0) + 1, latest_result: result.data } : item))
      setQuizRun(null)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit this quiz.') }
    finally { setBusy(false) }
  }

  async function markNoticeRead(notice: MobileNotice) {
    if (!active || !token || notice.read_at) return
    const organizationId = active.organization.id
    const workspaceContext = workspaceContextRef.current
    try {
      await mobileApi.post(`/api/v1/organizations/${organizationId}/notifications/${notice.id}/read`, token)
      if (workspaceContextRef.current !== workspaceContext) return
      setNotices(items => items.map(item => item.id === notice.id ? { ...item, read_at: new Date().toISOString() } : item))
    } catch (reason) {
      if (workspaceContextRef.current === workspaceContext) setError(reason instanceof Error ? reason.message : 'Could not update this notification.')
    }
  }

  async function openNotice(notice: MobileNotice) {
    const workspaceContext = workspaceContextRef.current
    await markNoticeRead(notice)
    if (workspaceContextRef.current !== workspaceContext) return
    if (active?.role !== 'student' || !notice.type.startsWith('learning_') || !notice.course_id) return
    const course = courses.find(item => item.id === notice.course_id)
    if (!course) {
      setError('This course is no longer available in your workspace.')
      return
    }
    await openCourse(course)
  }

  async function loadMoreNotices() {
    if (!active || !token || noticeLoading || noticePage >= noticeLastPage) return
    const organizationId = active.organization.id
    const workspaceContext = workspaceContextRef.current
    setNoticeLoading(true)
    setError('')
    try {
      const nextPage = noticePage + 1
      const result = await mobileApi.get<{ data: MobileNotice[]; meta: { current_page: number; last_page: number } }>(
        `/api/v1/organizations/${organizationId}/notifications?per_page=10&page=${nextPage}`,
        token,
      )
      if (workspaceContextRef.current !== workspaceContext) return
      setNotices(current => {
        const knownIds = new Set(current.map(item => item.id))
        return [...current, ...result.data.filter(item => !knownIds.has(item.id))]
      })
      setNoticePage(result.meta.current_page)
      setNoticeLastPage(result.meta.last_page)
    } catch (reason) {
      if (workspaceContextRef.current === workspaceContext) setError(reason instanceof Error ? reason.message : 'Could not load more updates.')
    } finally {
      if (workspaceContextRef.current === workspaceContext) setNoticeLoading(false)
    }
  }

  async function loadAttendance(assignment: TeachingAssignment) {
    if (!active || !token) return
    setBusy(true); setError(''); setSuccess(''); setAttendanceSection(assignment); setAttendanceStudents([]); setAttendanceDate(localDate())
    try {
      const result = await mobileApi.get<{ data: AttendanceStudent[] }>(`/api/v1/organizations/${active.organization.id}/attendance?section_id=${assignment.academic_section_id}&date=${localDate()}&per_page=200`, token)
      setAttendanceStudents(result.data.map(student => ({ ...student, originalStatus: student.status, correctionReason: '' })))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load this attendance register.') }
    finally { setBusy(false) }
  }

  async function saveAttendance() {
    if (!active || !token || !attendanceSection) return
    if (attendanceStudents.some(student => !student.status)) { setError('Choose an attendance status for every learner before saving.'); return }
    if (attendanceStudents.some(student => student.originalStatus !== null && student.status !== student.originalStatus && !student.correctionReason.trim())) { setError('Add a reason for each change to a saved attendance mark.'); return }
    setBusy(true); setError(''); setSuccess('')
    try {
      await mobileApi.put(`/api/v1/organizations/${active.organization.id}/attendance`, token, {
        section_id: attendanceSection.academic_section_id,
        date: attendanceDate,
        records: attendanceStudents.map(student => ({ student_id: student.id, status: student.status, correction_reason: student.originalStatus !== null && student.status !== student.originalStatus ? student.correctionReason.trim() : null })),
      })
      setAttendanceStudents(students => students.map(student => ({ ...student, originalStatus: student.status, correctionReason: '' })))
      setSuccess('Attendance saved successfully.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save attendance.') }
    finally { setBusy(false) }
  }

  async function scheduleMeeting() {
    if (!active || !token) return
    const manager = active.role === 'admin' || active.role === 'management' || active.role === 'independent_educator'
    const kind = active.role === 'teacher' ? 'class' : meetingKind
    const selectableSections: MobileMeetingSection[] = manager
      ? meetingSections
      : teachingAssignments.map(item => ({ id: item.academic_section_id, name: item.academic_section.name, academic_session_id: item.academic_session_id, academic_class: { name: item.academic_section.academic_class.name } }))
    const section = kind === 'class' ? selectableSections.find(item => `${item.id}:${item.academic_session_id}` === meetingSectionId) : undefined
    if (kind === 'class' && !section) { setError('Choose an assigned class section for this meeting.'); return }
    const startsAt = new Date(meetingStartsAt)
    const endsAt = new Date(meetingEndsAt)
    if (!Number.isFinite(startsAt.getTime()) || startsAt.getTime() < Date.now()) { setError('Enter a valid future start date and time.'); return }
    if (!Number.isFinite(endsAt.getTime()) || endsAt <= startsAt) { setError('The meeting end must be later than its start.'); return }
    setBusy(true); setError(''); setSuccess('')
    try {
      const base = `/api/v1/organizations/${active.organization.id}`
      const response = await mobileApi.post<{ data: MobileMeeting }>(`${base}/meetings`, token, {
        kind,
        academic_section_id: section?.id ?? null,
        academic_session_id: section?.academic_session_id ?? null,
        title: meetingTitle.trim(), description: meetingDescription.trim() || null,
        starts_at: startsAt.toISOString(), ends_at: endsAt.toISOString(), meeting_url: meetingUrl.trim(),
      })
      setMeetings(current => [...current.filter(item => item.id !== response.data.id), response.data].sort((left, right) => new Date(left.starts_at).getTime() - new Date(right.starts_at).getTime()))
      setMeetingTitle(''); setMeetingDescription(''); setMeetingSectionId(''); setMeetingUrl(''); setMeetingStartsAt(localDateTimeInput(24)); setMeetingEndsAt(localDateTimeInput(25)); setMeetingKind('class'); setMeetingFormOpen(false); setSuccess('Meeting scheduled for this workspace.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not schedule this meeting.') }
    finally { setBusy(false) }
  }

  async function cancelMobileMeeting(meeting: MobileMeeting) {
    if (!active || !token) return
    setBusy(true); setError(''); setSuccess('')
    try {
      const response = await mobileApi.patch<{ data: MobileMeeting }>(`/api/v1/organizations/${active.organization.id}/meetings/${meeting.id}`, token, { status: 'cancelled' })
      setMeetings(current => current.map(item => item.id === response.data.id ? response.data : item))
      setSuccess('Meeting cancelled.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not cancel this meeting.') }
    finally { setBusy(false) }
  }

  async function saveAccountProfile() {
    if (!token) return
    if (newPassword && newPassword !== passwordConfirmation) {
      setError('The new password and confirmation do not match.')
      return
    }
    setBusy(true); setError(''); setSuccess(''); setVerificationUrl('')
    try {
      const response = await mobileApi.patch<{ data: ApiUser; email_changed: boolean; password_changed: boolean; verification_url?: string }>('/api/v1/me/profile', token, {
        name: profileName.trim(), email: profileEmail.trim(), current_password: currentPassword,
        ...(newPassword ? { password: newPassword, password_confirmation: passwordConfirmation } : {}),
      })
      setUser(response.data)
      setProfileName(response.data.name); setProfileEmail(response.data.email)
      setCurrentPassword(''); setNewPassword(''); setPasswordConfirmation('')
      setVerificationUrl(response.verification_url ?? '')
      setSuccess(response.email_changed
        ? 'Email updated. Verify the new address before using your workspace again.'
        : response.password_changed ? 'Account details and password updated. Other app sessions were signed out.' : 'Account details updated.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update account details.') }
    finally { setBusy(false) }
  }

  async function refreshVerificationStatus() {
    if (!token) return
    setBusy(true); setError(''); setSuccess('')
    try {
      const response = await mobileApi.get<{ data: ApiUser }>('/api/v1/me', token)
      setUser(response.data)
      setSuccess(response.data.email_verified_at ? 'Your email is verified. You can continue using your workspace.' : 'Email verification is still pending. Open the verification link first.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not refresh account status.') }
    finally { setBusy(false) }
  }

  async function signOut() {
    const current = token
    setBusy(true)
    try {
      const pushToken = user ? await SecureStore.getItemAsync(mobilePushTokenKey(user.id)) : null
      if (current && pushToken) await mobileApi.delete('/api/v1/me/push-devices', current, { token: pushToken })
    }
    catch { /* The logout below still runs if push registration cleanup is unavailable. */ }
    try { if (current) await mobileApi.post('/api/v1/auth/logout', current) }
    catch { /* Local credentials must still be removed if the API is offline. */ }
    if (user) await SecureStore.deleteItemAsync(mobilePushTokenKey(user.id))
    await SecureStore.deleteItemAsync(TOKEN_KEY); await SecureStore.deleteItemAsync(ORGANIZATION_KEY)
    workspaceContextRef.current = null
    setToken(''); setUser(null); setMemberships([]); setActive(null); setSummary(null); setNotices([]); setNoticePage(1); setNoticeLastPage(1); setNoticeLoading(false); setMeetings([]); setMeetingSections([]); setMeetingFormOpen(false); setMeetingKind('class'); setMeetingSectionId(''); setMeetingTitle(''); setMeetingDescription(''); setMeetingUrl(''); setMeetingRecordings({}); setStudentProfile(null); setFamilyChildren([]); setOwnAttendance([]); setOwnResults({ exams: [], quizzes: [], assignments: [] }); setOwnProgress(null); setOwnHomework([]); setFamilyFees([]); setStudyMaterials([]); setRosterStudents([]); setRosterTeachers([]); setRosterStudentTotal(0); setRosterTeacherTotal(0); setAttendanceReport(null); setCourseProgressReport(null); setAssessmentReport(null); setEnrollmentReport(null); setTeacherWorkloadReport(null); setExamSectionSummaries([]); setExamSeriesComparisons([]); setFinanceReport(null); setTeacherExams([]); setSelectedExamPaper(null); setExamMarks([]); setCourses([]); setSelectedCourse(null); setLessons([]); setAssignments([]); setQuizzes([]); setQuizRun(null); setTeachingAssignments([]); setAttendanceSection(null); setAttendanceStudents([]); setAccountOpen(false); setCurrentPassword(''); setNewPassword(''); setPasswordConfirmation(''); setVerificationUrl(''); setBusy(false)
  }

  if (busy && !user) return <Splash />
  if (!user) return <Login email={email} setEmail={value => { setEmail(value); setVerificationNeeded(false); setError(''); setSuccess('') }} password={password} setPassword={setPassword} busy={busy} error={error} success={success} verificationNeeded={verificationNeeded} onResend={() => void resendVerification()} onSubmit={() => void signIn()} />
  if (!active) return <OrganizationPicker user={user} memberships={memberships} busy={busy} error={error} onChoose={membership => void chooseOrganization(membership)} onSignOut={() => void signOut()} />
  const canScheduleMeetings = ['admin', 'management', 'independent_educator', 'teacher'].includes(active.role)
  const selectableMeetingSections: MobileMeetingSection[] = active.role === 'teacher'
    ? teachingAssignments.map(item => ({ id: item.academic_section_id, name: item.academic_section.name, academic_session_id: item.academic_session_id, academic_class: { name: item.academic_section.academic_class.name } }))
    : meetingSections

  return <View style={styles.screen}><StatusBar barStyle="dark-content" backgroundColor="#f4f7fc" />
    <View style={styles.topBar}><View style={{ flex: 1 }}><Text style={styles.brand}>edTechLive</Text><Text style={styles.orgName}>{active.organization.name}</Text></View><Pressable onPress={() => { setAccountOpen(true); setProfileName(user.name); setProfileEmail(user.email); setCurrentPassword(''); setNewPassword(''); setPasswordConfirmation(''); setError(''); setSuccess(''); setVerificationUrl('') }} style={styles.topAction}><Text style={styles.topActionText}>Account</Text></Pressable><Pressable onPress={() => { workspaceContextRef.current = null; setAccountOpen(false); setActive(null); setSelectedCourse(null) }} style={styles.topAction}><Text style={styles.topActionText}>Switch</Text></Pressable><Pressable onPress={() => void signOut()} style={styles.topAction}><Text style={styles.topActionText}>Sign out</Text></Pressable></View>
    <ScrollView contentContainerStyle={styles.content}>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {success ? <Text style={styles.success}>{success}</Text> : null}
      {accountOpen ? <>
        <Pressable onPress={() => { setAccountOpen(false); setVerificationUrl(''); setCurrentPassword(''); setNewPassword(''); setPasswordConfirmation('') }}><Text style={styles.backLink}>‹  Dashboard</Text></Pressable>
        <Text style={styles.pageTitle}>Account profile</Text>
        <Text style={styles.subtitle}>Update your account details on all your edTechLive devices.</Text>
        <View style={styles.infoCard}><Text style={styles.courseTitle}>Email status</Text><Text style={styles.courseMeta}>{user.email_verified_at ? 'Verified' : 'Verification required'}</Text></View>
        <Text style={styles.inputLabel}>Full name</Text><TextInput autoComplete="name" value={profileName} onChangeText={setProfileName} maxLength={120} style={styles.input} editable={!busy} accessibilityLabel="Full name" />
        <Text style={styles.inputLabel}>Email address</Text><TextInput autoCapitalize="none" autoComplete="email" keyboardType="email-address" value={profileEmail} onChangeText={setProfileEmail} maxLength={254} style={styles.input} editable={!busy} accessibilityLabel="Email address" />
        <Text style={styles.inputLabel}>Current password</Text><TextInput autoComplete="current-password" secureTextEntry value={currentPassword} onChangeText={setCurrentPassword} style={styles.input} editable={!busy} accessibilityLabel="Current password" />
        <Text style={styles.inputLabel}>New password (optional)</Text><TextInput autoComplete="new-password" secureTextEntry value={newPassword} onChangeText={setNewPassword} maxLength={160} placeholder="At least 12 characters, with letters and numbers" style={styles.input} editable={!busy} accessibilityLabel="New password" />
        <Text style={styles.inputLabel}>Confirm new password</Text><TextInput autoComplete="new-password" secureTextEntry value={passwordConfirmation} onChangeText={setPasswordConfirmation} maxLength={160} style={styles.input} editable={!busy} accessibilityLabel="Confirm new password" />
        <Text style={styles.courseMeta}>Your current password confirms each update. A changed email must be verified again. Password changes sign out your other app devices.</Text>
        <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void saveAccountProfile()} disabled={busy || !profileName.trim() || !profileEmail.trim() || !currentPassword}><Text style={styles.primaryButtonText}>{busy ? 'Saving…' : 'Save account details'}</Text></Pressable>
        <NotificationPreferencesPanel token={token} organizationId={active.organization.id} userId={user.id} />
        {verificationUrl ? <View style={styles.activityCard}><Text style={styles.courseMeta}>Open the development verification link, then check your account status again.</Text><Pressable onPress={() => void Linking.openURL(verificationUrl)}><Text style={styles.link}>Verify email address</Text></Pressable><Pressable onPress={() => void refreshVerificationStatus()} disabled={busy}><Text style={styles.link}>Refresh verification status</Text></Pressable></View> : null}
      </> : attendanceSection ? <>
        <Pressable onPress={() => { setAttendanceSection(null); setAttendanceStudents([]) }}><Text style={styles.backLink}>‹  Dashboard</Text></Pressable>
        <Text style={styles.pageTitle}>Take attendance</Text>
        <Text style={styles.subtitle}>{attendanceSection.academic_section.academic_class.name} · {attendanceSection.academic_section.name} · {attendanceSection.subject.name}</Text>
        <Text style={styles.courseMeta}>Date: {attendanceDate} · {attendanceSection.academic_session.name}</Text>
        {attendanceStudents.length ? attendanceStudents.map(student => {
          const correcting = student.originalStatus !== null && student.status !== student.originalStatus
          return <View style={styles.studentRow} key={student.id}><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{student.name}</Text><Text style={styles.courseMeta}>{student.admission_number || 'Learner'}</Text>{correcting ? <TextInput accessibilityLabel={`Correction reason for ${student.name}`} value={student.correctionReason} onChangeText={correctionReason => setAttendanceStudents(items => items.map(item => item.id === student.id ? { ...item, correctionReason } : item))} placeholder="Why is this saved mark changing?" maxLength={500} style={styles.attendanceReasonInput} /> : null}</View><View style={styles.statusRow}>{attendanceStatuses.map(status => <Pressable key={status} onPress={() => setAttendanceStudents(items => items.map(item => item.id === student.id ? { ...item, status } : item))} style={[styles.statusChip, student.status === status && styles.statusChipSelected]}><Text style={[styles.statusChipText, student.status === status && styles.statusChipTextSelected]}>{status.slice(0, 1).toUpperCase()}</Text></Pressable>)}</View></View>
        }) : <Empty text={busy ? 'Loading class register…' : 'No active learners found for this class and date.'} />}
        {attendanceStudents.length ? <Text style={styles.courseMeta}>P present · A absent · L late · E excused. All learners need a selection before saving.</Text> : null}
        {attendanceStudents.length ? <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void saveAttendance()} disabled={busy}><Text style={styles.primaryButtonText}>{busy ? 'Saving…' : 'Save attendance'}</Text></Pressable> : null}
      </> : selectedCourse ? <>
        <Pressable onPress={() => setSelectedCourse(null)}><Text style={styles.backLink}>‹  All courses</Text></Pressable>
        <Text style={styles.pageTitle}>{selectedCourse.title}</Text><Text style={styles.subtitle}>{selectedCourse.subject?.name ?? 'Course'}{selectedCourse.teacher?.name ? ` · ${selectedCourse.teacher.name}` : ''}</Text>
        {quizRun ? <View style={styles.activityCard}>
          <Pressable onPress={() => setQuizRun(null)}><Text style={styles.backLink}>‹  Course content</Text></Pressable>
          <Text style={styles.sectionTitle}>{quizRun.quiz.title}</Text>
          {quizRun.quiz.instructions ? <Text style={styles.courseMeta}>{quizRun.quiz.instructions}</Text> : null}
          {quizRun.questions.map((question, index) => <View key={question.id} style={styles.questionBlock}>
            <Text style={styles.courseTitle}>{index + 1}. {question.prompt} · {question.difficulty}</Text>
            {question.question_type === 'short_answer' ? <Text style={styles.courseMeta}>Written response · {question.points} points · teacher reviewed</Text> : null}
            {question.question_type === 'short_answer' ? <TextInput multiline maxLength={10000} value={typeof quizAnswers[question.id] === 'string' ? quizAnswers[question.id] as string : ''} onChangeText={value => setQuizAnswers(current => ({ ...current, [question.id]: value }))} placeholder="Write your response" style={styles.answerInput} editable={!busy} /> : null}
            {question.question_type === 'multiple_select' ? <Text style={styles.courseMeta}>Select all that apply.</Text> : null}
            {question.question_type !== 'short_answer' && question.options.map((option, optionIndex) => <Pressable key={optionIndex} onPress={() => setQuizAnswers(current => {
              const selected = current[question.id] ?? []
              const next = question.question_type === 'multiple_select'
                ? (Array.isArray(selected) && selected.includes(optionIndex) ? selected.filter(item => item !== optionIndex) : [...(Array.isArray(selected) ? selected : []), optionIndex].sort())
                : [optionIndex]
              return { ...current, [question.id]: next }
            })} style={[styles.option, Array.isArray(quizAnswers[question.id]) && (quizAnswers[question.id] as number[]).includes(optionIndex) && styles.optionSelected]}>
              <Text style={styles.courseMeta}>{String.fromCharCode(65 + optionIndex)}. {option}</Text>
            </Pressable>)}
          </View>)}
          <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void submitQuiz()} disabled={busy}><Text style={styles.primaryButtonText}>{busy ? 'Submitting…' : 'Submit quiz'}</Text></Pressable>
        </View> : <>
        <Text style={styles.sectionTitle}>Lessons</Text>
        {lessons.length ? lessons.filter(lesson => lesson.status === 'published').map((lesson, index) => <View style={styles.lessonCard} key={lesson.id}><Text style={styles.lessonNumber}>{String(index + 1).padStart(2, '0')}</Text><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{lesson.title}</Text>{lesson.content ? <Text style={styles.courseMeta} numberOfLines={3}>{lesson.content}</Text> : null}{lesson.external_url ? <Pressable onPress={() => void Linking.openURL(lesson.external_url!)}><Text style={styles.link}>Open lesson resource</Text></Pressable> : null}</View></View>) : <Empty text="No published lessons are available in this course yet." />}
        <Text style={styles.sectionTitle}>Assignments</Text>
        {assignments.length ? assignments.map(item => <View style={styles.activityCard} key={item.id}>
          <Text style={styles.courseTitle}>{item.title}</Text>
          <Text style={styles.courseMeta}>{item.instructions || 'Written response'} · Up to {item.max_points} points{item.due_at ? ` · Due ${new Date(item.due_at).toLocaleDateString()}` : ''}</Text>
          {item.submission?.graded_at ? <Text style={styles.resultText}>Graded: {item.submission.awarded_points}/{item.max_points}{item.submission.feedback ? ` · ${item.submission.feedback}` : ''}</Text> : null}
          {active.role === 'student' ? <><TextInput multiline value={assignmentDrafts[item.id] ?? ''} onChangeText={value => setAssignmentDrafts(current => ({ ...current, [item.id]: value }))} placeholder="Write your response" style={styles.answerInput} editable={!busy && !item.submission?.graded_at} /><Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void submitAssignment(item)} disabled={busy || !assignmentDrafts[item.id]?.trim() || Boolean(item.submission?.graded_at)}><Text style={styles.primaryButtonText}>{item.submission ? 'Update submission' : 'Submit assignment'}</Text></Pressable></> : null}
          {active.role !== 'student' ? (item.submissions?.length ? item.submissions.map(submission => <View style={styles.submissionCard} key={submission.id}><Text style={styles.courseTitle}>{submission.student.name}</Text><Text style={styles.courseMeta}>{submission.student.admission_number ?? 'Learner'} · Submitted {submission.submitted_at ? new Date(submission.submitted_at).toLocaleDateString() : 'recently'}</Text><Text style={styles.answerText}>{submission.answer}</Text>{submission.graded_at ? <Text style={styles.resultText}>Current score: {submission.awarded_points}/{item.max_points}</Text> : null}<TextInput keyboardType="decimal-pad" value={gradingDrafts[submission.id]?.score ?? ''} onChangeText={value => setGradingDrafts(current => ({ ...current, [submission.id]: { score: value, feedback: current[submission.id]?.feedback ?? '' } }))} placeholder={`Score out of ${item.max_points}`} style={styles.input} editable={!busy} /><TextInput value={gradingDrafts[submission.id]?.feedback ?? ''} onChangeText={value => setGradingDrafts(current => ({ ...current, [submission.id]: { score: current[submission.id]?.score ?? '', feedback: value } }))} placeholder="Feedback for the learner" style={styles.input} editable={!busy} /><Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void gradeSubmission(submission.id)} disabled={busy}><Text style={styles.primaryButtonText}>{submission.graded_at ? 'Update grade' : 'Save grade'}</Text></Pressable></View>) : <Text style={styles.courseMeta}>No learner submissions yet.</Text>) : null}
        </View>) : <Empty text="No published assignments in this course." />}
        <Text style={styles.sectionTitle}>Quizzes</Text>
        {quizzes.length ? quizzes.map(quiz => {
          const notOpen = quiz.opens_at !== null && Date.now() < Date.parse(quiz.opens_at)
          const closed = quiz.closes_at !== null && Date.now() >= Date.parse(quiz.closes_at)
          return <View style={styles.activityCard} key={quiz.id}>
            <Text style={styles.courseTitle}>{quiz.title}</Text>
            <Text style={styles.courseMeta}>{quiz.questions.length} questions · {quiz.time_limit_minutes ? `${quiz.time_limit_minutes} minutes` : 'No time limit'} · {quiz.attempts_used ?? 0}/{quiz.max_attempts} attempts</Text>
            {quiz.opens_at ? <Text style={styles.courseMeta}>Opens {new Date(quiz.opens_at).toLocaleString()}</Text> : null}
            {quiz.closes_at ? <Text style={styles.courseMeta}>Closes {new Date(quiz.closes_at).toLocaleString()}</Text> : null}
            {quiz.latest_result?.submitted_at ? <Text style={styles.resultText}>{quiz.latest_result.review_status === 'pending' || quiz.latest_result.review_status === 'partial' ? 'Awaiting teacher review' : 'Latest result'}: {quiz.latest_result.score}/{quiz.latest_result.max_score}{quiz.latest_result.result_outcome === 'passed' ? ' · Passed' : quiz.latest_result.result_outcome === 'failed' ? ' · Not passed' : ''}</Text> : null}
            {active.role === 'student' && (quiz.attempts_used ?? 0) < quiz.max_attempts ? <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void startQuiz(quiz)} disabled={busy || notOpen || closed}><Text style={styles.primaryButtonText}>{notOpen ? 'Quiz not open yet' : closed ? 'Quiz closed' : 'Start quiz'}</Text></Pressable> : null}
          </View>
        }) : <Empty text="No published quizzes in this course." />}
        </>}
      </> : <>
        <Text style={styles.eyebrow}>YOUR LEARNING SPACE</Text><Text style={styles.pageTitle}>Welcome, {user.name.split(' ')[0]}</Text><Text style={styles.subtitle}>{roleNames[active.role]} · {active.organization.type.replaceAll('_', ' ')}</Text>
        {summary ? <View style={styles.statGrid}>{summaryCards(summary, active.role).map(item => <View style={styles.statCard} key={item.label}><Text style={styles.statValue}>{item.value}</Text><Text style={styles.statLabel}>{item.label}</Text></View>)}</View> : null}
        {['admin', 'management', 'independent_educator'].includes(active.role) ? <>
          <StudentRosterSection items={rosterStudents} total={rosterStudentTotal} search={rosterStudentSearch} loading={rosterStudentLoading} error={rosterStudentError} page={rosterStudentPage} lastPage={rosterStudentLastPage}
            onChangeSearch={setRosterStudentSearch} onSearch={() => void loadRoster('students', 1, rosterStudentSearch)} onLoadMore={() => void loadRoster('students', rosterStudentPage + 1, rosterStudentSearch, true)} />
          <TeacherRosterSection items={rosterTeachers} total={rosterTeacherTotal} search={rosterTeacherSearch} loading={rosterTeacherLoading} error={rosterTeacherError} page={rosterTeacherPage} lastPage={rosterTeacherLastPage}
            onChangeSearch={setRosterTeacherSearch} onSearch={() => void loadRoster('teachers', 1, rosterTeacherSearch)} onLoadMore={() => void loadRoster('teachers', rosterTeacherPage + 1, rosterTeacherSearch, true)} />
          <Text style={styles.sectionTitle}>Workspace invitations</Text>
          <Pressable style={styles.courseCard} onPress={() => { setInvitationFormOpen(open => !open); setError(''); setSuccess('') }} accessibilityRole="button" accessibilityState={{ expanded: invitationFormOpen }}>
            <View style={{ flex: 1 }}><Text style={styles.courseTitle}>{invitationFormOpen ? 'Close invitation form' : 'Invite a family or staff member'}</Text><Text style={styles.courseMeta}>Invitation email uses the API’s configured mail service. Links expire after seven days.</Text></View><Text style={styles.chevron}>{invitationFormOpen ? '−' : '+'}</Text>
          </Pressable>
          {invitationFormOpen ? <View style={styles.activityCard}>
            <Text style={styles.inputLabel}>Invite as</Text>
            <View style={styles.examOutcomeRow}>{(['parent', 'student', 'teacher', ...(active.role === 'admin' ? ['management' as const] : [])] as const).map(role => {
              const selected = invitationRole === role
              return <Pressable key={role} onPress={() => { setInvitationRole(role); setInvitationStudentIds([]) }} style={[styles.examOutcome, selected && styles.examOutcomeSelected]} accessibilityRole="radio" accessibilityState={{ selected }}>
                <Text style={[styles.examOutcomeText, selected && styles.examOutcomeTextSelected]}>{roleNames[role]}</Text>
              </Pressable>
            })}</View>
            <Text style={styles.inputLabel}>Email address</Text>
            <TextInput autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email" value={invitationEmail} onChangeText={setInvitationEmail} maxLength={254} placeholder="name@example.com" style={styles.input} editable={!invitationSaving} accessibilityLabel="Invitation email address" />
            {invitationRole === 'parent' ? <>
              <Text style={styles.courseMeta}>Choose the active learner records this guardian may view. Search the student roster above to find other learners.</Text>
              {rosterStudents.length ? rosterStudents.map(student => {
                const selected = invitationStudentIds.includes(student.id)
                return <Pressable key={student.id} onPress={() => {
                  if (selected) setInvitationStudentIds(ids => ids.filter(id => id !== student.id))
                  else if (invitationStudentIds.length >= 20) setError('A parent invitation can include up to 20 learners.')
                  else setInvitationStudentIds(ids => ids.includes(student.id) ? ids : [...ids, student.id])
                }} style={[styles.studentRow, selected && styles.optionSelected]} accessibilityRole="checkbox" accessibilityState={{ checked: selected }}>
                  <View style={{ flex: 1 }}><Text style={styles.courseTitle}>{student.name}</Text><Text style={styles.courseMeta}>{student.admission_number ?? 'Active learner'}{student.academic_class?.name ? ` · ${student.academic_class.name}` : ''}{student.academic_section?.name ? ` · ${student.academic_section.name}` : ''}</Text></View><Text style={styles.courseMeta}>{selected ? 'Selected' : 'Select'}</Text>
                </Pressable>
              }) : <Empty text={rosterStudentLoading ? 'Loading active learners…' : 'Load active students in the roster search above before inviting a guardian.'} />}
              <Text style={styles.courseMeta}>{invitationStudentIds.length} learner{invitationStudentIds.length === 1 ? '' : 's'} selected</Text>
            </> : invitationRole === 'student' ? <Text style={styles.courseMeta}>The email must match an active student profile in this organization.</Text> : invitationRole === 'teacher' ? <Text style={styles.courseMeta}>Use the staff member’s work email. A matching active teacher roster record is linked when the invitation is accepted.</Text> : <Text style={styles.courseMeta}>Only administrators can invite Management members.</Text>}
            <Pressable style={[styles.primaryButton, invitationSaving && styles.disabled]} onPress={() => void sendInvitation()} disabled={invitationSaving || !invitationEmail.trim() || (invitationRole === 'parent' && invitationStudentIds.length === 0)}><Text style={styles.primaryButtonText}>{invitationSaving ? 'Sending…' : 'Send invitation'}</Text></Pressable>
          </View> : null}
          <Text style={styles.inputLabel}>Recent invitations{invitationsLoading ? ' · Updating' : ''}</Text>
          {invitations.length ? invitations.map(invitation => <View key={invitation.id} style={styles.activityCard}>
            <Text style={styles.courseTitle}>{invitation.email}</Text><Text style={styles.courseMeta}>{roleNames[invitation.role]} · {invitation.status}{invitation.status === 'pending' ? ` · Expires ${new Date(invitation.expires_at).toLocaleDateString()}` : ''}</Text>
            {invitation.status === 'pending' ? <Pressable onPress={() => revokeInvitation(invitation)} disabled={invitationSaving} accessibilityRole="button"><Text style={styles.link}>Revoke invitation</Text></Pressable> : null}
          </View>) : <Empty text={invitationsLoading ? 'Loading invitations…' : 'No invitations have been sent in this workspace.'} />}
          <Pressable onPress={() => void loadInvitations()} disabled={invitationsLoading} accessibilityRole="button"><Text style={styles.link}>Refresh invitations</Text></Pressable>
          <MembershipAccessPanel organizationId={active.organization.id} token={token} userId={user.id} />
          <EnrollmentReportPanel report={enrollmentReport} loading={busy} />
          <TeacherWorkloadReportPanel report={teacherWorkloadReport} loading={busy} />
        </> : null}
        {active.role === 'admin' || active.role === 'management' ? <>
          <AttendanceReportPanel report={attendanceReport} from={attendanceFrom} to={attendanceTo} loading={attendanceReportLoading} error={attendanceReportError}
            onChangeFrom={setAttendanceFrom} onChangeTo={setAttendanceTo} onApply={() => applyAttendanceRange()}
            onToday={() => { const today = organizationDate(active.organization.timezone); applyAttendanceRange(today, today) }}
            onWeek={() => { const today = organizationDate(active.organization.timezone); applyAttendanceRange(shiftDate(today, -6), today) }} />
        </> : null}
        {['admin', 'management', 'independent_educator'].includes(active.role) ? <>
          <ExamSectionReportPanel items={examSectionSummaries} loading={busy} role="manager" />
          <Text style={styles.sectionTitle}>Exam series progress</Text>
          <ExamSeriesHistory items={examSeriesComparisons} emptyText="No published exams are available in a named series." />
        </> : null}
        {['admin', 'management', 'independent_educator'].includes(active.role) ? <CourseProgressReportPanel report={courseProgressReport} loading={busy} role="manager" /> : null}
        {active.role === 'teacher' ? <CourseProgressReportPanel report={courseProgressReport} loading={busy} role="teacher" /> : null}
        {active.role === 'teacher' ? <ExamSectionReportPanel items={examSectionSummaries} loading={busy} role="teacher" /> : null}
        {active.role === 'independent_educator' ? <AttendanceReportPanel report={attendanceReport} from={attendanceFrom} to={attendanceTo} loading={attendanceReportLoading} error={attendanceReportError}
          onChangeFrom={setAttendanceFrom} onChangeTo={setAttendanceTo} onApply={() => applyAttendanceRange()}
          onToday={() => { const today = organizationDate(active.organization.timezone); applyAttendanceRange(today, today) }}
          onWeek={() => { const today = organizationDate(active.organization.timezone); applyAttendanceRange(shiftDate(today, -6), today) }} /> : null}
        {['admin', 'management', 'independent_educator'].includes(active.role) ? <AssessmentReportPanel report={assessmentReport} loading={busy} role="manager" /> : null}
        {active.role === 'teacher' ? <AssessmentReportPanel report={assessmentReport} loading={busy} role="teacher" /> : null}
        {['admin', 'management', 'independent_educator'].includes(active.role) ? <FinanceSnapshot report={financeReport} busy={busy} /> : null}
        {canScheduleMeetings ? <>
          <Text style={styles.sectionTitle}>Meeting schedule</Text>
          <Pressable style={styles.courseCard} onPress={() => { setMeetingFormOpen(open => !open); setError(''); setSuccess('') }} accessibilityRole="button" accessibilityState={{ expanded: meetingFormOpen }}><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{meetingFormOpen ? 'Close meeting form' : active.role === 'teacher' ? 'Schedule a class meeting' : 'Schedule a class or management meeting'}</Text><Text style={styles.courseMeta}>Use a secure HTTPS link from Google Meet or another provider.</Text></View><Text style={styles.chevron}>{meetingFormOpen ? '−' : '+'}</Text></Pressable>
          {meetingFormOpen ? <View style={styles.activityCard}>
            {active.role !== 'teacher' ? <><Text style={styles.inputLabel}>Meeting type</Text><View style={styles.examOutcomeRow}><Pressable style={[styles.examOutcome, meetingKind === 'class' && styles.examOutcomeSelected]} onPress={() => setMeetingKind('class')} accessibilityRole="radio" accessibilityState={{ selected: meetingKind === 'class' }}><Text style={[styles.examOutcomeText, meetingKind === 'class' && styles.examOutcomeTextSelected]}>Class</Text></Pressable><Pressable style={[styles.examOutcome, meetingKind === 'management' && styles.examOutcomeSelected]} onPress={() => { setMeetingKind('management'); setMeetingSectionId('') }} accessibilityRole="radio" accessibilityState={{ selected: meetingKind === 'management' }}><Text style={[styles.examOutcomeText, meetingKind === 'management' && styles.examOutcomeTextSelected]}>Management</Text></Pressable></View></> : null}
            {active.role === 'teacher' || meetingKind === 'class' ? <><Text style={styles.inputLabel}>Class section</Text>{selectableMeetingSections.length ? <View style={styles.meetingSectionList}>{selectableMeetingSections.map(section => { const key = `${section.id}:${section.academic_session_id}`; const selected = meetingSectionId === key; return <Pressable key={key} onPress={() => setMeetingSectionId(key)} style={[styles.examOutcome, selected && styles.examOutcomeSelected]} accessibilityRole="radio" accessibilityState={{ selected }}><Text style={[styles.examOutcomeText, selected && styles.examOutcomeTextSelected]}>{section.academic_class.name} · {section.name}</Text></Pressable> })}</View> : <Empty text="No active assigned class sections are available for scheduling." />}</> : null}
            <Text style={styles.inputLabel}>Meeting title</Text><TextInput value={meetingTitle} onChangeText={setMeetingTitle} maxLength={160} placeholder="Class or team meeting" style={styles.input} editable={!busy} accessibilityLabel="Meeting title" />
            <Text style={styles.inputLabel}>Description (optional)</Text><TextInput value={meetingDescription} onChangeText={setMeetingDescription} maxLength={5000} placeholder="Agenda or joining notes" style={styles.input} editable={!busy} accessibilityLabel="Meeting description" />
            <Text style={styles.inputLabel}>Starts · local device time</Text><TextInput value={meetingStartsAt} onChangeText={setMeetingStartsAt} maxLength={32} placeholder="YYYY-MM-DDTHH:mm" style={styles.input} editable={!busy} accessibilityLabel="Meeting start date and time" />
            <Text style={styles.inputLabel}>Ends · local device time</Text><TextInput value={meetingEndsAt} onChangeText={setMeetingEndsAt} maxLength={32} placeholder="YYYY-MM-DDTHH:mm" style={styles.input} editable={!busy} accessibilityLabel="Meeting end date and time" />
            <Text style={styles.inputLabel}>HTTPS meeting link</Text><TextInput autoCapitalize="none" autoCorrect={false} keyboardType="url" value={meetingUrl} onChangeText={setMeetingUrl} maxLength={2048} placeholder="https://meet.google.com/…" style={styles.input} editable={!busy} accessibilityLabel="Meeting URL" />
            <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void scheduleMeeting()} disabled={busy || meetingTitle.trim().length < 2 || !meetingUrl.trim() || ((active.role === 'teacher' || meetingKind === 'class') && !meetingSectionId)}><Text style={styles.primaryButtonText}>{busy ? 'Scheduling…' : 'Schedule meeting'}</Text></Pressable>
          </View> : null}
        </> : null}
        <Text style={styles.sectionTitle}>Recent and upcoming classes</Text>
        {meetings.filter(item => item.status === 'scheduled' && new Date(item.ends_at).getTime() >= Date.now() - 30 * 24 * 60 * 60 * 1000).sort((left, right) => new Date(right.starts_at).getTime() - new Date(left.starts_at).getTime()).slice(0, 10).sort((left, right) => new Date(left.starts_at).getTime() - new Date(right.starts_at).getTime()).map(item => {
          const startsAt = new Date(item.starts_at).getTime()
          const now = Date.now()
          const isLive = startsAt <= now && new Date(item.ends_at).getTime() >= now
          const recordings = meetingRecordings[item.id] ?? []
          const canCancel = ['admin', 'management', 'independent_educator'].includes(active.role) || (active.role === 'teacher' && item.creator?.id === user.id)
          return <View key={item.id} style={styles.noticeCard}><Text style={styles.courseTitle}>{item.title}</Text><Text style={styles.courseMeta}>{new Date(item.starts_at).toLocaleString()} · {item.course?.title ?? ([item.section?.class, item.section?.name].filter(Boolean).join(' · ') || 'Meeting')}</Text>{isLive ? <Pressable onPress={() => void Linking.openURL(item.meeting_url)}><Text style={styles.link}>Join meeting</Text></Pressable> : startsAt > now ? <Text style={styles.courseMeta}>Upcoming</Text> : <Text style={styles.courseMeta}>Class ended</Text>}{canCancel ? <Pressable disabled={busy} onPress={() => Alert.alert('Cancel meeting?', `Cancel “${item.title}” for this workspace?`, [{ text: 'Keep meeting', style: 'cancel' }, { text: 'Cancel meeting', style: 'destructive', onPress: () => void cancelMobileMeeting(item) }])} accessibilityRole="button"><Text style={styles.link}>Cancel meeting</Text></Pressable> : null}{recordings.map(recording => <Pressable key={recording.id} onPress={() => void openMeetingRecording(item.id, recording)} disabled={busy} accessibilityRole="link"><Text style={styles.link}>{busy ? 'Preparing secure link…' : `Open recording: ${recording.title}`}</Text></Pressable>)}</View>
        })}
        {!meetings.some(item => item.status === 'scheduled' && new Date(item.ends_at).getTime() >= Date.now() - 30 * 24 * 60 * 60 * 1000) ? <Empty text="No recent or upcoming meetings are available." /> : null}
        <Text style={styles.sectionTitle}>Recent updates</Text>
        {notices.length ? <>
          {notices.map(notice => <Pressable key={notice.id} onPress={() => void openNotice(notice)} style={[styles.noticeCard, !notice.read_at && styles.noticeUnread]} accessibilityRole="button" accessibilityLabel={`${notice.title}, ${notice.read_at ? 'read' : 'unread'}`} accessibilityHint={active.role === 'student' && notice.course_id && notice.type.startsWith('learning_') ? 'Tap to open the related course.' : notice.read_at ? 'This notice is already read.' : 'Tap to mark this notice as read.'}><Text style={styles.courseTitle}>{notice.title}</Text><Text style={styles.courseMeta}>{notice.body}</Text>{notice.created_at ? <Text style={styles.noticeDate}>{new Date(notice.created_at).toLocaleString()}</Text> : null}{active.role === 'student' && notice.course_id && notice.type.startsWith('learning_') ? <Text style={styles.link}>Open related course</Text> : null}{!notice.read_at ? <Text style={styles.noticeUnreadLabel}>Unread · tap to open or mark read</Text> : null}</Pressable>)}
          {noticePage < noticeLastPage ? <Pressable style={styles.noticeLoadMore} onPress={() => void loadMoreNotices()} disabled={noticeLoading} accessibilityRole="button"><Text style={styles.noticeLoadMoreText}>{noticeLoading ? 'Loading updates…' : 'Load more updates'}</Text></Pressable> : null}
        </> : <Empty text="No recent updates." />}
        {active.role === 'student' && studentProfile ? <View style={styles.infoCard}><Text style={styles.courseTitle}>My profile</Text><Text style={styles.courseMeta}>{studentProfile.admission_number ? `Admission ${studentProfile.admission_number} · ` : ''}{studentProfile.academic_class?.name ?? 'Class not assigned'}{studentProfile.academic_section?.name ? ` · ${studentProfile.academic_section.name}` : ''}{studentProfile.academic_session?.name ? ` · ${studentProfile.academic_session.name}` : ''}</Text></View> : null}
        {active.role === 'student' || active.role === 'parent' ? <>
          <Text style={styles.sectionTitle}>Study materials</Text>
          {studyMaterials.length ? studyMaterials.map(material => <View style={styles.recordCard} key={material.id}><Text style={styles.courseTitle}>{material.title}</Text><Text style={styles.courseMeta}>{material.course.title} · {material.filename}</Text><Pressable onPress={() => void openStudyMaterial(material)} disabled={busy} accessibilityRole="link"><Text style={styles.link}>{busy ? 'Preparing secure link…' : 'Open study material'}</Text></Pressable></View>) : <Empty text={busy ? 'Loading study materials…' : 'No published materials are available for your enrolled courses.'} />}
        </> : null}
        {active.role === 'student' ? <>
          <Text style={styles.sectionTitle}>My attendance</Text>
          {ownAttendance.length ? ownAttendance.map(record => <View style={styles.recordCard} key={record.id}><Text style={styles.courseTitle}>{record.date} · {record.status}</Text><Text style={styles.courseMeta}>{record.section.class} · {record.section.name} · {record.session.name}</Text>{record.note ? <Text style={styles.courseMeta}>{record.note}</Text> : null}</View>) : <Empty text={busy ? 'Loading attendance…' : 'No attendance records are available.'} />}
          <Text style={styles.sectionTitle}>Assessment results</Text>
          <AssessmentHistory results={ownResults} />
          <Text style={styles.sectionTitle}>My course progress</Text>
          <CourseProgressHistory progress={ownProgress} />
          <Text style={styles.sectionTitle}>Homework</Text>
          <HomeworkHistory items={ownHomework} />
        </> : null}
        {active.role === 'parent' ? <>
          <Text style={styles.sectionTitle}>Linked learners</Text>
          {familyChildren.length ? familyChildren.map(child => <View style={styles.recordCard} key={child.id}><Text style={styles.courseTitle}>{child.name}{child.is_primary ? ' · Primary contact' : ''}</Text><Text style={styles.courseMeta}>{child.relationship ?? 'Guardian link'} · {child.academic_class?.name ?? 'Class not assigned'}{child.academic_section?.name ? ` · ${child.academic_section.name}` : ''}{child.academic_session?.name ? ` · ${child.academic_session.name}` : ''}</Text><Text style={styles.recordHeading}>Recent attendance</Text>{child.attendance.length ? child.attendance.slice(0, 5).map(record => <Text style={styles.courseMeta} key={record.id}>{record.date} · {record.status} · {record.section.class} {record.section.name}</Text>) : <Text style={styles.courseMeta}>{busy ? 'Loading attendance…' : 'No attendance records available.'}</Text>}<Text style={styles.recordHeading}>Assessment results</Text><AssessmentHistory results={child.results} /><Text style={styles.recordHeading}>Course progress</Text><CourseProgressHistory progress={child.progress} /><Text style={styles.recordHeading}>Homework</Text><HomeworkHistory items={child.homework} /></View>) : <Empty text={busy ? 'Loading linked learners…' : 'No active linked learners are available.'} />}
        </> : null}
        {active.role === 'student' || active.role === 'parent' ? <>
          <Text style={styles.sectionTitle}>Fee records</Text>
          {familyFees.length ? familyFees.map(fee => <View style={styles.recordCard} key={fee.id}><Text style={styles.courseTitle}>{fee.title}{fee.student && active.role === 'parent' ? ` · ${fee.student.name}` : ''}</Text><Text style={styles.courseMeta}>Amount ₹{fee.amount} · Paid ₹{fee.paid_amount} · Balance ₹{fee.balance}{fee.due_on ? ` · Due ${fee.due_on}` : ''}</Text><Text style={styles.feeStatus}>{fee.status.toUpperCase()}</Text></View>) : <Empty text={busy ? 'Loading fee records…' : 'No fee records available.'} />}
        </> : null}
        {active.role !== 'parent' ? <>
          <Text style={styles.sectionTitle}>{active.role === 'student' ? 'My courses' : active.role === 'teacher' ? 'My courses' : 'Courses in this workspace'}</Text>
          {courses.length ? courses.map(course => <Pressable style={styles.courseCard} key={course.id} onPress={() => void openCourse(course)}><View style={styles.courseIcon}><Text style={styles.courseIconText}>{course.title.slice(0, 1).toUpperCase()}</Text></View><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{course.title}</Text><Text style={styles.courseMeta}>{course.subject?.name ?? course.level ?? 'Learning course'}{course.teacher?.name ? ` · ${course.teacher.name}` : ''}</Text></View><Text style={styles.chevron}>›</Text></Pressable>) : <Empty text="No courses are available in this organization yet." />}
          {active.role === 'teacher' ? <>
            <Text style={styles.sectionTitle}>My assigned classes</Text>
            {teachingAssignments.length ? teachingAssignments.map(item => <Pressable style={styles.courseCard} key={item.id} onPress={() => void loadAttendance(item)}><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{item.academic_section.academic_class.name} · {item.academic_section.name}</Text><Text style={styles.courseMeta}>{item.subject.name} · {item.academic_session.name}</Text></View><Text style={styles.chevron}>›</Text></Pressable>) : <Empty text="No active teaching assignments are linked to this account." />}
            <Text style={styles.sectionTitle}>School exam papers</Text>
            {teacherExams.length ? teacherExams.map(exam => <View style={styles.recordCard} key={exam.id}>
              <Text style={styles.courseTitle}>{exam.title} · {exam.session}</Text>
              <Text style={styles.courseMeta}>{exam.status === 'published' ? 'Published and read-only' : `${exam.starts_on} – ${exam.ends_on} · Draft marks`}</Text>
              {exam.papers.map(paper => <Pressable style={styles.examPaperButton} key={paper.id} onPress={() => void loadExamPaper(exam, paper)} disabled={busy} accessibilityRole="button" accessibilityLabel={`${exam.status === 'published' ? 'View results' : 'Enter marks'}: ${paper.section}, ${paper.subject}`}>
                <View style={{ flex: 1 }}><Text style={styles.courseTitle}>{paper.section} · {paper.subject}</Text><Text style={styles.courseMeta}>{paper.exam_on} · {paper.results_recorded} results · maximum {paper.max_marks}</Text></View><Text style={styles.link}>{exam.status === 'published' ? 'View' : 'Enter marks'}</Text>
              </Pressable>)}
              {!exam.papers.length ? <Text style={styles.courseMeta}>No assigned papers.</Text> : null}
            </View>) : <Empty text={busy ? 'Loading assigned exam papers…' : 'No school exam papers are assigned to your classes.'} />}
            <Text style={styles.sectionTitle}>Exam series progress</Text>
            <ExamSeriesHistory items={examSeriesComparisons} emptyText="No published exams in a named series are assigned to your subjects." />
            {selectedExamPaper ? <View style={styles.infoCard}>
              <Text style={styles.courseTitle}>{selectedExamPaper.exam.title} · {selectedExamPaper.paper.section} · {selectedExamPaper.paper.subject}</Text>
              <Text style={styles.courseMeta}>{selectedExamPaper.exam.status === 'published' ? 'Published results are read-only.' : `Mark the active roster. Maximum ${selectedExamPaper.paper.max_marks}. Managers publish after every learner has an outcome.`}</Text>
              {examMarks.map(mark => <View style={styles.submissionCard} key={mark.student_id}>
                <Text style={styles.courseTitle}>{mark.name}</Text><Text style={styles.courseMeta}>{mark.admission_number || 'Learner'}</Text>
                {selectedExamPaper.exam.status === 'draft' ? <>
                  <View style={styles.examOutcomeRow}>{examOutcomes.map(outcome => <Pressable key={outcome.value} style={[styles.examOutcome, mark.outcome === outcome.value && styles.examOutcomeSelected]} onPress={() => updateExamMark(mark.student_id, { outcome: outcome.value, score: outcome.value === 'graded' ? mark.score : null, section_scores: outcome.value === 'graded' ? mark.section_scores : {} })} disabled={busy} accessibilityRole="button" accessibilityState={{ selected: mark.outcome === outcome.value }}><Text style={[styles.examOutcomeText, mark.outcome === outcome.value && styles.examOutcomeTextSelected]}>{outcome.label}</Text></Pressable>)}</View>
                  {mark.outcome === 'graded' ? <>
                    {selectedExamPaper.paper.paper_sections.map(section => <View key={section.id}><Text style={styles.inputLabel}>{section.title} · max {section.max_marks}</Text><TextInput style={styles.input} keyboardType="decimal-pad" value={mark.section_scores[String(section.id)] ?? ''} onChangeText={value => updateExamMark(mark.student_id, { section_scores: { ...mark.section_scores, [String(section.id)]: value } })} placeholder="Section score" editable={!busy} accessibilityLabel={`${section.title} score for ${mark.name}`} /></View>)}
                    <Text style={styles.inputLabel}>Total score · max {selectedExamPaper.paper.max_marks}</Text><TextInput style={styles.input} keyboardType="decimal-pad" value={mark.score ?? ''} onChangeText={value => updateExamMark(mark.student_id, { score: value })} placeholder="Enter total marks" editable={!busy} accessibilityLabel={`Total score for ${mark.name}`} />
                  </> : null}
                  <Text style={styles.inputLabel}>Remarks (optional)</Text><TextInput style={styles.input} value={mark.remarks ?? ''} onChangeText={value => updateExamMark(mark.student_id, { remarks: value })} placeholder="Feedback for the learner" editable={!busy} accessibilityLabel={`Remarks for ${mark.name}`} />
                </> : <>
                  <Text style={styles.resultText}>{mark.outcome === 'graded' ? `Score ${mark.score ?? '—'} / ${selectedExamPaper.paper.max_marks}` : mark.outcome ?? 'No result'}</Text>
                  {selectedExamPaper.paper.paper_sections.map(section => <Text style={styles.courseMeta} key={section.id}>{section.title}: {mark.section_scores[String(section.id)] ?? '—'} / {section.max_marks}</Text>)}
                  {mark.remarks ? <Text style={styles.courseMeta}>{mark.remarks}</Text> : null}
                </>}
              </View>)}
              {selectedExamPaper.exam.status === 'draft' ? <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void saveExamMarks()} disabled={busy}><Text style={styles.primaryButtonText}>{busy ? 'Saving marks…' : 'Save exam marks'}</Text></Pressable> : null}
            </View> : null}
          </> : null}
        </> : null}
      </>}
      <Text style={styles.footer}>Connected securely to your organization workspace.</Text>
    </ScrollView>
  </View>
}

function summaryCards(summary: DashboardSummary, role: OrganizationMembership['role']) {
  const rate = (value: unknown) => typeof value === 'number' ? `${value}%` : '—'
  if (role === 'student') return [
    { label: 'Active courses', value: String(summary.active_courses ?? 0) },
    { label: 'Lessons this week', value: String(summary.lessons_completed_week ?? 0) },
    { label: 'Assignments due', value: String(summary.assignments_pending ?? 0) },
    { label: 'Attendance', value: rate((summary.attendance_recent as { rate?: number | null } | undefined)?.rate) },
  ]
  if (role === 'teacher') return [
    { label: 'My classes', value: String(summary.classes ?? 0) },
    { label: 'Students', value: String(summary.students ?? 0) },
    { label: 'To review', value: String(summary.reviews_pending ?? 0) },
    { label: 'Classes this week', value: String(summary.classes_this_week ?? 0) },
  ]
  if (role === 'parent') return [
    { label: 'Linked children', value: String(summary.children ?? 0) },
    { label: 'Assignments due', value: String(summary.assignments_pending ?? 0) },
    { label: 'Upcoming classes', value: String(summary.upcoming_meetings ?? 0) },
    { label: 'Attendance', value: rate((summary.attendance_recent as { rate?: number | null } | undefined)?.rate) },
  ]
  if (role === 'independent_educator') return [
    { label: 'Courses', value: String(summary.courses ?? 0) },
    { label: 'Learners', value: String(summary.learners ?? 0) },
    { label: 'To review', value: String(summary.reviews_pending ?? 0) },
    { label: 'Classes this week', value: String(summary.classes_this_week ?? 0) },
  ]
  return [
    { label: 'Students', value: String(summary.students ?? 0) },
    { label: 'Teachers', value: String(summary.teachers ?? 0) },
    { label: 'Classes today', value: String(summary.classes_today ?? 0) },
    { label: 'Attendance today', value: rate((summary.attendance_today as { rate?: number | null } | undefined)?.rate) },
  ]
}

function ExamSeriesHistory({ items, emptyText }: { items: ExamSeriesComparison[]; emptyText: string }) {
  if (!items.length) return <Empty text={emptyText} />

  return <>{items.slice(0, 5).map(item => {
    const change = item.change_percentage_points
    const comparison = change === null
      ? 'Baseline or no previous graded average'
      : `${change > 0 ? '+' : ''}${change} percentage points${item.previous_exam ? ` vs ${item.previous_exam}` : ''}`

    return <View style={styles.recordCard} key={`${item.exam_id}-${item.class_section}-${item.subject}`}>
      <Text style={styles.courseTitle}>{item.series} · {item.exam}</Text>
      <Text style={styles.courseMeta}>{item.session} · {item.class_section} · {item.subject}</Text>
      <Text style={styles.courseMeta}>{item.average_percent === null ? 'No graded marks' : `${item.average_percent}% normalized average`} · {item.graded_count} graded · {item.absent_count} absent · {item.excused_count} excused</Text>
      <Text style={styles.resultText}>{comparison}</Text>
    </View>
  })}</>
}

function FinanceSnapshot({ report, busy }: { report: MobileFinanceReport | null; busy: boolean }) {
  return <>
    <Text style={styles.sectionTitle}>Finance snapshot · {report?.as_of ?? 'today'}</Text>
    {report ? <><View style={styles.statGrid}>{[
      ['Issued', report.summary.issued_amount], ['Received', report.summary.received_amount],
      ['Outstanding', report.summary.outstanding_amount], ['Overdue', report.summary.overdue_balance],
    ].map(([label, value]) => <View style={styles.statCard} key={label}><Text style={styles.statValue}>₹{Number(value).toLocaleString('en-IN', { maximumFractionDigits: 2 })}</Text><Text style={styles.statLabel}>{label}</Text></View>)}</View><View style={styles.infoCard}><Text style={styles.courseTitle}>Period {report.collections_period.from} to {report.collections_period.to}</Text><Text style={styles.courseMeta}>Net cash flow ₹{Number(report.collections_period.net_cash_flow).toLocaleString('en-IN', { maximumFractionDigits: 2 })} · {report.collections_period.receipt_count} receipts · {report.collections_period.expense_count} expenses</Text><Text style={styles.courseMeta}>All-time expenses ₹{Number(report.summary.expense_amount).toLocaleString('en-IN', { maximumFractionDigits: 2 })} · {report.summary.overdue_records} overdue fee records</Text></View></> : <Empty text={busy ? 'Loading finance summary…' : 'No finance summary is available.'} />}
  </>
}

function Splash() { return <View style={styles.splash}><ActivityIndicator color="#2d63c7" size="large" /><Text style={styles.subtitle}>Connecting to edTechLive…</Text></View> }

function Login({ email, setEmail, password, setPassword, busy, error, success, verificationNeeded, onResend, onSubmit }: { email: string; setEmail: (value: string) => void; password: string; setPassword: (value: string) => void; busy: boolean; error: string; success: string; verificationNeeded: boolean; onResend: () => void; onSubmit: () => void }) {
  return <View style={styles.loginScreen}><StatusBar barStyle="dark-content" backgroundColor="#f4f7fc" /><View style={styles.loginCard}>
    <Text style={styles.brand}>edTechLive</Text><Text style={styles.pageTitle}>Welcome back</Text><Text style={styles.subtitle}>Sign in to your learning workspace.</Text>
    <Text style={styles.inputLabel}>Email address</Text><TextInput autoCapitalize="none" autoComplete="email" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" style={styles.input} editable={!busy} />
    <Text style={styles.inputLabel}>Password</Text><TextInput autoComplete="password" secureTextEntry value={password} onChangeText={setPassword} placeholder="Your password" style={styles.input} editable={!busy} onSubmitEditing={onSubmit} returnKeyType="go" />
    {error ? <Text style={styles.error}>{error}</Text> : null}
    {success ? <Text style={styles.success}>{success}</Text> : null}
    {verificationNeeded ? <Pressable onPress={onResend} disabled={busy} style={styles.resendButton}><Text style={styles.link}>{busy ? 'Requesting link…' : 'Resend verification link'}</Text></Pressable> : null}
    <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={onSubmit} disabled={busy}><Text style={styles.primaryButtonText}>{busy ? 'Signing in…' : 'Sign in'}</Text></Pressable>
    <Text style={styles.footer}>Use the same verified account as the web portal.</Text>
  </View></View>
}

function OrganizationPicker({ user, memberships, busy, error, onChoose, onSignOut }: { user: ApiUser; memberships: OrganizationMembership[]; busy: boolean; error: string; onChoose: (membership: OrganizationMembership) => void; onSignOut: () => void }) {
  return <View style={styles.screen}><StatusBar barStyle="dark-content" backgroundColor="#f4f7fc" /><ScrollView contentContainerStyle={styles.content}><Text style={styles.brand}>edTechLive</Text><Text style={styles.pageTitle}>Choose your workspace</Text><Text style={styles.subtitle}>Signed in as {user.email}</Text>
    {error ? <Text style={styles.error}>{error}</Text> : null}
    {memberships.map(item => <Pressable style={styles.courseCard} key={item.membership_id} onPress={() => onChoose(item)} disabled={busy}><View style={styles.courseIcon}><Text style={styles.courseIconText}>{item.organization.name.slice(0, 1)}</Text></View><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{item.organization.name}</Text><Text style={styles.courseMeta}>{roleNames[item.role]}</Text></View><Text style={styles.chevron}>›</Text></Pressable>)}
    {!memberships.length ? <Empty text="No active organization membership is connected to this account." /> : null}<Pressable onPress={onSignOut}><Text style={styles.link}>Sign out</Text></Pressable>
  </ScrollView></View>
}

function Empty({ text }: { text: string }) { return <View style={styles.empty}><Text style={styles.courseMeta}>{text}</Text></View> }

function StudentRosterSection({ items, total, search, loading, error, page, lastPage, onChangeSearch, onSearch, onLoadMore }: {
  items: MobileRosterStudent[]; total: number; search: string; loading: boolean; error: string; page: number; lastPage: number
  onChangeSearch: (value: string) => void; onSearch: () => void; onLoadMore: () => void
}) {
  return <>
    <Text style={styles.sectionTitle}>Students · {total} {search.trim() ? 'matches' : 'active'}</Text>
    <View style={styles.rosterSearchRow}><TextInput value={search} onChangeText={onChangeSearch} placeholder="Name or admission number" maxLength={100} style={[styles.input, styles.rosterSearchInput]} accessibilityLabel="Search active students" returnKeyType="search" onSubmitEditing={onSearch} /><Pressable style={[styles.rosterSearchButton, loading && styles.disabled]} onPress={onSearch} disabled={loading} accessibilityRole="button"><Text style={styles.primaryButtonText}>Search</Text></Pressable></View>
    {error ? <Text style={styles.error} accessibilityRole="alert">{error}</Text> : null}
    {items.length ? items.map(student => <View style={styles.recordCard} key={student.id}><Text style={styles.courseTitle}>{student.name}</Text><Text style={styles.courseMeta}>{student.admission_number ?? 'Active learner'}{student.academic_class?.name ? ` · ${student.academic_class.name}` : ''}{student.academic_section?.name ? ` · ${student.academic_section.name}` : ''}</Text></View>) : <Empty text={loading ? 'Loading student roster…' : search.trim() ? 'No active students match this search.' : 'No active students are on the roster.'} />}
    {page < lastPage ? <Pressable style={styles.noticeLoadMore} onPress={onLoadMore} disabled={loading} accessibilityRole="button"><Text style={styles.noticeLoadMoreText}>{loading ? 'Loading students…' : 'Load more students'}</Text></Pressable> : null}
  </>
}

function TeacherRosterSection({ items, total, search, loading, error, page, lastPage, onChangeSearch, onSearch, onLoadMore }: {
  items: MobileRosterTeacher[]; total: number; search: string; loading: boolean; error: string; page: number; lastPage: number
  onChangeSearch: (value: string) => void; onSearch: () => void; onLoadMore: () => void
}) {
  return <>
    <Text style={styles.sectionTitle}>Teachers · {total} {search.trim() ? 'matches' : 'active'}</Text>
    <View style={styles.rosterSearchRow}><TextInput value={search} onChangeText={onChangeSearch} placeholder="Name or employee number" maxLength={100} style={[styles.input, styles.rosterSearchInput]} accessibilityLabel="Search active teachers" returnKeyType="search" onSubmitEditing={onSearch} /><Pressable style={[styles.rosterSearchButton, loading && styles.disabled]} onPress={onSearch} disabled={loading} accessibilityRole="button"><Text style={styles.primaryButtonText}>Search</Text></Pressable></View>
    {error ? <Text style={styles.error} accessibilityRole="alert">{error}</Text> : null}
    {items.length ? items.map(teacher => <View style={styles.recordCard} key={teacher.id}><Text style={styles.courseTitle}>{teacher.name}</Text><Text style={styles.courseMeta}>{teacher.employee_number ?? 'Teaching staff'}{teacher.specialization ? ` · ${teacher.specialization}` : ''}</Text></View>) : <Empty text={loading ? 'Loading teacher roster…' : search.trim() ? 'No active teachers match this search.' : 'No active teachers are on the roster.'} />}
    {page < lastPage ? <Pressable style={styles.noticeLoadMore} onPress={onLoadMore} disabled={loading} accessibilityRole="button"><Text style={styles.noticeLoadMoreText}>{loading ? 'Loading teachers…' : 'Load more teachers'}</Text></Pressable> : null}
  </>
}

function AttendanceReportPanel({ report, from, to, loading, error, onChangeFrom, onChangeTo, onApply, onToday, onWeek }: {
  report: MobileAttendanceReport | null
  from: string
  to: string
  loading: boolean
  error: string
  onChangeFrom: (value: string) => void
  onChangeTo: (value: string) => void
  onApply: () => void
  onToday: () => void
  onWeek: () => void
}) {
  return <>
    <Text style={styles.sectionTitle}>Attendance report{report ? ` · ${report.from} to ${report.to}` : ''}</Text>
    <View style={styles.activityCard}>
      <Text style={styles.inputLabel}>From date</Text>
      <TextInput value={from} onChangeText={onChangeFrom} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" maxLength={10} style={styles.input} accessibilityLabel="Attendance report start date" />
      <Text style={styles.inputLabel}>To date</Text>
      <TextInput value={to} onChangeText={onChangeTo} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" maxLength={10} style={styles.input} accessibilityLabel="Attendance report end date" />
      <View style={styles.examOutcomeRow}>
        <Pressable style={styles.examOutcome} onPress={onToday} disabled={loading} accessibilityRole="button"><Text style={styles.examOutcomeText}>Today</Text></Pressable>
        <Pressable style={styles.examOutcome} onPress={onWeek} disabled={loading} accessibilityRole="button"><Text style={styles.examOutcomeText}>Last 7 days</Text></Pressable>
        <Pressable style={[styles.primaryButton, styles.dateRangeApply, loading && styles.disabled]} onPress={onApply} disabled={loading} accessibilityRole="button"><Text style={styles.primaryButtonText}>{loading ? 'Loading…' : 'Apply date range'}</Text></Pressable>
      </View>
      {error ? <Text style={styles.error} accessibilityRole="alert">{error}</Text> : null}
    </View>
    {report ? report.totals.records ? <>
      <View style={styles.infoCard}><Text style={styles.courseTitle}>{report.totals.attendance_rate}% attendance rate</Text><Text style={styles.courseMeta}>{report.totals.records} records · {report.totals.present} present · {report.totals.late} late · {report.totals.absent} absent · {report.totals.excused} excused</Text></View>
      {report.sections.map(section => <View style={styles.recordCard} key={section.section_id}><Text style={styles.courseTitle}>{section.class} · {section.section}</Text><Text style={styles.courseMeta}>{section.attendance_rate}% attendance · {section.absent} absent of {section.records} records</Text></View>)}
    </> : <Empty text={`No attendance was recorded from ${report.from} to ${report.to}.`} /> : <Empty text={loading ? 'Loading attendance report…' : 'Apply a date range to view attendance.'} />}
  </>
}

function AssessmentHistory({ results }: { results: AssessmentResults }) {
  const items = [
    ...results.exams.map(result => ({ id: result.id, title: `${result.exam} · ${result.subject}`, kind: 'School exam', course: `${result.session} · ${result.class_section}`, score: result.score, max_score: result.max_marks, detail: result.outcome === 'graded' ? result.remarks || 'Published result' : result.outcome, completed_at: result.exam_on })),
    ...results.quizzes.map(result => ({ ...result, kind: 'Quiz', detail: result.result_outcome === 'pending_review' ? 'Awaiting teacher review' : result.result_outcome === 'passed' ? `Attempt ${result.attempt_number ?? 1} · Passed` : result.result_outcome === 'failed' ? `Attempt ${result.attempt_number ?? 1} · Not passed` : `Attempt ${result.attempt_number ?? 1}` })),
    ...results.assignments.map(result => ({ ...result, kind: 'Assignment', detail: result.feedback || 'Graded' })),
  ]
  if (!items.length) return <Text style={styles.courseMeta}>No completed or graded assessments yet.</Text>
  return <View>{items.slice(0, 10).map(item => <View key={`${item.kind}-${item.id}`} style={styles.resultRow}><Text style={styles.courseTitle}>{item.title}</Text><Text style={styles.courseMeta}>{item.kind} · {item.course}{item.completed_at ? ` · ${new Date(item.completed_at).toLocaleDateString()}` : ''}</Text><Text style={styles.resultText}>Score: {item.score ?? '—'} / {item.max_score ?? '—'} · {item.detail}</Text></View>)}</View>
}

function CourseProgressHistory({ progress }: { progress: StudentProgress | null }) {
  if (!progress?.data.length) return <Text style={styles.courseMeta}>No active published courses yet.</Text>
  return <View><Text style={styles.courseMeta}>{progress.summary.completed_lessons}/{progress.summary.lessons} lessons complete · {progress.summary.completion_percent}% overall</Text>{progress.data.map(course => <View key={course.course_id} style={styles.progressRow}><View style={styles.progressTrack}><View style={[styles.progressFill, { width: `${course.completion_percent}%` }]} /></View><Text style={styles.courseMeta}>{course.title} · {course.completed_lessons}/{course.lessons} ({course.completion_percent}%)</Text></View>)}</View>
}

function HomeworkHistory({ items }: { items: FamilyHomework[] }) {
  if (!items.length) return <Text style={styles.courseMeta}>No published assignments yet.</Text>
  return <View>{items.slice(0, 5).map(item => <View key={item.id} style={styles.progressRow}><Text style={styles.courseTitle}>{item.title}</Text><Text style={styles.courseMeta}>{item.course}{item.due_at ? ` · Due ${new Date(item.due_at).toLocaleDateString()}` : ''} · {item.status}</Text><Text style={styles.resultText}>{item.score ? `Score ${item.score}/${item.max_points}` : `Maximum ${item.max_points} points`}{item.feedback ? ` · ${item.feedback}` : ''}</Text></View>)}</View>
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#f4f7fc' },
  splash: { flex: 1, backgroundColor: '#f4f7fc', alignItems: 'center', justifyContent: 'center', gap: 14 },
  loginScreen: { flex: 1, justifyContent: 'center', padding: 22, backgroundColor: '#f4f7fc' },
  loginCard: { padding: 22, borderRadius: 20, backgroundColor: '#fff', shadowColor: '#19365b', shadowOpacity: 0.08, shadowRadius: 18, elevation: 3 },
  topBar: { paddingHorizontal: 18, paddingTop: Platform.OS === 'android' ? 46 : 60, paddingBottom: 14, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#fff', borderBottomColor: '#e7edf4', borderBottomWidth: 1 },
  content: { padding: 18, paddingBottom: 36 },
  brand: { color: '#244568', fontSize: 16, fontWeight: '800', letterSpacing: -0.5 },
  orgName: { color: '#75879b', fontSize: 11, marginTop: 3 },
  pageTitle: { color: '#223d5c', fontSize: 26, fontWeight: '800', letterSpacing: -1, marginTop: 18 },
  subtitle: { color: '#8493a3', fontSize: 12, lineHeight: 19, marginTop: 7 },
  eyebrow: { color: '#5677ae', fontSize: 9, fontWeight: '800', letterSpacing: 1.5, marginTop: 4 },
  inputLabel: { color: '#425c77', fontSize: 11, fontWeight: '700', marginTop: 18, marginBottom: 7 },
  input: { height: 48, borderRadius: 9, borderColor: '#dfe7f0', borderWidth: 1, paddingHorizontal: 12, backgroundColor: '#fff', color: '#304d6b', fontSize: 13 },
  primaryButton: { height: 48, borderRadius: 9, backgroundColor: '#2c61c2', alignItems: 'center', justifyContent: 'center', marginTop: 20 },
  dateRangeApply: { flexGrow: 1, minWidth: 150, paddingHorizontal: 12, marginTop: 0 },
  rosterSearchRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  rosterSearchInput: { flex: 1, minWidth: 0 },
  rosterSearchButton: { minHeight: 48, minWidth: 72, paddingHorizontal: 14, borderRadius: 9, backgroundColor: '#2c61c2', alignItems: 'center', justifyContent: 'center' },
  primaryButtonText: { color: '#fff', fontWeight: '700', fontSize: 13 },
  disabled: { opacity: 0.6 },
  error: { marginTop: 13, padding: 10, borderRadius: 8, overflow: 'hidden', color: '#9c4141', backgroundColor: '#fff0f0', fontSize: 11, lineHeight: 17 },
  success: { marginTop: 13, padding: 10, borderRadius: 8, overflow: 'hidden', color: '#28704d', backgroundColor: '#eaf7ef', fontSize: 11, lineHeight: 17 },
  topAction: { paddingVertical: 8, paddingHorizontal: 9, borderRadius: 7, backgroundColor: '#edf3ff' },
  topActionText: { color: '#4269b3', fontWeight: '700', fontSize: 9 },
  statGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9, marginTop: 20 },
  statCard: { width: '48%', minHeight: 88, padding: 13, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  statValue: { fontSize: 22, fontWeight: '800', color: '#2e5177' },
  statLabel: { fontSize: 9, lineHeight: 14, marginTop: 5, color: '#8190a0' },
  sectionTitle: { color: '#334f6f', fontSize: 14, fontWeight: '800', marginTop: 24, marginBottom: 10 },
  courseCard: { padding: 12, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3', flexDirection: 'row', alignItems: 'center', gap: 11, marginBottom: 8 },
  courseIcon: { width: 36, height: 36, borderRadius: 10, backgroundColor: '#eaf0ff', alignItems: 'center', justifyContent: 'center' },
  courseIconText: { color: '#4169b5', fontWeight: '800' },
  courseTitle: { color: '#405b77', fontSize: 11, fontWeight: '700' },
  courseMeta: { color: '#8795a5', fontSize: 9, lineHeight: 15, marginTop: 4 },
  examPaperButton: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 9, borderTopWidth: 1, borderTopColor: '#edf1f5' },
  examOutcomeRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginTop: 5 },
  meetingSectionList: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  examOutcome: { paddingHorizontal: 9, paddingVertical: 7, borderRadius: 8, borderWidth: 1, borderColor: '#e2eaf2', backgroundColor: '#fff' },
  examOutcomeSelected: { borderColor: '#3568c7', backgroundColor: '#edf3ff' },
  examOutcomeText: { color: '#65788d', fontSize: 9, fontWeight: '700' },
  examOutcomeTextSelected: { color: '#315ca8' },
  chevron: { color: '#99a7b4', fontSize: 23 },
  lessonCard: { padding: 12, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3', flexDirection: 'row', gap: 10, marginBottom: 8 },
  activityCard: { gap: 9, padding: 13, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3', marginBottom: 9 },
  submissionCard: { gap: 8, padding: 11, marginTop: 7, borderRadius: 9, backgroundColor: '#f8faff' },
  answerText: { color: '#536a82', fontSize: 10, lineHeight: 16 },
  questionBlock: { gap: 7, marginTop: 9 },
  option: { padding: 11, borderRadius: 9, borderWidth: 1, borderColor: '#e2eaf2', backgroundColor: '#fff' },
  optionSelected: { borderColor: '#3869c8', backgroundColor: '#edf3ff' },
  answerInput: { minHeight: 100, padding: 12, borderRadius: 9, borderWidth: 1, borderColor: '#e2eaf2', textAlignVertical: 'top', color: '#2b4158' },
  resultText: { color: '#3869c8', fontSize: 11, fontWeight: '700' },
  resultRow: { gap: 4, paddingVertical: 8, borderTopWidth: 1, borderTopColor: '#edf1f5' },
  progressRow: { gap: 4, paddingVertical: 6 },
  progressTrack: { height: 6, borderRadius: 99, backgroundColor: '#e9eef5', overflow: 'hidden' },
  progressFill: { height: '100%', borderRadius: 99, backgroundColor: '#3f7ccf' },
  studentRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, padding: 11, marginBottom: 7, borderRadius: 10, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  attendanceReasonInput: { minHeight: 38, marginTop: 7, paddingHorizontal: 9, borderWidth: 1, borderColor: '#dce4ed', borderRadius: 7, color: '#425b75', fontSize: 11 },
  statusRow: { flexDirection: 'row', gap: 4 },
  statusChip: { width: 28, height: 28, borderRadius: 8, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f1f4f8' },
  statusChipSelected: { backgroundColor: '#3568c7' },
  statusChipText: { color: '#65788d', fontSize: 10, fontWeight: '700' },
  statusChipTextSelected: { color: '#fff' },
  lessonNumber: { color: '#7e96bf', fontSize: 10, fontWeight: '800' },
  infoCard: { marginTop: 18, padding: 14, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  noticeCard: { padding: 12, marginBottom: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  noticeUnread: { borderLeftColor: '#3869c8', borderLeftWidth: 3 },
  noticeDate: { color: '#9aa7b5', fontSize: 8, marginTop: 6 },
  noticeUnreadLabel: { color: '#3768bb', fontSize: 9, fontWeight: '700', marginTop: 7 },
  noticeLoadMore: { minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 9, backgroundColor: '#edf3ff', marginBottom: 8 },
  noticeLoadMoreText: { color: '#315ca8', fontSize: 11, fontWeight: '700' },
  recordCard: { gap: 5, padding: 12, marginBottom: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  recordHeading: { color: '#526d8d', fontSize: 9, fontWeight: '800', marginTop: 8 },
  feeStatus: { alignSelf: 'flex-start', color: '#5270a3', backgroundColor: '#edf3ff', borderRadius: 6, overflow: 'hidden', paddingVertical: 4, paddingHorizontal: 6, fontSize: 8, fontWeight: '800', marginTop: 4 },
  empty: { padding: 15, marginTop: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  backLink: { color: '#446eb7', fontSize: 11, fontWeight: '700', marginTop: 7 },
  link: { color: '#3c6fc2', fontWeight: '700', fontSize: 10, marginTop: 9 },
  resendButton: { alignSelf: 'flex-start', marginTop: 4, paddingVertical: 4 },
  footer: { color: '#a0acb9', fontSize: 8, lineHeight: 14, marginTop: 20, textAlign: 'center' },
})
