import { useState } from 'react'
import { AlertCircle, Check, FileSpreadsheet, LoaderCircle, RefreshCw, Upload } from 'lucide-react'
import { api } from '../lib/api'
import { parseCsv } from '../lib/csv'
import './RosterReconciliation.css'

type Branch = { id: number; name: string; is_active: boolean }
type AcademicClass = { id: number; name: string; organization_branch_id: number | null }
type AcademicSession = { id: number; name: string }
type AcademicSection = { id: number; name: string; academic_session_id: number | null }
type Person = {
  id: number
  name: string
  admission_number?: string
  employee_number?: string | null
  email?: string | null
  phone?: string | null
  specialization?: string | null
  status: string
  organization_branch_id?: number | null
  branch?: Branch | null
  academic_class_id?: number | null
  academic_class?: AcademicClass | null
  academic_section_id?: number | null
  academic_section?: AcademicSection | null
  academic_session_id?: number | null
  academic_session?: AcademicSession | null
  enrolled_on?: string | null
}
type Paged<T> = { data: T[]; meta?: { current_page: number; last_page: number } }
type Change = { label: string; current: string; incoming: string; payload: Record<string, string | number | null>; selected: boolean }
type PreviewRow = { line: number; key: string; person: string; personId?: number; status: 'matched' | 'missing' | 'duplicate'; issues: string[]; changes: Change[] }
type Props = { organizationId: number; kind: 'students' | 'teachers'; onApplied: () => void }

const MAX_FILE_BYTES = 2 * 1024 * 1024
const MAX_DATA_ROWS = 500

function normalize(value: string) { return value.trim().toLocaleLowerCase() }
function show(value: string | number | null | undefined) { return value === null || value === undefined || value === '' ? '—' : String(value) }
function decodeCsvCell(value: string) {
  if (!value.startsWith("'")) return value
  const original = value.slice(1)
  return '=+-@'.includes(original.trimStart().charAt(0)) ? original : value
}
function validIsoDate(value: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
}

async function fetchAll<T>(endpoint: string): Promise<T[]> {
  const query = new URLSearchParams({ per_page: '100', page: '1' })
  const first = await api.get<Paged<T>>(`${endpoint}?${query}`)
  const values = [...first.data]
  const lastPage = first.meta?.last_page ?? 1
  for (let page = 2; page <= lastPage; page += 1) {
    query.set('page', String(page))
    const next = await api.get<Paged<T>>(`${endpoint}?${query}`)
    if (next.meta && next.meta.last_page !== lastPage) throw new Error('The roster changed during comparison. Refresh it and try again.')
    values.push(...next.data)
  }
  return values
}

function valueAt(row: string[], headers: Map<string, number>, label: string) {
  const index = headers.get(normalize(label))
  return index === undefined ? undefined : decodeCsvCell((row[index] ?? '').trim())
}

