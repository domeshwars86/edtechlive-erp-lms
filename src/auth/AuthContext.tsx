import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiUser, type OrganizationListResponse, type OrganizationMembership } from '../lib/api'
import { AuthContext } from './auth-state'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<ApiUser | null>(null)
  const [loading, setLoading] = useState(true)
  const [activeMembership, setActiveMembership] = useState<OrganizationMembership | null>(null)

  const refresh = useCallback(async () => {
    try {
      const response = await api.get<{ data: ApiUser }>('/api/v1/me')
      setUser(response.data)
      const memberships = await api.get<OrganizationListResponse>('/api/v1/organizations?per_page=100')
      const preferred = memberships.preferred_organization_id
        ? memberships.data.find((membership) => membership.organization.id === memberships.preferred_organization_id)
        : undefined
      setActiveMembership(preferred ?? null)
      return response.data
    } catch {
      setUser(null)
      setActiveMembership(null)
      return null
    } finally {
      setLoading(false)
    }
  }, [])

  const updateUser = useCallback((currentUser: ApiUser) => setUser(currentUser), [])

  useEffect(() => { void refresh() }, [refresh])

  const signIn = useCallback(async (email: string, password: string, remember = false) => {
    await api.post('/api/v1/auth/login', { email, password, remember })
    const currentUser = await refresh()
    if (!currentUser) throw new Error('Your session could not be confirmed. Please sign in again.')
    return currentUser
  }, [refresh])

  const signOut = useCallback(async () => {
    try {
      await api.post('/api/v1/auth/logout')
    } finally {
      setUser(null)
      setActiveMembership(null)
    }
  }, [])

  const selectOrganization = useCallback(async (membership: OrganizationMembership) => {
    const response = await api.patch<{ data: OrganizationMembership }>('/api/v1/me/organization', {
      organization_id: membership.organization.id,
    })
    setActiveMembership(response.data)
  }, [])

  const value = useMemo(() => ({
    user, loading, activeMembership, refresh, updateUser, signIn, signOut, selectOrganization,
  }), [user, loading, activeMembership, refresh, updateUser, signIn, signOut, selectOrganization])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
