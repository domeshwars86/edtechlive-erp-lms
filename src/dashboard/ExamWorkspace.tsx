import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, ClipboardCheck, LoaderCircle, Plus, Save } from 'lucide-react'
import { api } from '../lib/api'

type Session = { id: number; name: string; starts_on: string; ends_on: string; is_active: boolean }
type AcademicClass = { id: number; name: string }
type Section = { id: number; name: string; academic_session_id: number | null; is_active: boolean }
type Subject = { id: number; name: string; is_active: boolean }
type PaperSection = { id: number; title: string; max_marks: string; position: number }
type Paper = { id: number; section_id: number; section: string; subject_id: number; subject: string; exam_on: string; max_marks: string; results_recorded: number; paper_sections: PaperSection[] }
type Exam = { id: number; title: string; status: 'draft' | 'published'; starts_on: string; ends_on: string; session_id: number; session: string; papers: Paper[] }
type Mark = { student_id: number; name: string; admission_number: string; outcome: '' | 'graded' | 'absent' | 'excused'; score: string | null; remarks: string | null; section_scores: Record<string, string> }

const localToday = () => { const date = new Date(); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }

export default function ExamWorkspace({ organizationId, canManage }: { organizationId: number; canManage: boolean }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [exams, setExams] = useState<Exam[]>([])
  const [sessions, setSessions] = useState<Session[]>([])
  const [classes, setClasses] = useState<AcademicClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [examTitle, setExamTitle] = useState('')
  const [sessionId, setSessionId] = useState('')
  const [startsOn, setStartsOn] = useState(localToday())
  const [endsOn, setEndsOn] = useState(localToday())
  const [selectedExamId, setSelectedExamId] = useState<number | null>(null)
  const [selectedClassId, setSelectedClassId] = useState('')
  const [sectionId, setSectionId] = useState('')
  const [subjectId, setSubjectId] = useState('')
  const [examOn, setExamOn] = useState(localToday())
  const [maxMarks, setMaxMarks] = useState('100')
  const [paperSections, setPaperSections] = useState<{ title: string; max_marks: string }[]>([])
  const [selectedPaperId, setSelectedPaperId] = useState<number | null>(null)
  const [marks, setMarks] = useState<Mark[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingMarks, setLoadingMarks] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const selectedExam = exams.find(item => item.id === selectedExamId) ?? null
  const selectedExamSessionId = selectedExam?.session_id
  const selectedPaper = selectedExam?.papers.find(item => item.id === selectedPaperId) ?? null

  async function refreshExams() {
    const result = await api.get<{ data: Exam[] }>(`${base}/exams`)
    setExams(result.data)
  }

  useEffect(() => {
    let cancelled = false
    const requests: Promise<unknown>[] = [api.get<{ data: Exam[] }>(`${base}/exams`)]
    if (canManage) requests.push(api.get<{ data: Session[] }>(`${base}/academic-sessions`), api.get<{ data: AcademicClass[] }>(`${base}/classes`), api.get<{ data: Subject[] }>(`${base}/subjects`))
    Promise.all(requests).then(([examResponse, sessionResponse, classResponse, subjectResponse]) => {
      if (cancelled) return
      setExams((examResponse as { data: Exam[] }).data)
      if (canManage) {
        const availableSessions = (sessionResponse as { data: Session[] }).data.filter(item => item.is_active)
        setSessions(availableSessions)
        setSessionId(current => current || String(availableSessions.find(item => item.starts_on <= localToday() && item.ends_on >= localToday())?.id ?? availableSessions[0]?.id ?? ''))
        setClasses((classResponse as { data: AcademicClass[] }).data)
        setSubjects((subjectResponse as { data: Subject[] }).data.filter(item => item.is_active))
      }
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load examination records.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, canManage])

  useEffect(() => {
    const selectedClass = classes.find(item => String(item.id) === selectedClassId)
    if (!selectedClass || selectedExamSessionId === undefined) { setSections([]); setSectionId(''); return }
    let cancelled = false
    api.get<{ data: Section[] }>(`${base}/classes/${selectedClass.id}/sections`).then(result => {
      if (cancelled) return
      const sessionSections = result.data.filter(section => section.is_active && section.academic_session_id === selectedExamSessionId)
      setSections(sessionSections); setSectionId(current => sessionSections.some(section => String(section.id) === current) ? current : '')
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load class sections.') })
    return () => { cancelled = true }
  }, [base, classes, selectedClassId, selectedExamSessionId])

  async function createExam(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: { id: number } }>(`${base}/exams`, { academic_session_id: Number(sessionId), title: examTitle.trim(), starts_on: startsOn, ends_on: endsOn })
      await refreshExams(); setSelectedExamId(result.data.id); setExamTitle(''); setMessage('Examination created as a draft.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create the examination.') } finally { setSaving(false) }
  }

  async function addPaper(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedExam) return
    setSaving(true); setError(''); setMessage('')
    try {
      if (paperSections.some(item => !item.title.trim() || !item.max_marks)) throw new Error('Complete every paper section title and maximum mark.')
      const sectionMaximum = paperSections.reduce((total, item) => total + Math.round(Number(item.max_marks) * 100), 0)
      if (paperSections.length && sectionMaximum !== Math.round(Number(maxMarks) * 100)) throw new Error('Paper section maximum marks must add up to the paper maximum.')
      await api.post(`${base}/exams/${selectedExam.id}/papers`, { academic_section_id: Number(sectionId), subject_id: Number(subjectId), exam_on: examOn, max_marks: maxMarks, paper_sections: paperSections.filter(item => item.title.trim() && item.max_marks) })
      setPaperSections([])
      await refreshExams(); setMessage('Exam paper scheduled.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not schedule the paper.') } finally { setSaving(false) }
  }

  async function loadMarks(exam: Exam, paper: Paper) {
    setSelectedExamId(exam.id); setSelectedPaperId(paper.id); setLoadingMarks(true); setError(''); setMessage('')
    try {
      const result = await api.get<{ data: Omit<Mark, 'section_scores'>[] }>(`${base}/exam-papers/${paper.id}/results`)
      setMarks(result.data.map(mark => ({ ...mark, section_scores: (mark as Mark).section_scores ?? {} })))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load student marks.') } finally { setLoadingMarks(false) }
  }

  function updateMark(studentId: number, update: Partial<Mark>) {
    setMarks(current => current.map(mark => mark.student_id === studentId ? { ...mark, ...update } : mark))
  }

  async function saveMarks(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedPaper || !selectedExam) return
    setSaving(true); setError(''); setMessage('')
    try {
      const records = marks.filter(mark => mark.outcome).map(mark => ({
        student_id: mark.student_id, outcome: mark.outcome,
        score: mark.outcome === 'graded' ? mark.score : null, remarks: mark.remarks || null,
        section_scores: selectedPaper.paper_sections.map(section => ({ section_id: section.id, score: mark.section_scores[String(section.id)] ?? '' })),
      }))
      if (records.length === 0) throw new Error('Choose a result status for at least one student.')
      if (records.some(mark => mark.outcome === 'graded' && !mark.score)) throw new Error('Enter a score for each graded student.')
      if (selectedPaper.paper_sections.length && marks.filter(mark => mark.outcome === 'graded').some(mark => selectedPaper.paper_sections.some(part => !mark.section_scores[String(part.id)]) || Math.round(selectedPaper.paper_sections.reduce((total, part) => total + Number(mark.section_scores[String(part.id)] ?? 0), 0) * 100) !== Math.round(Number(mark.score ?? 0) * 100))) throw new Error('Enter each paper section score and make sure they add up to the total score.')
      await api.put(`${base}/exam-papers/${selectedPaper.id}/results`, { records })
      await loadMarks(selectedExam, selectedPaper); await refreshExams(); setMessage('Marks saved. Add a result or absence for every student before publishing.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save the examination results.') } finally { setSaving(false) }
  }

  async function publishExam(exam: Exam) {
    setSaving(true); setError(''); setMessage('')
    try { await api.post(`${base}/exams/${exam.id}/publish`); await refreshExams(); setMessage('Examination results are now published to students and linked parents.') }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish examination results.') } finally { setSaving(false) }
  }

  return <div className="exam-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {canManage && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC CALENDAR</span><h2>Create an examination</h2><p className="dash-record-subtitle">Set the session and exam window. Add one subject paper per section, then record each learner result.</p></div></div>
      <form className="fee-create-form" onSubmit={event => void createExam(event)}><label>Academic session<select value={sessionId} onChange={event => setSessionId(event.target.value)} required><option value="">Choose a session</option>{sessions.map(session => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label><label>Examination name<input value={examTitle} onChange={event => setExamTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="Term 1 examinations" /></label><label>Starts on<input type="date" value={startsOn} onChange={event => setStartsOn(event.target.value)} required /></label><label>Ends on<input type="date" value={endsOn} min={startsOn} onChange={event => setEndsOn(event.target.value)} required /></label><button className="master-submit" disabled={saving || !sessionId}><Plus size={15} />Create draft</button></form>
    </section>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">SCHOOL ASSESSMENTS</span><h2>Examinations</h2><p className="dash-record-subtitle">Marks are private until authorized staff publish complete results for the exam.</p></div></div>
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading examinations…</p> : exams.length === 0 ? <p className="master-empty"><ClipboardCheck size={18} />No school examinations have been created yet.</p> : <div className="exam-list">{exams.map(exam => <article className="exam-card" key={exam.id}>
        <header><div><span className={`fee-status ${exam.status === 'published' ? 'fee-paid' : ''}`}>{exam.status}</span><h3>{exam.title}</h3><p>{exam.session} · {exam.starts_on} – {exam.ends_on}</p></div>{canManage && exam.status === 'draft' && <div className="exam-card-actions"><button className="fee-cancel" type="button" onClick={() => { setSelectedExamId(current => current === exam.id ? null : exam.id); setSelectedPaperId(null) }}>{selectedExamId === exam.id ? 'Close setup' : 'Add papers'}</button><button className="master-submit" disabled={saving || exam.papers.length === 0} onClick={() => void publishExam(exam)}>Publish results</button></div>}</header>
        {canManage && exam.status === 'draft' && selectedExamId === exam.id && <form className="fee-create-form exam-paper-form" onSubmit={event => void addPaper(event)}><label>Class<select value={selectedClassId} onChange={event => setSelectedClassId(event.target.value)} required><option value="">Choose class</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Section<select value={sectionId} onChange={event => setSectionId(event.target.value)} required><option value="">Choose section</option>{sections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Subject<select value={subjectId} onChange={event => setSubjectId(event.target.value)} required><option value="">Choose subject</option>{subjects.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Exam date<input type="date" min={exam.starts_on} max={exam.ends_on} value={examOn} onChange={event => setExamOn(event.target.value)} required /></label><label>Maximum marks<input type="number" min="0.01" step="0.01" max="99999999.99" value={maxMarks} onChange={event => setMaxMarks(event.target.value)} required /></label><fieldset className="exam-paper-sections"><legend>Paper sections (optional)</legend><p>Use sections for board-style papers such as Part A and Part B. Section maximums must add up to the paper maximum.</p>{paperSections.map((item, index) => <div className="exam-paper-section-row" key={index}><label>Section title<input aria-label={`Paper section ${index + 1} title`} value={item.title} maxLength={100} onChange={event => setPaperSections(current => current.map((row, rowIndex) => rowIndex === index ? { ...row, title: event.target.value } : row))} placeholder={`Part ${String.fromCharCode(65 + index)}`} /></label><label>Maximum marks<input aria-label={`Paper section ${index + 1} maximum marks`} type="number" min="0.01" step="0.01" value={item.max_marks} onChange={event => setPaperSections(current => current.map((row, rowIndex) => rowIndex === index ? { ...row, max_marks: event.target.value } : row))} /></label><button type="button" className="fee-cancel" onClick={() => setPaperSections(current => current.filter((_, rowIndex) => rowIndex !== index))}>Remove section</button></div>)}<button type="button" className="fee-cancel" disabled={paperSections.length >= 20} onClick={() => setPaperSections(current => [...current, { title: '', max_marks: '' }])}><Plus size={14} />Add paper section</button></fieldset><button className="master-submit" disabled={saving || !sectionId || !subjectId}><Plus size={15} />Add paper</button></form>}
        <div className="dash-table-scroll attendance-table"><table><thead><tr><th>SECTION</th><th>SUBJECT</th><th>EXAM DATE</th><th>PAPER PARTS</th><th>MARKS RECORDED</th><th></th></tr></thead><tbody>{exam.papers.map(paper => <tr key={paper.id}><td>{paper.section}</td><td>{paper.subject}</td><td>{paper.exam_on}</td><td>{paper.paper_sections?.length ? paper.paper_sections.map(part => `${part.title} (${part.max_marks})`).join(' · ') : 'Whole paper'}</td><td>{paper.results_recorded}</td><td><button className="fee-cancel" onClick={() => { setSelectedExamId(exam.id); void loadMarks(exam, paper) }}>{exam.status === 'published' ? 'View marks' : 'Enter marks'}</button></td></tr>)}{exam.papers.length === 0 && <tr><td colSpan={6}>No papers scheduled yet.</td></tr>}</tbody></table></div>
      </article>)}</div>}
    </section>
    {selectedExam && selectedPaperId && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selectedPaper?.subject ?? 'EXAM PAPER'}</span><h2>{selectedExam.title} · {selectedPaper?.section}</h2><p className="dash-record-subtitle">Maximum marks: {selectedPaper?.max_marks}. Mark every student as graded, absent, or excused before publishing.</p></div><button type="button" className="fee-cancel" onClick={() => setSelectedPaperId(null)}>Close</button></div>
      {loadingMarks ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading student marks…</p> : <form onSubmit={event => void saveMarks(event)}><div className="dash-table-scroll attendance-table"><table><thead><tr><th>STUDENT</th><th>ADMISSION</th><th>OUTCOME</th>{selectedPaper?.paper_sections.map(part => <th key={part.id}>{part.title} / {part.max_marks}</th>)}<th>SCORE / {selectedPaper?.max_marks}</th><th>REMARKS</th></tr></thead><tbody>{marks.map(mark => <tr key={mark.student_id}><td>{mark.name}</td><td>{mark.admission_number}</td><td>{selectedExam.status === 'published' ? mark.outcome || '—' : <select aria-label={`Outcome for ${mark.name}`} value={mark.outcome} onChange={event => updateMark(mark.student_id, { outcome: event.target.value as Mark['outcome'], score: event.target.value === 'graded' ? mark.score : null })}><option value="">Choose</option><option value="graded">Graded</option><option value="absent">Absent</option><option value="excused">Excused</option></select>}</td>{selectedPaper?.paper_sections.map(part => <td key={part.id}>{selectedExam.status === 'published' ? mark.section_scores[String(part.id)] ?? '—' : mark.outcome === 'graded' ? <input aria-label={`${part.title} score for ${mark.name}`} type="number" min="0" max={part.max_marks} step="0.01" value={mark.section_scores[String(part.id)] ?? ''} onChange={event => updateMark(mark.student_id, { section_scores: { ...mark.section_scores, [String(part.id)]: event.target.value } })} /> : '—'}</td>)}<td>{selectedExam.status === 'published' ? `${mark.score ?? '—'} / ${selectedPaper?.max_marks}` : mark.outcome === 'graded' ? <input aria-label={`Total score for ${mark.name}`} type="number" min="0" max={selectedPaper?.max_marks} step="0.01" value={mark.score ?? ''} onChange={event => updateMark(mark.student_id, { score: event.target.value })} /> : '—'}</td><td>{selectedExam.status === 'published' ? mark.remarks || '—' : <input aria-label={`Remarks for ${mark.name}`} value={mark.remarks ?? ''} maxLength={500} onChange={event => updateMark(mark.student_id, { remarks: event.target.value })} />}</td></tr>)}</tbody></table></div>{selectedExam.status === 'draft' && <button className="master-submit exam-save-button" disabled={saving}><Save size={15} />Save results</button>}</form>}
    </section>}
  </div>
}