export default function RosterReconciliation({ organizationId, kind, onApplied }: Props) {
  const [rows, setRows] = useState<PreviewRow[]>([])
  const [sourceCsv, setSourceCsv] = useState('')
  const [fileName, setFileName] = useState('')
  const [busy, setBusy] = useState(false)
  const [applying, setApplying] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const base = `/api/v1/organizations/${organizationId}`
  const rosterEndpoint = `${base}/${kind}`
  const student = kind === 'students'
  const keyLabel = student ? 'Admission number' : 'Employee number'

  async function sectionsFor(classId: number, cache: Map<number, AcademicSection[]>) {
    const existing = cache.get(classId)
    if (existing) return existing
    const result = await fetchAll<AcademicSection>(`${base}/classes/${classId}/sections`)
    cache.set(classId, result)
    return result
  }

  async function buildPreview(csvText: string) {
    const parsed = parseCsv(csvText)
    if (parsed.length < 2) throw new Error('The CSV must include a header row and at least one roster record.')
    if (parsed.length - 1 > MAX_DATA_ROWS) throw new Error(`Compare up to ${MAX_DATA_ROWS} CSV rows at a time.`)

    const rawHeaders = parsed[0].map(header => header.trim())
    const headers = new Map<string, number>()
    rawHeaders.forEach((header, index) => {
      const key = normalize(header)
      if (!key) throw new Error('The CSV contains an empty column heading.')
      if (headers.has(key)) throw new Error(`The CSV contains a duplicate “${header}” column.`)
      headers.set(key, index)
    })
    if (!headers.has(normalize(keyLabel))) throw new Error(`The CSV must include the “${keyLabel}” column.`)

    setBusy(true)
    setError('')
    setNotice('')
    try {
      const [people, branches, classes, sessions] = await Promise.all([
        fetchAll<Person>(rosterEndpoint),
        fetchAll<Branch>(`${base}/branches`),
        student ? fetchAll<AcademicClass>(`${base}/classes`) : Promise.resolve([] as AcademicClass[]),
        student ? fetchAll<AcademicSession>(`${base}/academic-sessions`) : Promise.resolve([] as AcademicSession[]),
      ])
      const peopleByKey = new Map<string, Person>()
      people.forEach(person => {
        const key = normalize(student ? person.admission_number ?? '' : person.employee_number ?? '')
        if (key) peopleByKey.set(key, person)
      })
      const dataRows = parsed.slice(1)
      const keys = dataRows.map(row => normalize(valueAt(row, headers, keyLabel) ?? ''))
      const counts = new Map<string, number>()
      keys.forEach(key => { if (key) counts.set(key, (counts.get(key) ?? 0) + 1) })
      const sectionCache = new Map<number, AcademicSection[]>()
      const preview: PreviewRow[] = []

      for (let index = 0; index < dataRows.length; index += 1) {
        const csvRow = dataRows[index]
        const key = keys[index]
        const person = peopleByKey.get(key)
        const duplicate = Boolean(key && (counts.get(key) ?? 0) > 1)
        const issues: string[] = []
        const changes: Change[] = []
        if (csvRow.length !== rawHeaders.length) issues.push(`This row has ${csvRow.length} values; expected ${rawHeaders.length}.`)
        const addChange = (label: string, current: string, incoming: string, payload: Record<string, string | number | null>, selected = true) => {
          if (current !== incoming) changes.push({ label, current: show(current), incoming: show(incoming), payload, selected })
        }

        if (!key) issues.push(`Missing ${keyLabel.toLocaleLowerCase()}.`)
        if (duplicate) issues.push(`Duplicate ${keyLabel.toLocaleLowerCase()} in this file; neither row will be applied.`)
        if (!person) {
          preview.push({ line: index + 2, key: valueAt(csvRow, headers, keyLabel) ?? '', person: valueAt(csvRow, headers, student ? 'Student name' : 'Teacher name') ?? 'CSV row', status: duplicate ? 'duplicate' : 'missing', issues, changes })
          continue
        }

        const importedName = valueAt(csvRow, headers, student ? 'Student name' : 'Teacher name')
        if (importedName !== undefined) {
          if (!importedName) issues.push('Name cannot be blank.')
          else addChange('Name', person.name, importedName, { name: importedName })
        }
        const importedEmail = valueAt(csvRow, headers, 'Email')
        if (importedEmail !== undefined) {
          if (importedEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(importedEmail)) issues.push('Email address is not valid.')
          else addChange('Email', person.email ?? '', importedEmail, { email: importedEmail || null })
        }
        const importedPhone = valueAt(csvRow, headers, 'Phone')
        if (importedPhone !== undefined) {
          if (importedPhone && !/^[+0-9() .-]{7,24}$/.test(importedPhone)) issues.push('Phone must contain 7–24 supported characters.')
          else addChange('Phone', person.phone ?? '', importedPhone, { phone: importedPhone || null })
        }
        const importedStatus = valueAt(csvRow, headers, 'Status')
        if (importedStatus !== undefined) {
          const statuses = student ? ['active', 'inactive', 'graduated', 'withdrawn'] : ['active', 'inactive', 'on_leave']
          if (!statuses.includes(importedStatus)) issues.push('Status value is not supported.')
          else addChange('Status', person.status, importedStatus, { status: importedStatus }, false)
        }

        if (student) {
          const enrolled = valueAt(csvRow, headers, 'Enrollment date')
          if (enrolled !== undefined) {
            const validDate = !enrolled || validIsoDate(enrolled)
            if (!validDate) issues.push('Enrollment date must use YYYY-MM-DD.')
            else addChange('Enrollment date', person.enrolled_on?.slice(0, 10) ?? '', enrolled, { enrolled_on: enrolled || null })
          }

          const branchValue = valueAt(csvRow, headers, 'Branch')
          const classValue = valueAt(csvRow, headers, 'Class')
          const sectionValue = valueAt(csvRow, headers, 'Section')
          const sessionValue = valueAt(csvRow, headers, 'Academic session')
          if ([branchValue, classValue, sectionValue, sessionValue].some(value => value !== undefined)) {
            const placementPayload: Record<string, string | number | null> = {}
            const currentPlacement: string[] = []
            const importedPlacement: string[] = []
            let placementValid = true
            let branchId = person.organization_branch_id ?? null
            let classId = person.academic_class_id ?? null
            let sessionId = person.academic_session_id ?? null

            if (branchValue !== undefined) {
              currentPlacement.push(`Branch: ${show(person.branch?.name)}`)
              importedPlacement.push(`Branch: ${show(branchValue)}`)
              if (branchValue === (person.branch?.name ?? '')) branchId = person.organization_branch_id ?? null
              else if (!branchValue) branchId = null
              else {
                const matches = branches.filter(branch => normalize(branch.name) === normalize(branchValue) && branch.is_active)
                if (matches.length !== 1) { issues.push(matches.length ? `Branch “${branchValue}” is ambiguous.` : `Active branch “${branchValue}” was not found.`); placementValid = false }
                else branchId = matches[0].id
              }
              placementPayload.organization_branch_id = branchId
            }

            if (classValue !== undefined) {
              currentPlacement.push(`Class: ${show(person.academic_class?.name)}`)
              importedPlacement.push(`Class: ${show(classValue)}`)
              if (classValue === (person.academic_class?.name ?? '')) classId = person.academic_class_id ?? null
              else if (!classValue) classId = null
              else {
                let matches = classes.filter(item => normalize(item.name) === normalize(classValue))
                if (branchValue !== undefined && branchId !== null) matches = matches.filter(item => item.organization_branch_id === branchId)
                else if (branchValue === '') matches = matches.filter(item => item.organization_branch_id === null)
                if (matches.length !== 1) { issues.push(matches.length ? `Class “${classValue}” is ambiguous for this branch.` : `Class “${classValue}” was not found for this branch.`); placementValid = false }
                else classId = matches[0].id
              }
              placementPayload.academic_class_id = classId
            }

            if (sessionValue !== undefined) {
              currentPlacement.push(`Academic session: ${show(person.academic_session?.name)}`)
              importedPlacement.push(`Academic session: ${show(sessionValue)}`)
              if (sessionValue === (person.academic_session?.name ?? '')) sessionId = person.academic_session_id ?? null
              else if (!sessionValue) sessionId = null
              else {
                const matches = sessions.filter(item => normalize(item.name) === normalize(sessionValue))
                if (matches.length !== 1) { issues.push(matches.length ? `Academic session “${sessionValue}” is ambiguous.` : `Academic session “${sessionValue}” was not found.`); placementValid = false }
                else sessionId = matches[0].id
              }
              placementPayload.academic_session_id = sessionId
            }

            if (sectionValue !== undefined) {
              currentPlacement.push(`Section: ${show(person.academic_section?.name)}`)
              importedPlacement.push(`Section: ${show(sectionValue)}`)
              if (!sectionValue) placementPayload.academic_section_id = null
              else if (!classId) { issues.push('A section requires a matching class.'); placementValid = false }
              else {
                const sectionList = await sectionsFor(classId, sectionCache)
                const existing = sectionValue === (person.academic_section?.name ?? '') && classId === person.academic_class_id && sessionId === person.academic_session_id
                  ? person.academic_section_id
                  : undefined
                const matches = existing
                  ? sectionList.filter(item => item.id === existing)
                  : sectionList.filter(item => normalize(item.name) === normalize(sectionValue) && (sessionId === null || item.academic_session_id === sessionId))
                if (matches.length !== 1) { issues.push(matches.length ? `Section “${sectionValue}” is ambiguous for this class/session.` : `Section “${sectionValue}” was not found for this class/session.`); placementValid = false }
                else placementPayload.academic_section_id = matches[0].id
              }
            }

            if (placementValid && importedPlacement.some((value, placementIndex) => value !== currentPlacement[placementIndex])) {
              changes.push({ label: 'Academic placement', current: currentPlacement.join(' · '), incoming: importedPlacement.join(' · '), payload: placementPayload, selected: true })
            }
          }
        } else {
          const specialization = valueAt(csvRow, headers, 'Specialization')
          if (specialization !== undefined) addChange('Specialization', person.specialization ?? '', specialization, { specialization: specialization || null })
          const branchValue = valueAt(csvRow, headers, 'Branch')
          if (branchValue !== undefined) {
            const currentBranch = person.branch?.name ?? ''
            if (branchValue !== currentBranch) {
              const matches = branchValue ? branches.filter(branch => normalize(branch.name) === normalize(branchValue) && branch.is_active) : []
              if (branchValue && matches.length !== 1) issues.push(matches.length ? `Branch “${branchValue}” is ambiguous.` : `Active branch “${branchValue}” was not found.`)
              else addChange('Branch', currentBranch, branchValue, { organization_branch_id: matches[0]?.id ?? null })
            }
          }
        }

        preview.push({ line: index + 2, key: valueAt(csvRow, headers, keyLabel) ?? '', person: person.name, personId: person.id, status: duplicate ? 'duplicate' : 'matched', issues, changes })
      }

      setRows(preview)
      setSourceCsv(csvText)
      const changed = preview.filter(row => row.status === 'matched' && row.issues.length === 0 && row.changes.length > 0).length
      const unmatched = preview.filter(row => row.status === 'missing').length
      const duplicates = preview.filter(row => row.status === 'duplicate').length
      setNotice(`Compared ${preview.length} CSV rows: ${changed} with changes, ${unmatched} unmatched, ${duplicates} duplicated.`)
    } catch (reason) {
      setRows([])
      setSourceCsv('')
      setError(reason instanceof Error ? reason.message : 'Could not compare this roster file.')
    } finally {
      setBusy(false)
    }
  }

  async function readFile(file: File | undefined) {
    if (!file) return
    setRows([])
    setSourceCsv('')
    setError('')
    setNotice('')
    setFileName('')
    if (file.size > MAX_FILE_BYTES) { setError('Choose a CSV file smaller than 2 MB.'); return }
    if (!file.name.toLocaleLowerCase().endsWith('.csv')) { setError('Choose a .csv file.'); return }
    setFileName(file.name)
    try {
      await buildPreview(await file.text())
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not read this CSV file.')
    }
  }

  function toggleChange(rowIndex: number, changeIndex: number) {
    setRows(current => current.map((row, index) => index !== rowIndex ? row : {
      ...row,
      changes: row.changes.map((change, innerIndex) => innerIndex === changeIndex ? { ...change, selected: !change.selected } : change),
    }))
  }

  async function applySelected() {
    const selected = rows.flatMap(row => row.status === 'matched' && row.issues.length === 0
      ? [{ row, changes: row.changes.filter(change => change.selected) }]
      : []).filter(item => item.changes.length > 0)
    if (!selected.length) return
    setApplying(true)
    setError('')
    setNotice('Applying selected roster updates…')
    let applied = 0
    const failures: string[] = []
    try {
      for (let index = 0; index < selected.length; index += 5) {
        const batch = selected.slice(index, index + 5)
        const results = await Promise.allSettled(batch.map(({ row, changes }) => {
          if (!row.personId) throw new Error(`No organization record matches ${row.key}.`)
          return api.patch(`${rosterEndpoint}/${row.personId}`, Object.assign({}, ...changes.map(change => change.payload)))
        }))
        results.forEach((result, resultIndex) => {
          if (result.status === 'fulfilled') applied += 1
          else failures.push(`${batch[resultIndex].row.person}: ${result.reason instanceof Error ? result.reason.message : 'Update failed.'}`)
        })
      }
      if (applied) onApplied()
      await buildPreview(sourceCsv)
      setNotice(`Applied ${applied} record update${applied === 1 ? '' : 's'}; ${failures.length} failed. ${failures[0] ?? ''}`.trim())
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not refresh the reconciliation preview.')
    } finally {
      setApplying(false)
    }
  }

  const applyCount = rows.filter(row => row.status === 'matched' && row.issues.length === 0 && row.changes.some(change => change.selected)).length

  return <section className="roster-reconciliation" aria-labelledby="roster-reconciliation-title">
    <div className="roster-reconciliation-heading"><div><span className="master-record-icon"><FileSpreadsheet size={16} /></span><span><h3 id="roster-reconciliation-title">CSV roster reconciliation</h3><small>Compare by {student ? 'admission' : 'employee'} number, review field changes, then apply selected updates.</small></span></div>
      <label className="dash-secondary-button roster-upload"><Upload size={14} />{busy ? 'Comparing…' : 'Choose CSV'}<input type="file" accept=".csv,text/csv" disabled={busy || applying} onChange={event => { void readFile(event.target.files?.[0]); event.currentTarget.value = '' }} /></label>
    </div>
    <p className="roster-reconciliation-note">Up to 500 rows and 2 MB. Unmatched, duplicate, and malformed rows are skipped. Formula-safety prefixes from edTechLive exports are decoded. This tool updates matched records only; it never creates or deletes roster records.</p>
    {fileName && <p className="roster-file-name">Current file: {fileName}</p>}
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={15} />{error}</div>}
    {notice && <div className="master-feedback master-success" role="status" aria-live="polite"><Check size={15} />{notice}</div>}
    {busy && <p className="master-empty"><LoaderCircle className="master-spin" size={16} />Loading organization records and comparing fields…</p>}
    {rows.length > 0 && <>
      <ul className="roster-reconciliation-rows">{rows.map((row, rowIndex) => <li key={`${row.line}-${row.key}`} className={row.issues.length ? 'has-issues' : ''}>
        <div className="roster-reconciliation-row-heading"><span><b>{row.person}</b><small>{keyLabel}: {row.key || 'missing'} · CSV row {row.line}</small></span><span className={`roster-reconciliation-state ${row.status}`}>{row.status === 'matched' ? row.issues.length ? 'Review required' : row.changes.length ? `${row.changes.length} change${row.changes.length === 1 ? '' : 's'}` : 'No changes' : row.status === 'duplicate' ? 'Duplicate' : 'Unmatched'}</span></div>
        {row.issues.map(issue => <p className="roster-reconciliation-issue" key={issue}><AlertCircle size={13} />{issue}</p>)}
        {row.changes.length > 0 && <ul className="roster-reconciliation-changes">{row.changes.map((change, changeIndex) => <li key={change.label}>
          <label><input type="checkbox" checked={change.selected} disabled={row.status !== 'matched' || row.issues.length > 0 || applying} onChange={() => toggleChange(rowIndex, changeIndex)} /><span><b>{change.label}</b><small>Current: {change.current}</small><small>CSV: {change.incoming}</small></span></label>
        </li>)}</ul>}
      </li>)}</ul>
      <div className="roster-reconciliation-actions"><span>{applyCount} record{applyCount === 1 ? '' : 's'} selected · status changes require explicit selection</span><button className="master-submit" type="button" disabled={busy || applying || applyCount === 0} onClick={() => void applySelected()}>{applying ? <><LoaderCircle className="master-spin" size={15} />Applying…</> : <><RefreshCw size={14} />Apply selected updates</>}</button></div>
    </>}
  </section>
}
