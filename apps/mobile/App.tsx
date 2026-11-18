import { useEffect, useState } from 'react'
import { ActivityIndicator, Linking, Platform, Pressable, ScrollView, StatusBar, StyleSheet, Text, TextInput, View } from 'react-native'
import * as SecureStore from 'expo-secure-store'
import { ApiError, mobileApi, type ApiUser, type Course, type CourseAssignment, type CourseQuiz, type DashboardSummary, type Lesson, type OrganizationMembership, type QuizRun } from './src/api'

const TOKEN_KEY = 'edtechlive.mobile.token'
const ORGANIZATION_KEY = 'edtechlive.mobile.organization'
const roleNames: Record<OrganizationMembership['role'], string> = {
  admin: 'Administrator', management: 'Management', teacher: 'Teacher', student: 'Student', parent: 'Parent / guardian', independent_educator: 'Independent educator',
}
type MobileNotice = { id: string; type: string; title: string; body: string; read_at: string | null; created_at: string | null }

export default function App() {
  const [token, setToken] = useState('')
  const [user, setUser] = useState<ApiUser | null>(null)
  const [memberships, setMemberships] = useState<OrganizationMembership[]>([])
  const [active, setActive] = useState<OrganizationMembership | null>(null)
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [notices, setNotices] = useState<MobileNotice[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [selectedCourse, setSelectedCourse] = useState<Course | null>(null)
  const [lessons, setLessons] = useState<Lesson[]>([])
  const [assignments, setAssignments] = useState<CourseAssignment[]>([])
  const [quizzes, setQuizzes] = useState<CourseQuiz[]>([])
  const [quizRun, setQuizRun] = useState<QuizRun | null>(null)
  const [quizAnswers, setQuizAnswers] = useState<Record<number, number>>({})
  const [assignmentDrafts, setAssignmentDrafts] = useState<Record<number, string>>({})
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    async function restore() {
      try {
        const saved = await SecureStore.getItemAsync(TOKEN_KEY)
        if (!saved) return
        const me = await mobileApi.get<{ data: ApiUser }>('/api/v1/me', saved)
        const organizationList = await mobileApi.get<{ data: OrganizationMembership[] }>('/api/v1/organizations?per_page=100', saved)
        if (cancelled) return
        setToken(saved); setUser(me.data); setMemberships(organizationList.data)
        const remembered = await SecureStore.getItemAsync(ORGANIZATION_KEY)
        const selection = organizationList.data.find(item => String(item.organization.id) === remembered)
          ?? (organizationList.data.length === 1 ? organizationList.data[0] : null)
        if (selection) setActive(selection)
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
    setSummary(null); setNotices([]); setCourses([]); setLessons([]); setAssignments([]); setQuizzes([]); setQuizRun(null)
    const base = `/api/v1/organizations/${active.organization.id}`
    const tasks: Promise<void>[] = [mobileApi.get<{ data: DashboardSummary }>(`${base}/dashboard/summary`, token)
      .then(result => { if (!cancelled) setSummary(result.data) }),
    mobileApi.get<{ data: MobileNotice[] }>(`${base}/notifications?per_page=10`, token)
      .then(result => { if (!cancelled) setNotices(result.data) })]
    if (active.role !== 'parent') {
      tasks.push(mobileApi.get<{ data: Course[] }>(`${base}/courses?per_page=100`, token)
        .then(result => { if (!cancelled) setCourses(result.data) }))
    }
    Promise.all(tasks).catch(reason => {
      if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load your learning workspace.')
    }).finally(() => { if (!cancelled) setBusy(false) })
    return () => { cancelled = true }
  }, [active, token])

  async function signIn() {
    setBusy(true); setError('')
    try {
      const result = await mobileApi.login(email.trim(), password)
      const organizationList = await mobileApi.get<{ data: OrganizationMembership[] }>('/api/v1/organizations?per_page=100', result.token)
      await SecureStore.setItemAsync(TOKEN_KEY, result.token)
      setToken(result.token); setUser(result.data); setMemberships(organizationList.data)
      if (organizationList.data.length === 1) await chooseOrganization(organizationList.data[0], result.token)
      setPassword('')
    } catch (reason) {
      if (reason instanceof ApiError && reason.code === 'email_verification_required') {
        setError('Verify your email before signing in. Request a new link from the web sign-in page.')
      } else setError(reason instanceof Error ? reason.message : 'Could not sign in.')
    } finally { setBusy(false) }
  }

  async function chooseOrganization(membership: OrganizationMembership, currentToken = token) {
    await SecureStore.setItemAsync(ORGANIZATION_KEY, String(membership.organization.id))
    setActive(membership); setError('')
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
      setSelectedCourse(course); setLessons(lessonResult.data); setAssignments(assignmentResult.data); setQuizzes(quizResult.data); setQuizRun(null)
      setAssignmentDrafts(Object.fromEntries(assignmentResult.data.map(item => [item.id, item.submission?.answer ?? ''])))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not open this course.') }
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
      const answers = Object.entries(quizAnswers).map(([questionId, selectedOption]) => ({ question_id: Number(questionId), selected_option: selectedOption }))
      const result = await mobileApi.patch<{ data: QuizRun['attempt'] }>(`/api/v1/organizations/${active.organization.id}/quizzes/${quizRun.quiz.id}/attempts/${quizRun.attempt.id}`, token, { answers })
      setQuizzes(items => items.map(item => item.id === quizRun.quiz.id ? { ...item, attempts_used: (item.attempts_used ?? 0) + 1, latest_result: result.data } : item))
      setQuizRun(null)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit this quiz.') }
    finally { setBusy(false) }
  }

  async function markNoticeRead(notice: MobileNotice) {
    if (!active || !token || notice.read_at) return
    try {
      await mobileApi.post(`/api/v1/organizations/${active.organization.id}/notifications/${notice.id}/read`, token)
      setNotices(items => items.map(item => item.id === notice.id ? { ...item, read_at: new Date().toISOString() } : item))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update this notification.') }
  }

  async function signOut() {
    const current = token
    setBusy(true)
    try { if (current) await mobileApi.post('/api/v1/auth/logout', current) }
    catch { /* Local credentials must still be removed if the API is offline. */ }
    await SecureStore.deleteItemAsync(TOKEN_KEY); await SecureStore.deleteItemAsync(ORGANIZATION_KEY)
    setToken(''); setUser(null); setMemberships([]); setActive(null); setSummary(null); setCourses([]); setSelectedCourse(null); setLessons([]); setBusy(false)
  }

  if (busy && !user) return <Splash />
  if (!user) return <Login email={email} setEmail={setEmail} password={password} setPassword={setPassword} busy={busy} error={error} onSubmit={() => void signIn()} />
  if (!active) return <OrganizationPicker user={user} memberships={memberships} busy={busy} error={error} onChoose={membership => void chooseOrganization(membership)} onSignOut={() => void signOut()} />

  return <View style={styles.screen}><StatusBar barStyle="dark-content" backgroundColor="#f4f7fc" />
    <View style={styles.topBar}><View style={{ flex: 1 }}><Text style={styles.brand}>edTechLive</Text><Text style={styles.orgName}>{active.organization.name}</Text></View><Pressable onPress={() => { setActive(null); setSelectedCourse(null) }} style={styles.topAction}><Text style={styles.topActionText}>Switch</Text></Pressable><Pressable onPress={() => void signOut()} style={styles.topAction}><Text style={styles.topActionText}>Sign out</Text></Pressable></View>
    <ScrollView contentContainerStyle={styles.content}>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {selectedCourse ? <>
        <Pressable onPress={() => setSelectedCourse(null)}><Text style={styles.backLink}>‹  All courses</Text></Pressable>
        <Text style={styles.pageTitle}>{selectedCourse.title}</Text><Text style={styles.subtitle}>{selectedCourse.subject?.name ?? 'Course'}{selectedCourse.teacher?.name ? ` · ${selectedCourse.teacher.name}` : ''}</Text>
        {quizRun ? <View style={styles.activityCard}><Pressable onPress={() => setQuizRun(null)}><Text style={styles.backLink}>‹  Course content</Text></Pressable><Text style={styles.sectionTitle}>{quizRun.quiz.title}</Text>{quizRun.quiz.instructions ? <Text style={styles.courseMeta}>{quizRun.quiz.instructions}</Text> : null}{quizRun.questions.map((question, index) => <View key={question.id} style={styles.questionBlock}><Text style={styles.courseTitle}>{index + 1}. {question.prompt}</Text>{question.options.map((option, optionIndex) => <Pressable key={optionIndex} onPress={() => setQuizAnswers(current => ({ ...current, [question.id]: optionIndex }))} style={[styles.option, quizAnswers[question.id] === optionIndex && styles.optionSelected]}><Text style={styles.courseMeta}>{String.fromCharCode(65 + optionIndex)}. {option}</Text></Pressable>)}</View>)}<Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void submitQuiz()} disabled={busy}><Text style={styles.primaryButtonText}>{busy ? 'Submitting…' : 'Submit quiz'}</Text></Pressable></View> : <>
        <Text style={styles.sectionTitle}>Lessons</Text>
        {lessons.length ? lessons.filter(lesson => lesson.status === 'published').map((lesson, index) => <View style={styles.lessonCard} key={lesson.id}><Text style={styles.lessonNumber}>{String(index + 1).padStart(2, '0')}</Text><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{lesson.title}</Text>{lesson.content ? <Text style={styles.courseMeta} numberOfLines={3}>{lesson.content}</Text> : null}{lesson.external_url ? <Pressable onPress={() => void Linking.openURL(lesson.external_url!)}><Text style={styles.link}>Open lesson resource</Text></Pressable> : null}</View></View>) : <Empty text="No published lessons are available in this course yet." />}
        <Text style={styles.sectionTitle}>Assignments</Text>
        {assignments.length ? assignments.map(item => <View style={styles.activityCard} key={item.id}><Text style={styles.courseTitle}>{item.title}</Text><Text style={styles.courseMeta}>{item.instructions || 'Written response'} · Up to {item.max_points} points{item.due_at ? ` · Due ${new Date(item.due_at).toLocaleDateString()}` : ''}</Text>{item.submission?.graded_at ? <Text style={styles.resultText}>Graded: {item.submission.awarded_points}/{item.max_points}{item.submission.feedback ? ` · ${item.submission.feedback}` : ''}</Text> : null}{active.role === 'student' ? <><TextInput multiline value={assignmentDrafts[item.id] ?? ''} onChangeText={value => setAssignmentDrafts(current => ({ ...current, [item.id]: value }))} placeholder="Write your response" style={styles.answerInput} editable={!busy && !item.submission?.graded_at} /><Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void submitAssignment(item)} disabled={busy || !assignmentDrafts[item.id]?.trim() || Boolean(item.submission?.graded_at)}><Text style={styles.primaryButtonText}>{item.submission ? 'Update submission' : 'Submit assignment'}</Text></Pressable></> : null}</View>) : <Empty text="No published assignments in this course." />}
        <Text style={styles.sectionTitle}>Quizzes</Text>
        {quizzes.length ? quizzes.map(quiz => <View style={styles.activityCard} key={quiz.id}><Text style={styles.courseTitle}>{quiz.title}</Text><Text style={styles.courseMeta}>{quiz.questions.length} questions · {quiz.time_limit_minutes ? `${quiz.time_limit_minutes} minutes` : 'No time limit'} · {quiz.attempts_used ?? 0}/{quiz.max_attempts} attempts</Text>{quiz.latest_result?.submitted_at ? <Text style={styles.resultText}>Latest result: {quiz.latest_result.score}/{quiz.latest_result.max_score}</Text> : null}{active.role === 'student' && (quiz.attempts_used ?? 0) < quiz.max_attempts ? <Pressable style={[styles.primaryButton, busy && styles.disabled]} onPress={() => void startQuiz(quiz)} disabled={busy}><Text style={styles.primaryButtonText}>Start quiz</Text></Pressable> : null}</View>) : <Empty text="No published quizzes in this course." />}
        </>}
      </> : <>
        <Text style={styles.eyebrow}>YOUR LEARNING SPACE</Text><Text style={styles.pageTitle}>Welcome, {user.name.split(' ')[0]}</Text><Text style={styles.subtitle}>{roleNames[active.role]} · {active.organization.type.replaceAll('_', ' ')}</Text>
        {summary ? <View style={styles.statGrid}>{summaryCards(summary, active.role).map(item => <View style={styles.statCard} key={item.label}><Text style={styles.statValue}>{item.value}</Text><Text style={styles.statLabel}>{item.label}</Text></View>)}</View> : null}
        <Text style={styles.sectionTitle}>Recent updates</Text>
        {notices.length ? notices.map(notice => <Pressable key={notice.id} onPress={() => void markNoticeRead(notice)} style={[styles.noticeCard, !notice.read_at && styles.noticeUnread]}><Text style={styles.courseTitle}>{notice.title}</Text><Text style={styles.courseMeta}>{notice.body}</Text>{notice.created_at ? <Text style={styles.noticeDate}>{new Date(notice.created_at).toLocaleString()}</Text> : null}</Pressable>) : <Empty text="No recent updates." />}
        {active.role === 'parent' ? <View style={styles.infoCard}><Text style={styles.courseTitle}>Family updates</Text><Text style={styles.courseMeta}>Updates above are restricted to your linked family workspace.</Text></View> : <>
          <Text style={styles.sectionTitle}>{active.role === 'student' ? 'My courses' : active.role === 'teacher' ? 'My courses' : 'Courses in this workspace'}</Text>
          {courses.length ? courses.map(course => <Pressable style={styles.courseCard} key={course.id} onPress={() => void openCourse(course)}><View style={styles.courseIcon}><Text style={styles.courseIconText}>{course.title.slice(0, 1).toUpperCase()}</Text></View><View style={{ flex: 1 }}><Text style={styles.courseTitle}>{course.title}</Text><Text style={styles.courseMeta}>{course.subject?.name ?? course.level ?? 'Learning course'}{course.teacher?.name ? ` · ${course.teacher.name}` : ''}</Text></View><Text style={styles.chevron}>›</Text></Pressable>) : <Empty text="No courses are available in this organization yet." />}
        </>}
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

function Splash() { return <View style={styles.splash}><ActivityIndicator color="#2d63c7" size="large" /><Text style={styles.subtitle}>Connecting to edTechLive…</Text></View> }

function Login({ email, setEmail, password, setPassword, busy, error, onSubmit }: { email: string; setEmail: (value: string) => void; password: string; setPassword: (value: string) => void; busy: boolean; error: string; onSubmit: () => void }) {
  return <View style={styles.loginScreen}><StatusBar barStyle="dark-content" backgroundColor="#f4f7fc" /><View style={styles.loginCard}>
    <Text style={styles.brand}>edTechLive</Text><Text style={styles.pageTitle}>Welcome back</Text><Text style={styles.subtitle}>Sign in to your learning workspace.</Text>
    <Text style={styles.inputLabel}>Email address</Text><TextInput autoCapitalize="none" autoComplete="email" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" style={styles.input} editable={!busy} />
    <Text style={styles.inputLabel}>Password</Text><TextInput autoComplete="password" secureTextEntry value={password} onChangeText={setPassword} placeholder="Your password" style={styles.input} editable={!busy} onSubmitEditing={onSubmit} returnKeyType="go" />
    {error ? <Text style={styles.error}>{error}</Text> : null}
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
  primaryButtonText: { color: '#fff', fontWeight: '700', fontSize: 13 },
  disabled: { opacity: 0.6 },
  error: { marginTop: 13, padding: 10, borderRadius: 8, overflow: 'hidden', color: '#9c4141', backgroundColor: '#fff0f0', fontSize: 11, lineHeight: 17 },
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
  chevron: { color: '#99a7b4', fontSize: 23 },
  lessonCard: { padding: 12, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3', flexDirection: 'row', gap: 10, marginBottom: 8 },
  activityCard: { gap: 9, padding: 13, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3', marginBottom: 9 },
  questionBlock: { gap: 7, marginTop: 9 },
  option: { padding: 11, borderRadius: 9, borderWidth: 1, borderColor: '#e2eaf2', backgroundColor: '#fff' },
  optionSelected: { borderColor: '#3869c8', backgroundColor: '#edf3ff' },
  answerInput: { minHeight: 100, padding: 12, borderRadius: 9, borderWidth: 1, borderColor: '#e2eaf2', textAlignVertical: 'top', color: '#2b4158' },
  resultText: { color: '#3869c8', fontSize: 11, fontWeight: '700' },
  lessonNumber: { color: '#7e96bf', fontSize: 10, fontWeight: '800' },
  infoCard: { marginTop: 18, padding: 14, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  noticeCard: { padding: 12, marginBottom: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  noticeUnread: { borderLeftColor: '#3869c8', borderLeftWidth: 3 },
  noticeDate: { color: '#9aa7b5', fontSize: 8, marginTop: 6 },
  empty: { padding: 15, marginTop: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  backLink: { color: '#446eb7', fontSize: 11, fontWeight: '700', marginTop: 7 },
  link: { color: '#3c6fc2', fontWeight: '700', fontSize: 10, marginTop: 9 },
  footer: { color: '#a0acb9', fontSize: 8, lineHeight: 14, marginTop: 20, textAlign: 'center' },
})
