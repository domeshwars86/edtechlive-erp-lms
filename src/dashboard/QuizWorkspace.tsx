import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, ClipboardList, LoaderCircle, Pencil, Play, Plus, Save, Trash2 } from 'lucide-react'
import { api } from '../lib/api'

type Course = { id: number; title: string }
type Question = { id: number; prompt: string; options: string[]; points: number; question_type: 'single_choice' | 'multiple_select' | 'short_answer'; difficulty: 'easy' | 'medium' | 'hard'; question_bank_question_id?: number | null; correct_option?: number; correct_options?: number[] }
type QuestionBank = { id: number; title: string; description: string | null; questions_count: number }
type BankQuestion = { id: number; prompt: string; options: string[]; points: number; sort_order: number; question_type: Question['question_type']; difficulty: Question['difficulty']; correct_options: number[] }
type AttemptResult = { id: number; attempt_number: number; answers?: Record<number, number | number[] | string> | null; manual_grades?: Record<number, { awarded_points: number; feedback: string | null; graded_at: string | null }> | null; review_status?: 'not_required' | 'pending' | 'partial' | 'graded'; result_outcome?: 'not_set' | 'pending_review' | 'passed' | 'failed'; score: string | null; max_score: string | null; submitted_at: string | null; student?: { name: string; admission_number: string } | null }
type Quiz = { id: number; course_id: number; title: string; instructions: string | null; time_limit_minutes: number | null; max_attempts: number; opens_at: string | null; closes_at: string | null; negative_marking_rate: string | number; pass_percent: number | null; status: 'draft' | 'published' | 'archived'; questions: Question[]; attempts_used?: number; latest_result?: AttemptResult | null }
type ActiveAttempt = { id: number; attempt_number: number }
type AttemptPage = { data: AttemptResult[]; meta: { current_page: number; last_page: number; total: number } }

