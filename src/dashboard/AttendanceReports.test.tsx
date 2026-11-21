import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import AttendanceReports from './AttendanceReports'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, get: vi.fn() } }
})

describe('academic report landing tab', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockImplementation(async <T,>(path: string) => {
      if (path.endsWith('/reports/course-progress')) return {
        data: [], summary: { courses: 0, learners: 0, average_percent: 0 },
      } as T
      return { data: {
        from: '2026-11-01', to: '2026-11-21',
        totals: { records: 0, present: 0, late: 0, absent: 0, excused: 0, attendance_rate: 0 },
        sections: [],
      } } as T
    })
  })

  it('opens course analytics on the course progress report', async () => {
    render(<AttendanceReports organizationId={19} initialReport="courses" />)

    expect(screen.getByRole('button', { name: 'Course progress' })).toHaveAttribute('aria-pressed', 'true')
    expect(await screen.findByRole('heading', { name: 'Course progress' })).toBeVisible()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/v1/organizations/19/reports/course-progress'))
  })
})
