import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import TimetableManager from './TimetableManager'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, get: vi.fn(), post: vi.fn(), patch: vi.fn() } }
})

const entry = {
  id: 51,
  academic_session_id: 7,
  academic_section_id: 31,
  weekday: 0,
  starts_at: '09:00:00',
  ends_at: '10:00:00',
  room: 'Room 4',
  meeting_url: null,
  is_active: true,
  academic_section: { id: 31, name: 'A', academic_class: { id: 15, name: 'Grade 8' } },
  subject: { id: 23, name: 'Mathematics' },
  teacher: { id: 19, name: 'Asha Rao' },
}

describe('timetable entry editing', () => {
  let currentEntry: typeof entry

  beforeEach(() => {
    currentEntry = { ...entry }
    vi.mocked(api.get).mockImplementation(async <T,>(path: string) => {
      if (path.endsWith('/academic-sessions?per_page=100')) return { data: [{ id: 7, name: '2026–2027', is_current: true }] } as T
      if (path.endsWith('/classes?per_page=100')) return { data: [{ id: 15, name: 'Grade 8' }] } as T
      if (path.endsWith('/classes/15/sections?per_page=100')) return { data: [{ id: 31, name: 'A', academic_session_id: 7, is_active: true }] } as T
      if (path.endsWith('/subjects?per_page=100')) return { data: [{ id: 23, name: 'Mathematics', is_active: true }] } as T
      if (path.endsWith('/teachers?per_page=100')) return { data: [{ id: 19, name: 'Asha Rao', status: 'active' }] } as T
      if (path.includes('/timetable?')) return { data: [currentEntry] } as T
      return { data: [] } as T
    })
    vi.mocked(api.patch).mockImplementation(async (_path, payload) => {
      currentEntry = { ...currentEntry, ...(payload as Partial<typeof entry>), starts_at: `${(payload as { starts_at: string }).starts_at}:00`, ends_at: `${(payload as { ends_at: string }).ends_at}:00` }
      return { data: currentEntry }
    })
  })

  it('prefills an existing class time and saves edits through the timetable update endpoint', async () => {
    const user = userEvent.setup()
    render(<TimetableManager organizationId={4} />)

    await user.click(await screen.findByRole('button', { name: 'Edit Monday Mathematics timetable entry' }))
    expect(screen.getByRole('heading', { name: 'Edit class time' })).toBeVisible()
    expect(screen.getByLabelText('Room (optional)')).toHaveValue('Room 4')
    fireEvent.change(screen.getByLabelText('Starts'), { target: { value: '10:00' } })
    fireEvent.change(screen.getByLabelText('Ends'), { target: { value: '11:00' } })
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/api/v1/organizations/4/timetable/51', expect.objectContaining({
      academic_session_id: 7,
      academic_section_id: 31,
      subject_id: 23,
      teacher_id: 19,
      weekday: 0,
      starts_at: '10:00',
      ends_at: '11:00',
      room: 'Room 4',
      meeting_url: null,
    })))
    expect(await screen.findByRole('status')).toHaveTextContent('Timetable entry updated.')
    expect(await screen.findByText(/Monday · 10:00–11:00 · Mathematics/)).toBeVisible()
    expect(screen.getByRole('heading', { name: 'Add class time' })).toBeVisible()
  })

  it('keeps the edit form open and shows API conflict errors', async () => {
    const user = userEvent.setup()
    vi.mocked(api.patch).mockRejectedValueOnce(new Error('This timetable entry overlaps another scheduled class.'))
    render(<TimetableManager organizationId={4} />)

    await user.click(await screen.findByRole('button', { name: 'Edit Monday Mathematics timetable entry' }))
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This timetable entry overlaps another scheduled class.')
    expect(screen.getByRole('heading', { name: 'Edit class time' })).toBeVisible()
  })
})
