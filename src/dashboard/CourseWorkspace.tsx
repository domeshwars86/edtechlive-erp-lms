import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, BookOpen, Check, CheckCircle2, LoaderCircle, Plus, Save, Sparkles } from 'lucide-react'
import { ApiError, api } from '../lib/api'
import { useAuth } from '../auth/useAuth'

type Course = { id: number; title: string; slug: string | null; level: string | null; description: string | null; status: 'draft' | 'published' | 'archived'; is_public_listing: boolean; subject: { id: number; name: string } | null; teacher: { id: number; name: string } | null }
type CourseModule = { id: number; title: string; description: string | null; sort_order: number }
type Lesson = { id: number; title: string; sort_order: number; content: string | null; external_url: string | null; status: 'draft' | 'published'; module: CourseModule | null }
type Student = { id: number; name: string; admission_number: string }
type CourseAsset = { id: number; title: string; filename: string; mime_type: string; size_bytes: number; is_published: boolean; download_url: string }
type CourseProgressSummary = { completed: number; total: number; percent: number }
type StudentProgress = { data: { lesson_id: number; completed_at: string | null }[]; summary: CourseProgressSummary }
type LearnerProgress = { student: Student; completed: number; total: number; percent: number }
type LearnerProgressReport = { data: LearnerProgress[]; summary: { learners: number; average_percent: number; lessons: number } }
type AiConfiguration = { enabled: boolean; model: string | null; daily_limit: number; usage: { requests_30_days: number; prompt_tokens_30_days: number; completion_tokens_30_days: number; requests_today: number } }

