import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import CourseWorkspace from './CourseWorkspace'

vi.mock('../auth/useAuth', () => ({ useAuth: () => ({ activeMembership: null }) }))
vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, get: vi.fn(), post: vi.fn() } }
})

const course = {
  id: 8, title: 'Foundations of Science', slug: 'science', level: 'Grade 6', description: null,
  status: 'published' as const, is_public_listing: false, subject: { id: 3, name: 'Science' }, teacher: null,
}
const lesson = {
  id: 55, title: 'The Water Cycle', sort_order: 1,
  content: 'The sun warms surface water. Evaporation turns liquid water into water vapor.',
  external_url: null, status: 'published' as const, module: null,
}

describe('student course study help', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockImplementation(async <T,>(path: string) => {
      if (path.endsWith('/ai/config')) return { enabled: true, model: 'student-test-model', daily_limit: 10,
        usage: { requests_30_days: 0, prompt_tokens_30_days: 0, completion_tokens_30_days: 0, requests_today: 0 } } as T
      if (path.endsWith('/courses?per_page=100')) return { data: [course] } as T
      if (path.endsWith('/courses/8/lessons')) return { data: [lesson] } as T
      if (path.endsWith('/courses/8/progress')) return { data: [], summary: { completed: 0, total: 1, percent: 0 } } as T
      return { data: [] } as T
    })
    vi.mocked(api.post).mockResolvedValue({ data: {
      answer: 'The sun warms water, which evaporates into vapor.', model: 'student-test-model',
      usage: { prompt_tokens: 70, completion_tokens: 18 }, review_required: false,
    } })
  })

  it('shows the optional helper and sends a selected published lesson with the question', async () => {
    const user = userEvent.setup()
    render(<CourseWorkspace organizationId={19} mode="student" />)

    expect(await screen.findByRole('heading', { name: 'Study with your course lessons' })).toBeVisible()
    await screen.findByRole('option', { name: 'The Water Cycle' })
    await user.selectOptions(screen.getByLabelText('Lesson context'), '55')
    await user.type(screen.getByRole('textbox', { name: 'Question' }), 'How does sunlight start the water cycle?')
    await user.click(screen.getByRole('button', { name: 'Ask about this course' }))

    expect(await screen.findByText('The sun warms water, which evaporates into vapor.')).toBeVisible()
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/v1/organizations/19/ai/courses/8/student-question', {
      question: 'How does sunlight start the water cycle?', language: 'English', lesson_id: 55,
    }))
    expect(screen.getByText('1 of 10 daily requests used. Questions, lesson text and answers are not stored by edTechLive.')).toBeVisible()
  })

  it('explains when study help is unavailable after opening its dashboard shortcut', async () => {
    vi.mocked(api.get).mockImplementation(async <T,>(path: string) => {
      if (path.endsWith('/ai/config')) return { enabled: false, model: null, daily_limit: 10,
        usage: { requests_30_days: 0, prompt_tokens_30_days: 0, completion_tokens_30_days: 0, requests_today: 0 } } as T
      if (path.endsWith('/courses?per_page=100')) return { data: [course] } as T
      if (path.endsWith('/courses/8/lessons')) return { data: [lesson] } as T
      if (path.endsWith('/courses/8/progress')) return { data: [], summary: { completed: 0, total: 1, percent: 0 } } as T
      return { data: [] } as T
    })
    render(<CourseWorkspace organizationId={19} mode="student" focusStudentAiHelp />)

    const heading = await screen.findByRole('heading', { name: 'Study with your course lessons' })
    expect(heading).toHaveFocus()
    expect(screen.getByText('Study help is not enabled for this workspace yet. Ask your school administrator for details.')).toBeVisible()
    expect(screen.queryByRole('textbox', { name: 'Question' })).not.toBeInTheDocument()
  })
})
