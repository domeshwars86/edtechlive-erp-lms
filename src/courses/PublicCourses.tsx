import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, ArrowUpRight, BookOpen, CircleHelp, LoaderCircle, Search } from 'lucide-react'
import { ApiError, api } from '../lib/api'
import '../styles/public-courses.css'

type PublicCourse = {
  title: string
  description: string | null
  level: string | null
  slug: string
  subject: string | null
  organization: { name: string; slug: string }
  published_lesson_count: number
}

type PublicCourseList = {
  data: PublicCourse[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  filters: { levels: string[]; subjects: { name: string }[] }
}

type PublicCourseDetail = PublicCourse & { lessons: { title: string; sort_order: number }[] }

const cardColors = ['public-course-card--lilac', 'public-course-card--mint', 'public-course-card--peach']

function CourseCard({ course, index }: { course: PublicCourse; index: number }) {
  return <Link className="public-course-card" aria-label={`View course ${course.title} at ${course.organization.name}`} to={`/courses/${encodeURIComponent(course.organization.slug)}/${encodeURIComponent(course.slug)}`}>
    <article>
      <div className={`public-course-card__art ${cardColors[index % cardColors.length]}`} aria-hidden="true">
        <span>{(course.subject || course.title).slice(0, 3).toUpperCase()}</span><i>✳</i><small>{course.subject || 'COURSE'}</small>
      </div>
      <div className="public-course-card__body">
        <div className="public-course-card__meta"><span>{course.level || 'All levels'}</span><span><BookOpen size={13} />{course.published_lesson_count} lessons</span></div>
        <h2>{course.title}</h2>
        {course.description && <p>{course.description}</p>}
        <div className="public-course-card__org"><span>{course.organization.name}</span><ArrowUpRight size={16} /></div>
      </div>
    </article>
  </Link>
}

export function PublicCourses() {
  const [query, setQuery] = useState('')
  const [level, setLevel] = useState('')
  const [subject, setSubject] = useState('')
  const [page, setPage] = useState(1)
  const [reload, setReload] = useState(0)
  const [result, setResult] = useState<PublicCourseList | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    const timeout = window.setTimeout(() => {
      const params = new URLSearchParams({ per_page: '12', page: String(page) })
      if (query.trim()) params.set('q', query.trim())
      if (level) params.set('level', level)
      if (subject) params.set('subject', subject)
      setLoading(true)
      setError('')
      api.get<PublicCourseList>(`/api/v1/public/courses?${params.toString()}`)
        .then(data => { if (active) setResult(data) })
        .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Published courses could not be loaded. Please try again.') })
        .finally(() => { if (active) setLoading(false) })
    }, query ? 220 : 0)

    return () => { active = false; window.clearTimeout(timeout) }
  }, [query, level, subject, page, reload])

  function updateFilter(update: () => void) {
    update()
    setPage(1)
  }

  return <>
    <header className="public-courses-heading">
      <span className="public-courses-kicker">Explore the learning space</span>
      <h1>A place for good<br /><em>questions to begin.</em></h1>
      <p>Discover courses that educators have chosen to share publicly. Each listing includes published lesson titles and course details.</p>
    </header>
    <section className="public-courses-content" aria-label="Published courses">
      <div className="public-course-filters">
        <label className="public-course-search"><Search size={17} /><span className="sr-only">Search published courses</span><input value={query} onChange={event => updateFilter(() => setQuery(event.target.value))} placeholder="Search courses, subjects or schools" /></label>
        <label><span className="sr-only">Filter by subject</span><select value={subject} onChange={event => updateFilter(() => setSubject(event.target.value))}><option value="">All subjects</option>{result?.filters.subjects.map(item => <option key={item.name} value={item.name}>{item.name}</option>)}</select></label>
        <label><span className="sr-only">Filter by education level</span><select value={level} onChange={event => updateFilter(() => setLevel(event.target.value))}><option value="">All levels</option>{result?.filters.levels.map(item => <option key={item} value={item}>{item}</option>)}</select></label>
        {result && <span className="public-course-count">{result.meta.total} {result.meta.total === 1 ? 'course' : 'courses'}</span>}
      </div>
      {error && <div className="public-courses-state public-courses-state--error" role="alert"><CircleHelp size={20} /><div><b>We couldn’t load courses.</b><p>{error}</p><button type="button" onClick={() => setReload(current => current + 1)}>Try again</button></div></div>}
      {loading && <div className="public-courses-loading" role="status"><LoaderCircle className="public-course-spin" size={19} />Loading published courses…</div>}
      {!loading && !error && result?.data.length === 0 && <div className="public-courses-state"><CircleHelp size={22} /><h2>{result.meta.total === 0 && !query && !subject && !level ? 'No public courses yet' : 'No courses match these filters'}</h2><p>{result.meta.total === 0 && !query && !subject && !level ? 'Courses appear here when an active organization publishes a course and enables public listing.' : 'Try another search or clear one of the filters.'}</p>{(query || subject || level) && <button type="button" onClick={() => { setQuery(''); setLevel(''); setSubject('') }}>Clear filters</button>}</div>}
      {!loading && !error && result && result.data.length > 0 && <>
        <div className="public-course-grid">{result.data.map((course, index) => <CourseCard key={`${course.organization.slug}/${course.slug}`} course={course} index={index} />)}</div>
        {result.meta.last_page > 1 && <nav className="public-course-pagination" aria-label="Course pages"><button type="button" disabled={page <= 1} onClick={() => setPage(current => current - 1)}><ArrowLeft size={15} />Previous</button><span>Page {result.meta.current_page} of {result.meta.last_page}</span><button type="button" disabled={page >= result.meta.last_page} onClick={() => setPage(current => current + 1)}>Next<ArrowRight size={15} /></button></nav>}
      </>}
    </section>
  </>
}