export default function CourseWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'student' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const { activeMembership } = useAuth()
  const [courses, setCourses] = useState<Course[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [modules, setModules] = useState<CourseModule[]>([])
  const [lessons, setLessons] = useState<Lesson[]>([])
  const [lessonProgress, setLessonProgress] = useState<Record<number, string | null>>({})
  const [progressSummary, setProgressSummary] = useState<CourseProgressSummary>({ completed: 0, total: 0, percent: 0 })
  const [learnerProgress, setLearnerProgress] = useState<LearnerProgress[]>([])
  const [assets, setAssets] = useState<CourseAsset[]>([])
  const [students, setStudents] = useState<Student[]>([])
  const [selectedStudents, setSelectedStudents] = useState<number[]>([])
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [lessonTitle, setLessonTitle] = useState('')
  const [lessonContent, setLessonContent] = useState('')
  const [lessonUrl, setLessonUrl] = useState('')
  const [moduleTitle, setModuleTitle] = useState('')
  const [moduleDescription, setModuleDescription] = useState('')
  const [selectedModuleId, setSelectedModuleId] = useState('')
  const [assetTitle, setAssetTitle] = useState('')
  const [assetFile, setAssetFile] = useState<File | null>(null)
  const assetInput = useRef<HTMLInputElement>(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [aiConfiguration, setAiConfiguration] = useState<AiConfiguration | null>(null)
  const [aiTopic, setAiTopic] = useState('')
  const [aiGradeLevel, setAiGradeLevel] = useState('')
  const [aiObjectives, setAiObjectives] = useState('')
  const [aiLanguage, setAiLanguage] = useState<'English' | 'Hindi'>('English')
  const [aiDraftType, setAiDraftType] = useState<'lesson_plan' | 'questions'>('lesson_plan')
  const [aiQuestionCount, setAiQuestionCount] = useState(5)
  const [aiDifficulty, setAiDifficulty] = useState<'easy' | 'medium' | 'hard'>('medium')
  const [aiDraft, setAiDraft] = useState('')
  const [aiGenerating, setAiGenerating] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.get<{ data: Course[] }>(`${base}/courses?per_page=100`)
      .then(result => { if (!cancelled) { setCourses(result.data); setSelectedId(result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load courses.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    if (mode === 'manage') api.get<{ data: Student[] }>(`${base}/students?status=active&per_page=100`)
      .then(result => { if (!cancelled) setStudents(result.data) })
      .catch(() => { if (!cancelled) setStudents([]) })
    if (mode === 'manage') api.get<AiConfiguration>(`${base}/ai/config`)
      .then(result => { if (!cancelled) setAiConfiguration(result) })
      .catch(() => { if (!cancelled) setAiConfiguration(null) })
    return () => { cancelled = true }
  }, [base, mode])

  const selectedCourse = courses.find(course => course.id === selectedId) ?? null
  useEffect(() => { setAiDraft('') }, [selectedId])
  const publicCoursePath = selectedCourse?.slug && activeMembership?.organization.slug
    ? `/courses/${encodeURIComponent(activeMembership.organization.slug)}/${encodeURIComponent(selectedCourse.slug)}`
    : null
  useEffect(() => {
    let cancelled = false
    if (!selectedCourse) { setModules([]); setLessons([]); setAssets([]); setLessonProgress({}); setProgressSummary({ completed: 0, total: 0, percent: 0 }); setLearnerProgress([]); return }
    setSelectedModuleId('')
    Promise.all([
      api.get<{ data: Lesson[] }>(`${base}/courses/${selectedCourse.id}/lessons`),
      api.get<{ data: CourseAsset[] }>(`${base}/courses/${selectedCourse.id}/assets`),
      mode === 'student'
        ? api.get<StudentProgress>(`${base}/courses/${selectedCourse.id}/progress`)
        : selectedCourse.status === 'published'
          ? api.get<LearnerProgressReport>(`${base}/courses/${selectedCourse.id}/progress`)
          : Promise.resolve(null),
      mode === 'manage'
        ? api.get<{ data: CourseModule[] }>(`${base}/courses/${selectedCourse.id}/modules`)
        : Promise.resolve({ data: [] as CourseModule[] }),
    ]).then(([lessonResult, assetResult, progressResult, moduleResult]) => {
      if (!cancelled) {
        setLessons(lessonResult.data); setAssets(assetResult.data)
        const lessonModules = lessonResult.data.flatMap(lesson => lesson.module ? [lesson.module] : [])
        const visibleModules = mode === 'manage' ? moduleResult.data : lessonModules
        setModules(Array.from(new Map(visibleModules.map(module => [module.id, module])).values()).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id))
        if (mode === 'student') {
          const studentResult = progressResult as StudentProgress | null
          setLessonProgress(Object.fromEntries(studentResult?.data.map(item => [item.lesson_id, item.completed_at]) ?? []))
          setProgressSummary(studentResult?.summary ?? { completed: 0, total: 0, percent: 0 })
          setLearnerProgress([])
        } else {
          setLearnerProgress((progressResult as LearnerProgressReport | null)?.data ?? [])
          setLessonProgress({}); setProgressSummary({ completed: 0, total: 0, percent: 0 })
        }
      }
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load course learning materials.') })
    return () => { cancelled = true }
  }, [base, mode, selectedCourse])

  async function toggleLessonProgress(lesson: Lesson) {
    if (!selectedCourse) return
    const completed = !lessonProgress[lesson.id]
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.put<{ data: { lesson_id: number; completed_at: string | null } }>(`${base}/courses/${selectedCourse.id}/lessons/${lesson.id}/progress`, { completed })
      const nextProgress = { ...lessonProgress, [lesson.id]: result.data.completed_at }
      const completedCount = Object.values(nextProgress).filter(Boolean).length
      setLessonProgress(nextProgress)
      setProgressSummary({ completed: completedCount, total: lessons.length, percent: lessons.length ? Math.round(completedCount / lessons.length * 100) : 0 })
      setMessage(completed ? 'Lesson marked complete.' : 'Lesson marked incomplete.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update lesson progress.') } finally { setSaving(false) }
  }

  async function createCourse(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: Course }>(`${base}/courses`, { title, description: description || null })
      setCourses(current => [result.data, ...current]); setSelectedId(result.data.id); setTitle(''); setDescription(''); setMessage('Course created as a draft.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create course.') } finally { setSaving(false) }
  }

  async function createLesson(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedCourse) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: Lesson }>(`${base}/courses/${selectedCourse.id}/lessons`, { title: lessonTitle, module_id: selectedModuleId ? Number(selectedModuleId) : null, content: lessonContent || null, external_url: lessonUrl || null, status: 'published', sort_order: lessons.length + 1 })
      setLessons(current => [...current, result.data]); setLessonTitle(''); setLessonContent(''); setLessonUrl(''); setMessage('Lesson published.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save lesson.') } finally { setSaving(false) }
  }

  async function createModule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedCourse) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: CourseModule }>(`${base}/courses/${selectedCourse.id}/modules`, { title: moduleTitle, description: moduleDescription || null, sort_order: modules.length + 1 })
      setModules(current => [...current, result.data].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id))
      setSelectedModuleId(String(result.data.id)); setModuleTitle(''); setModuleDescription(''); setMessage('Course module created.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create course module.') } finally { setSaving(false) }
  }

  async function updateModule(event: FormEvent<HTMLFormElement>, module: CourseModule) {
    event.preventDefault(); if (!selectedCourse) return
    const values = new FormData(event.currentTarget)
    const title = String(values.get('title') ?? '').trim()
    const description = String(values.get('description') ?? '').trim() || null
    const sortOrder = Number(values.get('sort_order'))
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: CourseModule }>(`${base}/courses/${selectedCourse.id}/modules/${module.id}`, { title, description, sort_order: sortOrder })
      setModules(current => current.map(item => item.id === module.id ? result.data : item).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id))
      setLessons(current => current.map(lesson => lesson.module?.id === module.id ? { ...lesson, module: result.data } : lesson))
      setMessage('Course module updated.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update course module.') } finally { setSaving(false) }
  }

  function renderLesson(lesson: Lesson) {
    return <section key={lesson.id} className="course-lesson"><div className="course-lesson-title"><b>{lesson.sort_order}. {lesson.title}</b>{mode === 'student' && <button type="button" className="master-action" onClick={() => void toggleLessonProgress(lesson)} disabled={saving} aria-pressed={Boolean(lessonProgress[lesson.id])}>{lessonProgress[lesson.id] ? <><CheckCircle2 size={15} /> Completed</> : 'Mark complete'}</button>}</div>{lesson.content && <p>{lesson.content}</p>}{lesson.external_url && <a href={lesson.external_url} target="_blank" rel="noreferrer">Open learning resource</a>}</section>
  }

  async function publishCourse() {
    if (!selectedCourse) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: Course }>(`${base}/courses/${selectedCourse.id}`, { status: 'published' })
      setCourses(current => current.map(course => course.id === result.data.id ? result.data : course)); setMessage('Course published for enrolled students.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish course.') } finally { setSaving(false) }
  }

  async function setPublicListing(isPublicListing: boolean) {
    if (!selectedCourse || selectedCourse.status !== 'published') return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: Course }>(`${base}/courses/${selectedCourse.id}`, { is_public_listing: isPublicListing })
      setCourses(current => current.map(course => course.id === result.data.id ? result.data : course))
      setMessage(isPublicListing ? 'Public course listing enabled.' : 'Public course listing disabled.')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update the public course listing.')
    } finally { setSaving(false) }
  }

  async function copyPublicCourseLink() {
    if (!publicCoursePath) return
    setError(''); setMessage('')
    try {
      await navigator.clipboard.writeText(new URL(publicCoursePath, window.location.origin).toString())
      setMessage('Public course link copied.')
    } catch {
      setError('Could not copy the link. Open the listing and copy its address.')
    }
  }

  async function generateLessonPlan(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedCourse) return
    setAiGenerating(true); setError(''); setMessage('')
    try {
      const questions = aiDraftType === 'questions'
      const result = await api.post<{ data: { draft: string; model: string; usage: { prompt_tokens: number; completion_tokens: number }; review_required: true } }>(`${base}/ai/courses/${selectedCourse.id}/${questions ? 'question-drafts' : 'lesson-plan'}`, {
        topic: aiTopic, grade_level: aiGradeLevel || null, objectives: aiObjectives, language: aiLanguage,
        ...(questions ? { question_count: aiQuestionCount, difficulty: aiDifficulty } : {}),
      })
      setAiDraft(result.data.draft)
      setAiConfiguration(current => current ? { ...current, usage: { ...current.usage, requests_today: current.usage.requests_today + 1, requests_30_days: current.usage.requests_30_days + 1, prompt_tokens_30_days: current.usage.prompt_tokens_30_days + result.data.usage.prompt_tokens, completion_tokens_30_days: current.usage.completion_tokens_30_days + result.data.usage.completion_tokens } } : current)
      setMessage(`Draft generated with ${result.data.model}. Review and edit it before using.`)
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 503) {
        setAiConfiguration(current => current ? { ...current, usage: { ...current.usage, requests_today: current.usage.requests_today + 1, requests_30_days: current.usage.requests_30_days + 1 } } : current)
      }
      setError(reason instanceof Error ? reason.message : 'Could not generate a lesson-plan draft.')
    } finally { setAiGenerating(false) }
  }

  async function copyAiDraft() {
    if (!aiDraft) return
    try { await navigator.clipboard.writeText(aiDraft); setMessage('AI lesson-plan draft copied for educator review.') }
    catch { setError('Could not copy the draft. Select the text and copy it manually.') }
  }

  async function enrollStudents(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedCourse || selectedStudents.length === 0) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/courses/${selectedCourse.id}/enrollments`, { student_ids: selectedStudents })
      setMessage(`${selectedStudents.length} student${selectedStudents.length === 1 ? '' : 's'} enrolled.`); setSelectedStudents([])
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not enroll students.') } finally { setSaving(false) }
  }

  async function uploadAsset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedCourse || !assetFile) return
    setSaving(true); setError(''); setMessage('')
    const form = new FormData(); form.append('title', assetTitle); form.append('file', assetFile)
    try {
      const result = await api.postForm<{ data: CourseAsset }>(`${base}/courses/${selectedCourse.id}/assets`, form)
      setAssets(current => [...current, result.data]); setAssetTitle(''); setAssetFile(null)
      if (assetInput.current) assetInput.current.value = ''
      setMessage('Private study material uploaded. Publish it to share with enrolled students.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not upload study material.') } finally { setSaving(false) }
  }

  async function publishAsset(asset: CourseAsset) {
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: CourseAsset }>(`${base}/assets/${asset.id}`, { is_published: true })
      setAssets(current => current.map(item => item.id === asset.id ? result.data : item)); setMessage('Study material shared with enrolled students.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish study material.') } finally { setSaving(false) }
  }

  return <div className="course-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {mode === 'manage' && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">LEARNING MANAGEMENT</span><h2>Create a course</h2><p className="dash-record-subtitle">Courses begin as drafts. Publish at least one lesson before making a course available to enrolled learners.</p></div></div><form className="course-form" onSubmit={event => void createCourse(event)}><label>Course title<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="For example, Foundations of Science" /></label><label>Description<textarea value={description} onChange={event => setDescription(event.target.value)} maxLength={5000} rows={3} placeholder="What will learners study?" /></label><button className="master-submit" disabled={saving}><Plus size={15} />{saving ? 'Saving…' : 'Create draft course'}</button></form></section>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'student' ? 'MY LEARNING' : 'COURSE LIBRARY'}</span><h2>{mode === 'student' ? 'Enrolled courses' : 'Courses and lessons'}</h2><p className="dash-record-subtitle">{mode === 'student' ? 'Published courses you have been enrolled in by your institution.' : 'Manage lesson content and enrollment using saved organization records.'}</p></div>{loading && <LoaderCircle className="master-spin" size={18} />}</div>
      {loading ? <p className="master-empty">Loading courses…</p> : courses.length === 0 ? <p className="master-empty"><BookOpen size={18} />{mode === 'student' ? 'You are not enrolled in a published course yet.' : 'No courses yet. Create one above to begin.'}</p> : <div className="course-layout"><nav className="course-list" aria-label="Courses">{courses.map(course => <button key={course.id} className={course.id === selectedId ? 'course-selected' : ''} onClick={() => setSelectedId(course.id)}><b>{course.title}</b><small>{course.level ?? 'Course'} · {course.status}</small></button>)}</nav>{selectedCourse && <article className="course-detail"><div className="course-detail-heading"><div><span className={`course-status ${selectedCourse.status}`}>{selectedCourse.status}</span><h3>{selectedCourse.title}</h3><p>{selectedCourse.description || 'No course description has been added.'}</p>{mode === 'student' && <p className="course-progress-label">Progress: {progressSummary.completed} of {progressSummary.total} lessons · {progressSummary.percent}%</p>}</div>{mode === 'manage' && selectedCourse.status === 'draft' && <button className="master-submit" onClick={() => void publishCourse()} disabled={saving}>Publish course</button>}</div>
        {mode === 'manage' && <section className="course-public-listing" aria-labelledby={`public-listing-heading-${selectedCourse.id}`}><div><h4 id={`public-listing-heading-${selectedCourse.id}`}>Public course listing</h4><p>When enabled, visitors can find this course on the public site and see its title, description, subject, level, institution, and published lesson count. Lesson content and learner records stay in the organization workspace.</p></div>{selectedCourse.status === 'published' ? <div className="course-public-listing-controls"><label className="course-public-listing-toggle"><input type="checkbox" checked={selectedCourse.is_public_listing} disabled={saving} onChange={event => void setPublicListing(event.currentTarget.checked)} /><span>List this course publicly</span></label>{selectedCourse.is_public_listing && publicCoursePath && <div className="course-public-listing-actions"><a className="master-action" href={publicCoursePath} target="_blank" rel="noreferrer">Open public listing</a><button className="master-action" type="button" onClick={() => void copyPublicCourseLink()}>Copy link</button></div>}{selectedCourse.is_public_listing && !publicCoursePath && <p className="course-public-listing-note">The public link is not available yet. Refresh the course details and try again.</p>}</div> : <p className="course-public-listing-note">{selectedCourse.status === 'draft' ? 'Publish this course first to enable public listing.' : 'Only published courses can be listed publicly.'}</p>}</section>}
        {mode === 'manage' && aiConfiguration?.enabled && <section className="course-ai-panel" aria-labelledby="course-ai-title"><div className="course-ai-heading"><span className="course-ai-icon"><Sparkles size={16} /></span><div><h4 id="course-ai-title">AI lesson-plan draft</h4><p>Uses your locally configured model. No student records are sent. Review every suggestion before using it.</p></div></div><form className="course-form course-ai-form" onSubmit={event => void generateLessonPlan(event)}><label>Draft type<select value={aiDraftType} onChange={event => setAiDraftType(event.target.value as 'lesson_plan' | 'questions')}><option value="lesson_plan">Lesson plan</option><option value="questions">Practice MCQs</option></select></label><label>Topic<input value={aiTopic} onChange={event => setAiTopic(event.target.value)} required minLength={3} maxLength={180} placeholder="For example, the water cycle" /></label><label>Grade or age range (optional)<input value={aiGradeLevel} onChange={event => setAiGradeLevel(event.target.value)} maxLength={80} placeholder="For example, Grade 6" /></label>{aiDraftType === 'questions' && <><label>Question count<select value={aiQuestionCount} onChange={event => setAiQuestionCount(Number(event.target.value))}><option value={3}>3 questions</option><option value={5}>5 questions</option><option value={8}>8 questions</option></select></label><label>Difficulty<select value={aiDifficulty} onChange={event => setAiDifficulty(event.target.value as 'easy' | 'medium' | 'hard')}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></label></>}<label>Learning objectives<textarea value={aiObjectives} onChange={event => setAiObjectives(event.target.value)} required minLength={10} maxLength={1000} rows={3} placeholder="What should learners understand or be able to do?" /></label><label>Draft language<select value={aiLanguage} onChange={event => setAiLanguage(event.target.value as 'English' | 'Hindi')}><option>English</option><option>Hindi</option></select></label><button className="master-submit" disabled={aiGenerating || saving || aiConfiguration.usage.requests_today >= aiConfiguration.daily_limit}><Sparkles size={15} />{aiGenerating ? 'Drafting…' : aiDraftType === 'questions' ? 'Generate MCQ drafts' : 'Generate lesson-plan draft'}</button></form><small className="course-ai-usage">{aiConfiguration.usage.requests_today} of {aiConfiguration.daily_limit} daily requests used · Last 30 days: {aiConfiguration.usage.prompt_tokens_30_days + aiConfiguration.usage.completion_tokens_30_days} model tokens recorded. Prompt and draft text are not stored.</small>{aiDraft && <div className="course-ai-result"><pre>{aiDraft}</pre><button className="master-action" type="button" onClick={() => void copyAiDraft()}>Copy draft to edit</button></div>}</section>}
        {mode === 'manage' && <section className="course-module-manager"><h4>Course modules</h4><p className="course-module-note">Group lessons into a clear learning sequence. Lessons can also stay outside a module.</p>{modules.length ? <div className="course-module-list">{modules.map(module => <form className="course-module-edit" key={module.id} onSubmit={event => void updateModule(event, module)}><label>Module title<input name="title" defaultValue={module.title} required minLength={2} maxLength={160} /></label><label>Description<input name="description" defaultValue={module.description ?? ''} maxLength={5000} /></label><label>Order<input name="sort_order" type="number" defaultValue={module.sort_order} min={0} max={10000} /></label><button className="master-action" disabled={saving}>Save</button></form>)}</div> : <p className="master-empty">No modules have been added yet.</p>}<form className="course-form course-module-form" onSubmit={event => void createModule(event)}><h5>Add a module</h5><label>Module title<input value={moduleTitle} onChange={event => setModuleTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="For example, Getting started" /></label><label>Description<textarea value={moduleDescription} onChange={event => setModuleDescription(event.target.value)} maxLength={5000} rows={2} /></label><button className="master-submit" disabled={saving}><Plus size={15} />{saving ? 'Saving…' : 'Create module'}</button></form></section>}
        <div className="course-lessons"><h4>Lessons</h4>{lessons.length ? <>{modules.map(module => { const moduleLessons = lessons.filter(lesson => lesson.module?.id === module.id); return moduleLessons.length ? <section className="course-module-content" key={module.id}><h5>{module.sort_order}. {module.title}</h5>{module.description && <p>{module.description}</p>}{moduleLessons.map(renderLesson)}</section> : null })}{lessons.some(lesson => !lesson.module) && <section className="course-module-content"><h5>Other lessons</h5>{lessons.filter(lesson => !lesson.module).map(renderLesson)}</section>}</> : <p className="master-empty">No lessons have been added to this course.</p>}</div>
        <div className="course-assets"><h4>Study materials</h4>{assets.length ? <ul>{assets.map(asset => <li key={asset.id}><div><b>{asset.title}</b><small>{asset.filename} · {Math.ceil(asset.size_bytes / 1024)} KB{mode === 'manage' && !asset.is_published ? ' · Private draft' : ''}</small></div>{mode === 'manage' && !asset.is_published ? <button className="master-action" onClick={() => void publishAsset(asset)} disabled={saving || selectedCourse.status !== 'published'}>Publish</button> : <a href={asset.download_url}>Download</a>}</li>)}</ul> : <p className="master-empty">No files have been added to this course.</p>}{mode === 'manage' && <form className="course-form course-asset-form" onSubmit={event => void uploadAsset(event)}><h4>Upload a private study file</h4><label>Display title<input value={assetTitle} onChange={event => setAssetTitle(event.target.value)} required minLength={2} maxLength={180} /></label><label>File (PDF, Office document, or image · up to 20 MB)<input ref={assetInput} type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg,.webp" onChange={event => setAssetFile(event.target.files?.[0] ?? null)} required /></label><button className="master-submit" disabled={saving || !assetFile}><Save size={15} />{saving ? 'Uploading…' : 'Upload privately'}</button></form>}</div>
        {mode === 'manage' && selectedCourse.status === 'published' && <div className="course-progress-report"><h4>Learner progress</h4><p>{learnerProgress.length} enrolled learner{learnerProgress.length === 1 ? '' : 's'} · {learnerProgress.length ? Math.round(learnerProgress.reduce((sum, row) => sum + row.percent, 0) / learnerProgress.length) : 0}% average completion</p>{learnerProgress.length ? <div className="dash-table-scroll"><table><thead><tr><th>STUDENT</th><th>LESSONS</th><th>PROGRESS</th></tr></thead><tbody>{learnerProgress.map(row => <tr key={row.student.id}><td>{row.student.name} · {row.student.admission_number}</td><td>{row.completed} / {row.total}</td><td>{row.percent}%</td></tr>)}</tbody></table></div> : <p className="master-empty">No active learners are enrolled in this course.</p>}</div>}
        {mode === 'manage' && <><form className="course-form course-lesson-form" onSubmit={event => void createLesson(event)}><h4>Add and publish a lesson</h4><label>Lesson title<input value={lessonTitle} onChange={event => setLessonTitle(event.target.value)} required minLength={2} maxLength={160} /></label><label>Module<select value={selectedModuleId} onChange={event => setSelectedModuleId(event.target.value)}><option value="">No module</option>{modules.map(module => <option key={module.id} value={module.id}>{module.sort_order}. {module.title}</option>)}</select></label><label>Learning content<textarea value={lessonContent} onChange={event => setLessonContent(event.target.value)} maxLength={50000} rows={4} placeholder="Write lesson notes or instructions" /></label><label>External resource URL<input type="url" value={lessonUrl} onChange={event => setLessonUrl(event.target.value)} placeholder="https://…" /></label><button className="master-submit" disabled={saving || (!lessonContent.trim() && !lessonUrl.trim())}><Save size={15} />{saving ? 'Saving…' : 'Publish lesson'}</button></form>{selectedCourse.status === 'published' && <form className="course-form course-enroll-form" onSubmit={event => void enrollStudents(event)}><h4>Enroll students</h4><label>Active student profiles<select multiple value={selectedStudents.map(String)} onChange={event => setSelectedStudents(Array.from(event.target.selectedOptions, option => Number(option.value)))}>{students.map(student => <option key={student.id} value={student.id}>{student.name} · {student.admission_number}</option>)}</select></label><button className="master-submit" disabled={saving || selectedStudents.length === 0}>Enroll selected students</button></form>}</>}
      </article>}</div>}
    </section>
  </div>
}
