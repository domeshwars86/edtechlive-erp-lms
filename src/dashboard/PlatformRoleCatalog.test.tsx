import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import PlatformRoleCatalog from './PlatformRoleCatalog'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, get: vi.fn() } }
})

describe('platform role catalog', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({ data: {
      platform_role: { key: 'super_admin', name: 'Super Admin', scope: 'Platform operations', description: 'Allowlisted platform operations.' },
      organization_roles: [
        { key: 'admin', name: 'Admin', scope: 'Organization membership', description: 'Organization administration.', active_memberships: 2, suspended_memberships: 0 },
        { key: 'parent', name: 'Parent', scope: 'Organization membership', description: 'Linked children only.', active_memberships: 4, suspended_memberships: 1 },
      ],
      access_model: { editable_here: false, summary: 'Fixed role catalog; custom role policies are not available.' },
    } } as never)
  })

  it('shows the live built-in roles and membership totals without implying custom roles', async () => {
    render(<PlatformRoleCatalog />)

    expect(await screen.findByRole('heading', { name: 'Built-in roles and access scope' })).toBeVisible()
    expect(await screen.findByText('Super Admin')).toBeVisible()
    expect(screen.getByText('6 active memberships')).toBeVisible()
    expect(screen.getByText('4 active')).toBeVisible()
    expect(screen.getByText('Fixed role catalog; custom role policies are not available.')).toBeVisible()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/v1/platform/roles'))
  })
})