export default function QuizWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'student' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [courses, setCourses] = useState<Course[]>([])
  const [courseId, setCourseId] = useState<number | null>(null)
  const [quizzes, setQuizzes] = useState<Quiz[]>([])
  const [questionBanks, setQuestionBanks] = useState<QuestionBank[]>([])
  const [bankQuestions, setBankQuestions] = useState<BankQuestion[]>([])
  const [selectedBankId, setSelectedBankId] = useState<number | null>(null)
  const [bankSearch, setBankSearch] = useState('')
  const [bankPage, setBankPage] = useState(1)
  const [bankLastPage, setBankLastPage] = useState(1)
  const [bankTotal, setBankTotal] = useState(0)
  const [editingBankQuestion, setEditingBankQuestion] = useState<number | null>(null)
  const [bankEditPrompt, setBankEditPrompt] = useState('')
  const [bankEditOptions, setBankEditOptions] = useState('')
  const [bankEditCorrectOptions, setBankEditCorrectOptions] = useState<number[]>([])
  const [bankEditType, setBankEditType] = useState<Question['question_type']>('single_choice')
  const [bankEditDifficulty, setBankEditDifficulty] = useState<Question['difficulty']>('medium')
  const [bankEditPoints, setBankEditPoints] = useState('1')
  const [bankTitle, setBankTitle] = useState('')
  const [bankDescription, setBankDescription] = useState('')
  const [bankSettingsOpen, setBankSettingsOpen] = useState(false)
  const [bankNameDraft, setBankNameDraft] = useState('')
  const [bankDescriptionDraft, setBankDescriptionDraft] = useState('')
  const [saveToBank, setSaveToBank] = useState(false)
  const [quizId, setQuizId] = useState<number | null>(null)
  const [results, setResults] = useState<AttemptResult[]>([])
  const [resultsPage, setResultsPage] = useState(1)
  const [resultsLastPage, setResultsLastPage] = useState(1)
  const [resultsTotal, setResultsTotal] = useState(0)
  const [attempt, setAttempt] = useState<ActiveAttempt | null>(null)
  const [answers, setAnswers] = useState<Record<number, number[] | string>>({})
  const [title, setTitle] = useState('')
  const [instructions, setInstructions] = useState('')
  const [timeLimit, setTimeLimit] = useState('20')
  const [maxAttempts, setMaxAttempts] = useState('1')
  const [opensAt, setOpensAt] = useState('')
  const [closesAt, setClosesAt] = useState('')
  const [negativeMarkingRate, setNegativeMarkingRate] = useState('0')
  const [passPercent, setPassPercent] = useState('')
  const [prompt, setPrompt] = useState('')
  const [optionsText, setOptionsText] = useState('Option one\nOption two\nOption three\nOption four')
  const [correctOption, setCorrectOption] = useState('0')
  const [correctOptions, setCorrectOptions] = useState<number[]>([])
  const [questionType, setQuestionType] = useState<Question['question_type']>('single_choice')
  const [difficulty, setDifficulty] = useState<Question['difficulty']>('medium')
  const [points, setPoints] = useState('1')
  const [manualReviewAttempt, setManualReviewAttempt] = useState<AttemptResult | null>(null)
  const [reviewScores, setReviewScores] = useState<Record<number, string>>({})
  const [reviewFeedback, setReviewFeedback] = useState<Record<number, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Course[] }>(`${base}/courses?per_page=100`)
      .then(result => { if (!cancelled) { setCourses(result.data); setCourseId(result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load courses.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  const course = courses.find(item => item.id === courseId) ?? null
  const parsedOptions = optionsText.split('\n').map(option => option.trim()).filter(Boolean)

  const loadQuestionBanks = useCallback(async () => {
    if (!course || mode !== 'manage') { setQuestionBanks([]); setBankQuestions([]); setSelectedBankId(null); return }
    const result = await api.get<{ data: QuestionBank[] }>(`${base}/courses/${course.id}/question-banks`)
    setQuestionBanks(result.data)
    setSelectedBankId(current => result.data.some(bank => bank.id === current) ? current : result.data[0]?.id ?? null)
  }, [base, course, mode])

  useEffect(() => {
    let cancelled = false
    if (!course || mode !== 'manage') { setQuestionBanks([]); setBankQuestions([]); setSelectedBankId(null); return }
    setQuestionBanks([]); setBankQuestions([]); setSelectedBankId(null)
    api.get<{ data: QuestionBank[] }>(`${base}/courses/${course.id}/question-banks`)
      .then(result => { if (!cancelled) { setQuestionBanks(result.data); setSelectedBankId(result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load question banks.') })
    return () => { cancelled = true }
  }, [base, course, mode])

  useEffect(() => {
    let cancelled = false
    if (!selectedBankId || mode !== 'manage') { setBankQuestions([]); return }
    const params = new URLSearchParams({ per_page: '50', page: String(bankPage) })
    if (bankSearch.trim()) params.set('q', bankSearch.trim())
    api.get<{ data: BankQuestion[]; meta: { last_page: number; total: number } }>(`${base}/question-banks/${selectedBankId}/questions?${params}`)
      .then(result => { if (!cancelled) { setBankQuestions(result.data); setBankLastPage(result.meta.last_page); setBankTotal(result.meta.total); setBankPage(current => Math.min(current, result.meta.last_page)) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load bank questions.') })
    return () => { cancelled = true }
  }, [base, mode, selectedBankId, bankSearch, bankPage])
  const loadQuizzes = useCallback(async () => {
    if (!course) { setQuizzes([]); setQuizId(null); return }
    const result = await api.get<{ data: Quiz[] }>(`${base}/courses/${course.id}/quizzes`)
    setQuizzes(result.data)
    setQuizId(current => result.data.some(item => item.id === current) ? current : result.data[0]?.id ?? null)
  }, [base, course])

  useEffect(() => {
    let cancelled = false
    if (!course) { setQuizzes([]); return }
    api.get<{ data: Quiz[] }>(`${base}/courses/${course.id}/quizzes`)
      .then(result => { if (!cancelled) { setQuizzes(result.data); setQuizId(result.data[0]?.id ?? null); setAttempt(null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load quizzes.') })
    return () => { cancelled = true }
  }, [base, course])

  const quiz = quizzes.find(item => item.id === quizId) ?? null
  useEffect(() => {
    let cancelled = false
    if (!quiz) { setResults([]); return }
    api.get<AttemptPage>(`${base}/quizzes/${quiz.id}/attempts?per_page=20&page=${resultsPage}`)
      .then(result => { if (!cancelled) { setResults(result.data); setResultsLastPage(result.meta.last_page); setResultsTotal(result.meta.total) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load quiz results.') })
    return () => { cancelled = true }
  }, [base, mode, quiz, resultsPage])

  async function createQuiz(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!course) return
    setSaving(true); setError(''); setMessage('')
    try {
      const created = await api.post<{ data: Quiz }>(`${base}/courses/${course.id}/quizzes`, { title, instructions: instructions || null, time_limit_minutes: timeLimit ? Number(timeLimit) : null, max_attempts: Number(maxAttempts), opens_at: opensAt ? new Date(opensAt).toISOString() : null, closes_at: closesAt ? new Date(closesAt).toISOString() : null, negative_marking_rate: Number(negativeMarkingRate), pass_percent: passPercent === '' ? null : Number(passPercent) })
      await loadQuizzes(); setQuizId(created.data.id); setTitle(''); setInstructions(''); setOpensAt(''); setClosesAt(''); setPassPercent(''); setNegativeMarkingRate('0'); setMessage('Quiz draft created.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create quiz.') } finally { setSaving(false) }
  }

  async function createQuestionBank(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!course) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: QuestionBank }>(`${base}/courses/${course.id}/question-banks`, { title: bankTitle.trim(), description: bankDescription.trim() || null })
      await loadQuestionBanks()
      setSelectedBankId(result.data.id); setBankTitle(''); setBankDescription(''); setMessage('Question bank created for this course.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create the question bank.') } finally { setSaving(false) }
  }

  function beginBankSettings(bank: QuestionBank) {
    setBankNameDraft(bank.title)
    setBankDescriptionDraft(bank.description ?? '')
    setBankSettingsOpen(true)
  }

  async function updateQuestionBank(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedBankId) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/question-banks/${selectedBankId}`, { title: bankNameDraft.trim(), description: bankDescriptionDraft.trim() || null })
      await loadQuestionBanks()
      setBankSettingsOpen(false); setMessage('Question bank details updated.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update the question bank.') } finally { setSaving(false) }
  }

  async function deleteQuestionBank() {
    if (!selectedBankId || !window.confirm('Delete this course question bank? Quiz copies will stay available.')) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.delete(`${base}/question-banks/${selectedBankId}`)
      setSelectedBankId(null); setBankSettingsOpen(false)
      await loadQuestionBanks(); setMessage('Question bank deleted. Existing quiz copies remain unchanged.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not delete the question bank.') } finally { setSaving(false) }
  }

  async function addQuestion(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!quiz) return
    const options = optionsText.split('\n').map(option => option.trim()).filter(Boolean)
    setSaving(true); setError(''); setMessage('')
    try {
      const question = { prompt, ...(questionType === 'short_answer' ? {} : { options }), question_type: questionType, difficulty, points: Number(points), sort_order: quiz.questions.length, ...(questionType === 'multiple_select' ? { correct_options: correctOptions } : questionType === 'single_choice' ? { correct_option: Number(correctOption) } : {}) }
      if (saveToBank && selectedBankId) {
        const bankQuestion = await api.post<{ data: BankQuestion }>(`${base}/question-banks/${selectedBankId}/questions`, question)
        await api.post(`${base}/quizzes/${quiz.id}/questions/from-bank`, { question_bank_question_id: bankQuestion.data.id })
        await Promise.all([loadQuizzes(), loadQuestionBanks()])
        setMessage('Question added to the quiz and saved in the course bank.')
      } else {
        await api.post(`${base}/quizzes/${quiz.id}/questions`, question)
        await loadQuizzes(); setMessage('Question saved to the draft quiz.')
      }
      setPrompt(''); setCorrectOptions([]); setSaveToBank(false)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save question.') } finally { setSaving(false) }
  }

  async function addBankQuestion(question: BankQuestion) {
    if (!quiz) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/quizzes/${quiz.id}/questions/from-bank`, { question_bank_question_id: question.id })
      await loadQuizzes(); setMessage('Question copied from the course bank into this quiz draft.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not add this bank question.') } finally { setSaving(false) }
  }

  function beginBankQuestionEdit(question: BankQuestion) {
    setEditingBankQuestion(question.id)
    setBankEditPrompt(question.prompt)
    setBankEditOptions(question.options.join('\n'))
    setBankEditCorrectOptions(question.correct_options)
    setBankEditType(question.question_type)
    setBankEditDifficulty(question.difficulty)
    setBankEditPoints(String(question.points))
  }

  async function updateBankQuestion(event: FormEvent<HTMLFormElement>, questionId: number) {
    event.preventDefault(); if (!selectedBankId) return
    const options = bankEditOptions.split('\n').map(option => option.trim()).filter(Boolean)
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/question-banks/${selectedBankId}/questions/${questionId}`, {
        prompt: bankEditPrompt,
        ...(bankEditType === 'short_answer' ? {} : { options }),
        question_type: bankEditType,
        difficulty: bankEditDifficulty,
        points: Number(bankEditPoints),
        ...(bankEditType === 'multiple_select' ? { correct_options: bankEditCorrectOptions } : bankEditType === 'single_choice' ? { correct_option: bankEditCorrectOptions[0] } : {}),
      })
      setEditingBankQuestion(null)
      await Promise.all([loadQuestionBanks(), loadQuizzes()])
      setMessage('Question bank item updated. Quiz copies keep their saved content.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update this bank question.') } finally { setSaving(false) }
  }

  async function deleteBankQuestion(question: BankQuestion) {
    if (!selectedBankId || !window.confirm('Delete this question from the course bank? Copies already saved in quizzes will remain.')) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.delete(`${base}/question-banks/${selectedBankId}/questions/${question.id}`)
      setEditingBankQuestion(null)
      await Promise.all([loadQuestionBanks(), loadQuizzes()])
      setMessage('Question removed from the course bank.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not delete this bank question.') } finally { setSaving(false) }
  }

  async function publishQuiz() {
    if (!quiz) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/quizzes/${quiz.id}`, { status: 'published' })
      await loadQuizzes(); setMessage('Quiz published. Students can now start an attempt.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish quiz.') } finally { setSaving(false) }
  }

  async function startAttempt() {
    if (!quiz) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: { attempt: ActiveAttempt } }>(`${base}/quizzes/${quiz.id}/attempts`)
      setAttempt(result.data.attempt); setAnswers({}); setMessage('Attempt started. Your answers are scored when submitted.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not start quiz.') } finally { setSaving(false) }
  }

  async function submitAttempt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!quiz || !attempt) return
    setSaving(true); setError(''); setMessage('')
    try {
      const payload = Object.entries(answers).map(([question_id, selected_options]) => {
        const question = quiz.questions.find(item => item.id === Number(question_id))
        if (question?.question_type === 'short_answer') return { question_id: Number(question_id), answer_text: typeof selected_options === 'string' ? selected_options : '' }
        const selected = Array.isArray(selected_options) ? selected_options : []
        return question?.question_type === 'multiple_select'
          ? { question_id: Number(question_id), selected_options: selected }
          : { question_id: Number(question_id), selected_option: selected[0] }
      })
      const result = await api.patch<{ data: AttemptResult }>(`${base}/quizzes/${quiz.id}/attempts/${attempt.id}`, { answers: payload })
      setAttempt(null); setAnswers({}); await loadQuizzes(); setMessage(`Attempt submitted. Score: ${result.data.score} / ${result.data.max_score}.`)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit quiz attempt.') } finally { setSaving(false) }
  }

  function beginQuizReview(result: AttemptResult) {
    setManualReviewAttempt(result)
    setReviewScores(Object.fromEntries(quiz?.questions.filter(question => question.question_type === 'short_answer').map(question => [question.id, String(result.manual_grades?.[question.id]?.awarded_points ?? '')] ) ?? []))
    setReviewFeedback(Object.fromEntries(quiz?.questions.filter(question => question.question_type === 'short_answer').map(question => [question.id, result.manual_grades?.[question.id]?.feedback ?? '']) ?? []))
  }

  async function gradeQuizResponse(questionId: number) {
    if (!quiz || !manualReviewAttempt) return
    const rawScore = reviewScores[questionId]
    if (rawScore === undefined || rawScore.trim() === '' || !Number.isInteger(Number(rawScore))) { setError('Enter a whole number score for this response.'); return }
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.patch<{ data: AttemptResult }>(`${base}/quizzes/${quiz.id}/attempts/${manualReviewAttempt.id}/questions/${questionId}/grade`, { awarded_points: Number(rawScore), feedback: reviewFeedback[questionId]?.trim() || null })
      setManualReviewAttempt(result.data)
      setResults(current => current.map(item => item.id === result.data.id ? { ...item, ...result.data } : item))
      setMessage(result.data.review_status === 'graded' ? 'Written responses reviewed. The final quiz score is updated.' : 'Response reviewed. More written answers are still waiting for review.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not review this response.') } finally { setSaving(false) }
  }

  return <div className="quiz-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {loading ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading courses…</p></section> : courses.length === 0 ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><ClipboardList size={18} />{mode === 'student' ? 'Enroll in a published course to see its quizzes.' : 'Create and publish a course before adding quizzes.'}</p></section> : <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'student' ? 'KNOWLEDGE CHECKS' : 'ASSESSMENT BUILDER'}</span><h2>{mode === 'student' ? 'Tests and quizzes' : 'Course quizzes'}</h2><p className="dash-record-subtitle">{mode === 'student' ? 'Start a quiz attempt and review your submitted scores.' : 'Schedule quizzes with single-choice and multiple-select questions, difficulty tags and server-side scoring.'}</p></div></div>
      <div className="assignment-course-select"><label>Course<select value={courseId ?? ''} onChange={event => { setCourseId(Number(event.target.value)); setAttempt(null); setResultsPage(1) }}>{courses.map(item => <option key={item.id} value={item.id}>{item.title}</option>)}</select></label>{quizzes.length > 0 && <label>Quiz<select value={quizId ?? ''} onChange={event => { setQuizId(Number(event.target.value)); setAttempt(null); setResultsPage(1) }}>{quizzes.map(item => <option key={item.id} value={item.id}>{item.title}{item.status === 'draft' ? ' · Draft' : ''}</option>)}</select></label>}</div>
      {mode === 'manage' && course && <form className="course-form quiz-create" onSubmit={event => void createQuiz(event)}>
        <h3>Create a quiz draft</h3>
        <label>Title<input required minLength={2} maxLength={160} value={title} onChange={event => setTitle(event.target.value)} /></label>
        <label>Instructions<textarea rows={2} maxLength={5000} value={instructions} onChange={event => setInstructions(event.target.value)} /></label>
        <div className="assignment-form-row">
          <label>Time limit in minutes<input type="number" min="1" max="240" value={timeLimit} onChange={event => setTimeLimit(event.target.value)} /></label>
          <label>Allowed attempts<input type="number" min="1" max="5" value={maxAttempts} onChange={event => setMaxAttempts(event.target.value)} required /></label>
        </div>
        <div className="assignment-form-row">
          <label>Opens at (optional)<input type="datetime-local" value={opensAt} onChange={event => setOpensAt(event.target.value)} /></label>
          <label>Closes at (optional)<input type="datetime-local" min={opensAt || undefined} value={closesAt} onChange={event => setClosesAt(event.target.value)} /></label>
        </div>
        <div className="assignment-form-row"><label>Negative marking rate<input type="number" min="0" max="1" step="0.05" value={negativeMarkingRate} onChange={event => setNegativeMarkingRate(event.target.value)} /></label><label>Passing score percentage (optional)<input type="number" min="0" max="100" step="1" value={passPercent} onChange={event => setPassPercent(event.target.value)} placeholder="No pass threshold" /></label></div>
        <p className="quiz-schedule-hint">Times use your device timezone and are stored as UTC. A wrong objective answer deducts its points multiplied by the negative marking rate; unanswered questions are not penalized. Pending written responses do not show a pass/fail result.</p>
        <button className="master-submit" disabled={saving}><Plus size={15} />Create draft quiz</button>
      </form>}
      {mode === 'manage' && course && <section className="quiz-bank-panel" aria-labelledby="question-bank-title">
        <div><h3 id="question-bank-title">Course question bank</h3><p>Reuse questions across this course’s quizzes. Imported questions are copied into a draft, so published assessment content stays fixed.</p></div>
        <form className="quiz-bank-create" onSubmit={event => void createQuestionBank(event)}>
          <label>Bank name<input required minLength={2} maxLength={150} value={bankTitle} onChange={event => setBankTitle(event.target.value)} placeholder="Unit 1 questions" /></label>
          <label>Description<input maxLength={1000} value={bankDescription} onChange={event => setBankDescription(event.target.value)} placeholder="Optional notes for course staff" /></label>
          <button className="master-submit" disabled={saving}><Plus size={14} />Create bank</button>
        </form>
        {questionBanks.length > 0 ? <>
          <div className="quiz-bank-toolbar"><label>Bank<select aria-label="Select course question bank" value={selectedBankId ?? ''} onChange={event => { setSelectedBankId(Number(event.target.value)); setBankPage(1); setBankSettingsOpen(false) }}>{questionBanks.map(bank => <option key={bank.id} value={bank.id}>{bank.title} · {bank.questions_count} questions</option>)}</select></label><label>Search questions<input aria-label="Search bank questions" value={bankSearch} onChange={event => { setBankSearch(event.target.value); setBankPage(1) }} placeholder="Search prompts" /></label><div className="quiz-bank-settings-actions"><button type="button" className="master-action" disabled={saving || !questionBanks.find(bank => bank.id === selectedBankId)} onClick={() => { const bank = questionBanks.find(item => item.id === selectedBankId); if (bank) beginBankSettings(bank) }}>Edit bank</button><button type="button" className="master-action" disabled={saving || !selectedBankId} onClick={() => void deleteQuestionBank()}><Trash2 size={13} />Delete bank</button></div></div>
          {bankSettingsOpen && <form className="quiz-bank-edit-form" onSubmit={event => void updateQuestionBank(event)}><label>Bank name<input required minLength={2} maxLength={150} value={bankNameDraft} onChange={event => setBankNameDraft(event.target.value)} /></label><label>Description<input maxLength={1000} value={bankDescriptionDraft} onChange={event => setBankDescriptionDraft(event.target.value)} /></label><div className="quiz-bank-question-actions"><button className="master-submit" disabled={saving}><Save size={14} />Save bank</button><button className="master-action" type="button" disabled={saving} onClick={() => setBankSettingsOpen(false)}>Cancel</button></div></form>}
          {bankQuestions.length ? <div className="quiz-bank-questions">{bankQuestions.map(question => {
            const added = quiz?.questions.some(item => item.question_bank_question_id === question.id) ?? false
            const options = bankEditOptions.split('\n').map(option => option.trim()).filter(Boolean)
            return <div className="quiz-bank-question-entry" key={question.id}><article><div><b>{question.prompt}</b><small>{question.question_type === 'multiple_select' ? 'Multiple select' : question.question_type === 'short_answer' ? 'Written response' : 'Single choice'} · {question.difficulty} · {question.points} pt{question.points === 1 ? '' : 's'}</small></div><div className="quiz-bank-question-actions"><button type="button" className="master-action" disabled={saving} onClick={() => editingBankQuestion === question.id ? setEditingBankQuestion(null) : beginBankQuestionEdit(question)}><Pencil size={13} />{editingBankQuestion === question.id ? 'Cancel' : 'Edit'}</button><button type="button" className="master-action" disabled={saving} onClick={() => void deleteBankQuestion(question)}><Trash2 size={13} />Remove</button><button type="button" className="master-action" disabled={!quiz || quiz.status !== 'draft' || saving || added} onClick={() => void addBankQuestion(question)}>{added ? 'Added' : 'Add to quiz'}</button></div></article>
              {editingBankQuestion === question.id && <form className="course-form quiz-bank-edit-form" onSubmit={event => void updateBankQuestion(event, question.id)}><label>Prompt<input required minLength={3} maxLength={2000} value={bankEditPrompt} onChange={event => setBankEditPrompt(event.target.value)} /></label>{bankEditType !== 'short_answer' && <label>Answer choices, one per line<textarea required rows={3} value={bankEditOptions} onChange={event => setBankEditOptions(event.target.value)} /></label>}<div className="assignment-form-row"><label>Question type<select value={bankEditType} onChange={event => setBankEditType(event.target.value as Question['question_type'])}><option value="single_choice">Single choice</option><option value="multiple_select">Select all that apply</option><option value="short_answer">Written response</option></select></label><label>Difficulty<select value={bankEditDifficulty} onChange={event => setBankEditDifficulty(event.target.value as Question['difficulty'])}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></label><label>Points<input type="number" min="1" max="1000" value={bankEditPoints} onChange={event => setBankEditPoints(event.target.value)} /></label></div>{bankEditType === 'multiple_select' ? <fieldset className="quiz-correct-options"><legend>Correct answers (choose at least two)</legend>{options.map((option, index) => <label key={`bank-edit-${index}`}><input type="checkbox" checked={bankEditCorrectOptions.includes(index)} onChange={event => setBankEditCorrectOptions(current => event.target.checked ? [...current, index].sort() : current.filter(item => item !== index))} />{index + 1}. {option}</label>)}</fieldset> : bankEditType === 'single_choice' ? <label>Correct answer number<input type="number" min="1" max={options.length} value={(bankEditCorrectOptions[0] ?? 0) + 1} onChange={event => setBankEditCorrectOptions([Number(event.target.value) - 1])} /></label> : <p className="quiz-schedule-hint">The course teacher will review written responses after students submit.</p>}<button className="master-submit" disabled={saving || (bankEditType !== 'short_answer' && options.length < 2) || (bankEditType === 'multiple_select' && bankEditCorrectOptions.length < 2)}><Save size={14} />Save question</button></form>}
            </div>
          })}</div> : <p className="master-empty">{bankSearch.trim() ? 'No bank questions match this search.' : 'This bank has no questions yet.'}</p>}
          {bankLastPage > 1 && <div className="platform-directory-pagination"><span>Page {bankPage} of {bankLastPage} · {bankTotal} questions</span><button className="master-action" type="button" disabled={bankPage <= 1} onClick={() => setBankPage(page => page - 1)}>Previous</button><button className="master-action" type="button" disabled={bankPage >= bankLastPage} onClick={() => setBankPage(page => page + 1)}>Next</button></div>}
        </> : <p className="master-empty">Create a bank to store and reuse course questions.</p>}
      </section>}
      {quiz && <div className="quiz-detail"><div className="course-detail-heading"><div><span className={`course-status ${quiz.status}`}>{quiz.status}</span><h3>{quiz.title}</h3><p>{quiz.instructions || 'No extra instructions.'}{quiz.time_limit_minutes ? ` · ${quiz.time_limit_minutes} minute time limit` : ''} · {quiz.max_attempts} attempt{quiz.max_attempts === 1 ? '' : 's'}{Number(quiz.negative_marking_rate) > 0 ? ` · −${quiz.negative_marking_rate}× points for wrong objective answers` : ''}{quiz.pass_percent !== null ? ` · Pass mark ${quiz.pass_percent}%` : ''}{quiz.opens_at ? ` · Opens ${new Date(quiz.opens_at).toLocaleString()}` : ''}{quiz.closes_at ? ` · Closes ${new Date(quiz.closes_at).toLocaleString()}` : ''}</p></div>{mode === 'manage' && quiz.status === 'draft' && <button className="master-submit" onClick={() => void publishQuiz()} disabled={saving || quiz.questions.length === 0}>Publish quiz</button>}</div>
        {mode === 'manage' && quiz.status === 'draft' && <form className="course-form quiz-question-form" onSubmit={event => void addQuestion(event)}>
          <h4>Add an objective question</h4>
          <label>Prompt<input required minLength={3} maxLength={2000} value={prompt} onChange={event => setPrompt(event.target.value)} /></label>
          {questionType !== 'short_answer' && <label>Answer choices, one per line<textarea rows={4} value={optionsText} onChange={event => setOptionsText(event.target.value)} /></label>}
          <div className="assignment-form-row">
            <label>Question type<select value={questionType} onChange={event => setQuestionType(event.target.value as Question['question_type'])}><option value="single_choice">Single choice</option><option value="multiple_select">Select all that apply</option><option value="short_answer">Written response</option></select></label>
            <label>Difficulty<select value={difficulty} onChange={event => setDifficulty(event.target.value as Question['difficulty'])}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></label>
          </div>
          {questionType === 'single_choice' ? <label>Correct answer number<input type="number" min="1" max={parsedOptions.length} value={Number(correctOption) + 1} onChange={event => setCorrectOption(String(Number(event.target.value) - 1))} /></label> : questionType === 'multiple_select' ? <fieldset className="quiz-correct-options"><legend>Correct answers (choose at least two)</legend>{parsedOptions.map((option, index) => <label key={`correct-${index}`}><input type="checkbox" checked={correctOptions.includes(index)} onChange={event => setCorrectOptions(current => event.target.checked ? [...current, index].sort() : current.filter(item => item !== index))} />{index + 1}. {option}</label>)}</fieldset> : <p className="quiz-schedule-hint">Written responses are marked by a course teacher after submission.</p>}
          {selectedBankId !== null && <label className="quiz-save-bank"><input type="checkbox" checked={saveToBank} onChange={event => setSaveToBank(event.target.checked)} />Save this question in the selected course bank for reuse</label>}
          <label>Points<input type="number" min="1" max="1000" value={points} onChange={event => setPoints(event.target.value)} /></label>
          <button className="master-submit" disabled={saving || (questionType !== 'short_answer' && parsedOptions.length < 2) || (questionType === 'multiple_select' && correctOptions.length < 2)}><Plus size={15} />Add question</button>
        </form>}
        {mode === 'manage' && quiz.questions.length > 0 && <div className="quiz-question-list"><h4>{quiz.questions.length} question{quiz.questions.length === 1 ? '' : 's'}</h4>{quiz.questions.map((question, index) => <article key={question.id}><b>{index + 1}. {question.prompt}</b><small>{question.question_type === 'multiple_select' ? 'Select all that apply' : question.question_type === 'short_answer' ? 'Written response · teacher reviewed' : 'Single choice'} · {question.difficulty} · {question.points} pt{question.points === 1 ? '' : 's'}</small>{question.question_type !== 'short_answer' && <ol>{question.options.map((option, optionIndex) => <li key={`${question.id}-${optionIndex}`}>{option}{(question.correct_options ?? [question.correct_option]).includes(optionIndex) && <strong> · Answer</strong>}</li>)}</ol>}</article>)}</div>}
        {mode === 'student' && !attempt && <div className="quiz-start"><p>{quiz.latest_result?.submitted_at ? `Latest result: ${quiz.latest_result.score} / ${quiz.latest_result.max_score}${quiz.latest_result.result_outcome === 'pending_review' ? ' · Pending review' : quiz.latest_result.result_outcome === 'passed' ? ' · Passed' : quiz.latest_result.result_outcome === 'failed' ? ' · Not passed' : ''}` : `Attempts used: ${quiz.attempts_used ?? 0} / ${quiz.max_attempts}`}</p><button className="master-submit" onClick={() => void startAttempt()} disabled={saving || (quiz.attempts_used ?? 0) >= quiz.max_attempts || (quiz.opens_at !== null && Date.now() < Date.parse(quiz.opens_at)) || (quiz.closes_at !== null && Date.now() >= Date.parse(quiz.closes_at))}><Play size={15} />Start attempt</button></div>}
        {mode === 'student' && attempt && <form className="quiz-attempt-form" onSubmit={event => void submitAttempt(event)}><p>Attempt {attempt.attempt_number}{quiz.time_limit_minutes ? ` · Time limit ${quiz.time_limit_minutes} minutes` : ''}</p>{quiz.questions.map((question, index) => <fieldset key={question.id}><legend>{index + 1}. {question.prompt} <small>({question.points} pts · {question.difficulty})</small></legend>{question.question_type === 'short_answer' ? <><p className="quiz-select-hint">Written response · reviewed by your course teacher.</p><textarea required maxLength={10000} value={typeof answers[question.id] === 'string' ? answers[question.id] as string : ''} onChange={event => setAnswers(current => ({ ...current, [question.id]: event.target.value }))} /></> : <>{question.question_type === 'multiple_select' && <p className="quiz-select-hint">Select all that apply.</p>}{question.options.map((option, optionIndex) => <label key={`${question.id}-${optionIndex}`}><input type={question.question_type === 'multiple_select' ? 'checkbox' : 'radio'} name={`question-${question.id}`} checked={Array.isArray(answers[question.id]) && (answers[question.id] as number[]).includes(optionIndex)} onChange={event => setAnswers(current => {
          const selected = current[question.id] ?? []
          const next = question.question_type === 'multiple_select'
            ? (event.target.checked ? [...(Array.isArray(selected) ? selected : []), optionIndex].sort() : (Array.isArray(selected) ? selected : []).filter(item => item !== optionIndex))
            : [optionIndex]
          return { ...current, [question.id]: next }
        })} />{option}</label>)}</>}</fieldset>)}<button className="master-submit" disabled={saving}><Save size={15} />Submit answers</button></form>}
        {mode === 'student' && quiz.status === 'published' && <div className="quiz-results"><h4>Your submitted results</h4>{results.length ? <><div className="dash-table-scroll"><table><thead><tr><th>ATTEMPT</th><th>SCORE</th><th>REVIEW</th><th>SUBMITTED</th></tr></thead><tbody>{results.map(result => <tr key={result.id}><td>{result.attempt_number}</td><td>{result.score} / {result.max_score}{result.result_outcome === 'passed' ? ' · Passed' : result.result_outcome === 'failed' ? ' · Not passed' : ''}</td><td>{result.review_status === 'pending' || result.review_status === 'partial' ? 'Awaiting teacher review' : result.review_status === 'graded' ? 'Reviewed' : 'Complete'}</td><td>{result.submitted_at ? new Date(result.submitted_at).toLocaleString() : '—'}</td></tr>)}</tbody></table></div>{results.some(result => result.review_status === 'graded') && <div className="quiz-review-feedback">{results.filter(result => result.review_status === 'graded').flatMap(result => Object.entries(result.manual_grades ?? {}).map(([questionId, grade]) => <p key={`${result.id}-${questionId}`}><b>Question feedback:</b> {grade.feedback || 'No feedback provided.'} · {grade.awarded_points} points</p>))}</div>}{resultsLastPage > 1 && <div className="platform-directory-pagination"><span>Page {resultsPage} of {resultsLastPage} · {resultsTotal} results</span><button className="master-action" type="button" aria-label="Previous result page" disabled={resultsPage <= 1} onClick={() => setResultsPage(page => page - 1)}>Previous</button><button className="master-action" type="button" aria-label="Next result page" disabled={resultsPage >= resultsLastPage} onClick={() => setResultsPage(page => page + 1)}>Next</button></div>}</> : <p className="master-empty">No attempts have been submitted yet.</p>}</div>}
        {mode === 'manage' && quiz.status === 'published' && <div className="quiz-results">
          <h4>Submitted results</h4>
          {results.length ? <>
            <div className="dash-table-scroll"><table><thead><tr><th>STUDENT</th><th>ATTEMPT</th><th>SCORE</th><th>REVIEW</th><th>SUBMITTED</th><th></th></tr></thead><tbody>
              {results.map(result => <tr key={result.id}>
                <td>{result.student?.name} · {result.student?.admission_number}</td><td>{result.attempt_number}</td><td>{result.score} / {result.max_score}{result.result_outcome === 'passed' ? ' · Passed' : result.result_outcome === 'failed' ? ' · Not passed' : ''}</td>
                <td>{result.review_status === 'pending' ? 'Needs review' : result.review_status === 'partial' ? 'Partially reviewed' : result.review_status === 'graded' ? 'Reviewed' : 'Complete'}</td>
                <td>{result.submitted_at ? new Date(result.submitted_at).toLocaleString() : '—'}</td>
                <td>{quiz.questions.some(question => question.question_type === 'short_answer') && <button className="master-action" type="button" onClick={() => beginQuizReview(result)}>Review responses</button>}</td>
              </tr>)}
            </tbody></table></div>
            {manualReviewAttempt && <div className="quiz-review-panel">
              <div className="quiz-review-heading"><h4>Written responses · {manualReviewAttempt.student?.name}</h4><button type="button" className="master-action" onClick={() => setManualReviewAttempt(null)}>Close review</button></div>
              {quiz.questions.filter(question => question.question_type === 'short_answer').map(question => {
                const answer = manualReviewAttempt.answers?.[question.id]
                const grade = manualReviewAttempt.manual_grades?.[question.id]
                return <article key={question.id}>
                  <b>{question.prompt} · {question.points} points</b>
                  <p>{typeof answer === 'string' && answer ? answer : 'No response submitted.'}</p>
                  {typeof answer === 'string' && answer.trim() && <>
                    <label>Score<input type="number" min="0" max={question.points} step="1" value={reviewScores[question.id] ?? ''} onChange={event => setReviewScores(current => ({ ...current, [question.id]: event.target.value }))} /></label>
                    <label>Feedback<textarea rows={3} maxLength={2000} value={reviewFeedback[question.id] ?? ''} onChange={event => setReviewFeedback(current => ({ ...current, [question.id]: event.target.value }))} /></label>
                    <button type="button" className="master-submit" disabled={saving} onClick={() => void gradeQuizResponse(question.id)}><Save size={14} />{grade ? 'Update review' : 'Save review'}</button>
                  </>}
                </article>
              })}
            </div>}
            {resultsLastPage > 1 && <div className="platform-directory-pagination"><span>Page {resultsPage} of {resultsLastPage} · {resultsTotal} results</span><button className="master-action" type="button" aria-label="Previous result page" disabled={resultsPage <= 1} onClick={() => setResultsPage(page => page - 1)}>Previous</button><button className="master-action" type="button" aria-label="Next result page" disabled={resultsPage >= resultsLastPage} onClick={() => setResultsPage(page => page + 1)}>Next</button></div>}
          </> : <p className="master-empty">No quiz attempts have been submitted.</p>}
        </div>}
      </div>}
    </section>}
  </div>
}
