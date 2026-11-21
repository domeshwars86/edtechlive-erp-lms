import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import NotificationInbox from './NotificationInbox'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()

  return {
    ...original,
    api: {
      ...original.api,
      get: vi.fn(),
      post: vi.fn(),
    },
  }
})

describe('NotificationInbox', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({
      data: [{
        id: 'notice-1',
        type: 'learning_assignment_published',
        title: 'New assignment available',
        body: 'Earth Systems: Prepare a food web',
        resource_type: 'course_assignment',
        resource_id: 42,
        read_at: null,
        created_at: '2026-11-21T10:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, total: 1, unread: 1 },
    })
    vi.mocked(api.post).mockResolvedValue({ data: { read_at: '2026-11-21T10:01:00Z' } })
  })

  it('marks a learning notice read before opening the learning area', async () => {
    const user = userEvent.setup()
    const onOpenLearning = vi.fn()

    render(<NotificationInbox organizationId={7} onOpenLearning={onOpenLearning} />)
    await user.click(await screen.findByRole('button', { name: 'Open learning' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/v1/organizations/7/notifications/notice-1/read'))
    expect(onOpenLearning).toHaveBeenCalledOnce()
    expect(screen.getByText(/0 unread\./)).toBeVisible()
  })

  it('does not offer a learning destination for non-learning or unlinked notices', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: [{
        id: 'notice-2', type: 'announcement', title: 'School update', body: 'New term dates',
        resource_type: null, resource_id: null, read_at: null, created_at: '2026-11-21T10:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, total: 1, unread: 1 },
    })

    render(<NotificationInbox organizationId={7} onOpenLearning={vi.fn()} />)

    expect(await screen.findByText('School update')).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Open learning' })).not.toBeInTheDocument()
  })
})
