import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import PlatformSystemReadiness from './PlatformSystemReadiness'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, get: vi.fn() } }
})

describe('platform system readiness', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({ data: {
      services: [
        { key: 'email', name: 'Email delivery', status: 'local_only', detail: 'Local mail only.' },
        { key: 'google_meet', name: 'Google Meet', status: 'optional', detail: 'Optional room creation.' },
      ],
      security_note: 'Configuration signals only. Credentials are never returned.',
    } } as never)
  })

  it('loads the live readiness report and identifies configuration limits', async () => {
    render(<PlatformSystemReadiness />)

    expect(await screen.findByRole('heading', { name: 'Integration readiness' })).toBeVisible()
    expect(await screen.findByText('Email delivery')).toBeVisible()
    expect(screen.getByText('Local only')).toBeVisible()
    expect(screen.getByText('Optional · off')).toBeVisible()
    expect(screen.getByText('Configuration signals only. Credentials are never returned.')).toBeVisible()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/v1/platform/system-readiness'))
  })
})