export function PublicCourseDetails() {
  const { organizationSlug = '', courseSlug = '' } = useParams()
  const [course, setCourse] = useState<PublicCourseDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    api.get<{ data: PublicCourseDetail }>(`/api/v1/public/organizations/${encodeURIComponent(organizationSlug)}/courses/${encodeURIComponent(courseSlug)}`)
      .then(result => { if (active) setCourse(result.data) })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'This published course could not be loaded.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [organizationSlug, courseSlug])

  if (loading) return <section className="public-course-detail-state" role="status"><LoaderCircle className="public-course-spin" />Loading course…</section>
  if (error || !course) return <section className="public-course-detail-state" role="alert"><CircleHelp size={23} /><h1>Course unavailable</h1><p>{error || 'This course may no longer be publicly listed.'}</p><Link to="/courses">Browse published courses</Link></section>

  return <>
    <header className="public-course-detail-heading"><Link to="/courses"><ArrowLeft size={15} />All courses</Link><span className="public-courses-kicker">{course.organization.name}{course.subject ? ` · ${course.subject}` : ''}</span><h1>{course.title}</h1><p>{course.description || 'Course details and published lesson outline.'}</p></header>
    <section className="public-course-detail-content"><aside className="public-course-summary"><span>COURSE AT A GLANCE</span><dl><div><dt>Organization</dt><dd>{course.organization.name}</dd></div><div><dt>Subject</dt><dd>{course.subject || 'Not specified'}</dd></div><div><dt>Level</dt><dd>{course.level || 'All levels'}</dd></div><div><dt>Published lessons</dt><dd>{course.published_lesson_count}</dd></div></dl><p>Enrollment is managed by the organization. Contact them to ask about availability.</p><Link className="public-course-contact" to={`/contact?${new URLSearchParams({ course: course.title, organization: course.organization.name })}`}>Ask about this course <ArrowUpRight size={15} /></Link></aside><main className="public-course-outline"><span className="public-courses-kicker">Course outline</span><h2>What you’ll explore</h2>{course.lessons.length ? <ol>{course.lessons.map((lesson, index) => <li key={`${lesson.sort_order}-${lesson.title}`}><span>{String(index + 1).padStart(2, '0')}</span><b>{lesson.title}</b><small>Published lesson</small></li>)}</ol> : <p>No lesson titles are available yet.</p>}</main></section>
  </>
}
