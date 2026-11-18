import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, BookOpen, Check, LoaderCircle, Plus, Save } from 'lucide-react'
import { api } from '../lib/api'

type Course = { id: number; title: string; level: string | null; description: string | null; status: 'draft' | 'published' | 'archived'; subject: { id: number; name: string } | null; teacher: { id: number; name: string } | null }
type Lesson = { id: number; title: string; sort_order: number; content: string | null; external_url: string | null; status: 'draft' | 'published' }
type Student = { id: number; name: string; admission_number: string }

export default function CourseWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'student' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [courses, setCourses] = useState<Course[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [lessons, setLessons] = useState<Lesson[]>([])
  const [students, setStudents] = useState<Student[]>([])
  const [selectedStudents, setSelectedStudents] = useState<number[]>([])
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [lessonTitle, setLessonTitle] = useState('')
  const [lessonContent, setLessonContent] = useState('')
  const [lessonUrl, setLessonUrl] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

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
    return () => { cancelled = true }
  }, [base, mode])

  const selectedCourse = courses.find(course => course.id === selectedId) ?? null
  useEffect(() => {
    let cancelled = false
    if (!selectedCourse) { setLessons([]); return }
    api.get<{ data: Lesson[] }>(`${base}/courses/${selectedCourse.id}/lessons`)
      .then(result => { if (!cancelled) setLessons(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load course lessons.') })
    return () => { cancelled = true }
  }, [base, selectedCourse])

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
      const result = await api.post<{ data: Lesson }>(`${base}/courses/${selectedCourse.id}/lessons`, { title: lessonTitle, content: lessonContent || null, external_url: lessonUrl || null, status: 'published', sort_order: lessons.length + 1 })
      setLessons(current => [...current, result.data]); setLessonTitle(''); setLessonContent(''); setLessonUrl(''); setMessage('Lesson published.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save lesson.') } finally { setSaving(false) }
  }

  async function publishCourse() {
    if (!selectedCourse) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: Course }>(`${base}/courses/${selectedCourse.id}`, { status: 'published' })
      setCourses(current => current.map(course => course.id === result.data.id ? result.data : course)); setMessage('Course published for enrolled students.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish course.') } finally { setSaving(false) }
  }

  async function enrollStudents(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedCourse || selectedStudents.length === 0) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/courses/${selectedCourse.id}/enrollments`, { student_ids: selectedStudents })
      setMessage(`${selectedStudents.length} student${selectedStudents.length === 1 ? '' : 's'} enrolled.`); setSelectedStudents([])
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not enroll students.') } finally { setSaving(false) }
  }

  return <div className="course-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {mode === 'manage' && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">LEARNING MANAGEMENT</span><h2>Create a course</h2><p className="dash-record-subtitle">Courses begin as drafts. Publish at least one lesson before making a course available to enrolled learners.</p></div></div><form className="course-form" onSubmit={event => void createCourse(event)}><label>Course title<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="For example, Foundations of Science" /></label><label>Description<textarea value={description} onChange={event => setDescription(event.target.value)} maxLength={5000} rows={3} placeholder="What will learners study?" /></label><button className="master-submit" disabled={saving}><Plus size={15} />{saving ? 'Saving…' : 'Create draft course'}</button></form></section>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'student' ? 'MY LEARNING' : 'COURSE LIBRARY'}</span><h2>{mode === 'student' ? 'Enrolled courses' : 'Courses and lessons'}</h2><p className="dash-record-subtitle">{mode === 'student' ? 'Published courses you have been enrolled in by your institution.' : 'Manage lesson content and enrollment using saved organization records.'}</p></div>{loading && <LoaderCircle className="master-spin" size={18} />}</div>
      {loading ? <p className="master-empty">Loading courses…</p> : courses.length === 0 ? <p className="master-empty"><BookOpen size={18} />{mode === 'student' ? 'You are not enrolled in a published course yet.' : 'No courses yet. Create one above to begin.'}</p> : <div className="course-layout"><nav className="course-list" aria-label="Courses">{courses.map(course => <button key={course.id} className={course.id === selectedId ? 'course-selected' : ''} onClick={() => setSelectedId(course.id)}><b>{course.title}</b><small>{course.level ?? 'Course'} · {course.status}</small></button>)}</nav>{selectedCourse && <article className="course-detail"><div className="course-detail-heading"><div><span className={`course-status ${selectedCourse.status}`}>{selectedCourse.status}</span><h3>{selectedCourse.title}</h3><p>{selectedCourse.description || 'No course description has been added.'}</p></div>{mode === 'manage' && selectedCourse.status === 'draft' && <button className="master-submit" onClick={() => void publishCourse()} disabled={saving}>Publish course</button>}</div><div className="course-lessons"><h4>Lessons</h4>{lessons.length ? lessons.map(lesson => <section key={lesson.id} className="course-lesson"><b>{lesson.sort_order}. {lesson.title}</b>{lesson.content && <p>{lesson.content}</p>}{lesson.external_url && <a href={lesson.external_url} target="_blank" rel="noreferrer">Open learning resource</a>}</section>) : <p className="master-empty">No lessons have been added to this course.</p>}</div>
        {mode === 'manage' && <><form className="course-form course-lesson-form" onSubmit={event => void createLesson(event)}><h4>Add and publish a lesson</h4><label>Lesson title<input value={lessonTitle} onChange={event => setLessonTitle(event.target.value)} required minLength={2} maxLength={160} /></label><label>Learning content<textarea value={lessonContent} onChange={event => setLessonContent(event.target.value)} maxLength={50000} rows={4} placeholder="Write lesson notes or instructions" /></label><label>External resource URL<input type="url" value={lessonUrl} onChange={event => setLessonUrl(event.target.value)} placeholder="https://…" /></label><button className="master-submit" disabled={saving || (!lessonContent.trim() && !lessonUrl.trim())}><Save size={15} />{saving ? 'Saving…' : 'Publish lesson'}</button></form>{selectedCourse.status === 'published' && <form className="course-form course-enroll-form" onSubmit={event => void enrollStudents(event)}><h4>Enroll students</h4><label>Active student profiles<select multiple value={selectedStudents.map(String)} onChange={event => setSelectedStudents(Array.from(event.target.selectedOptions, option => Number(option.value)))}>{students.map(student => <option key={student.id} value={student.id}>{student.name} · {student.admission_number}</option>)}</select></label><button className="master-submit" disabled={saving || selectedStudents.length === 0}>Enroll selected students</button></form>}</>}
      </article>}</div>}
    </section>
  </div>
}
