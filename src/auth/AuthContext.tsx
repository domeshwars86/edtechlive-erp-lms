import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiUser, type OrganizationMembership } from '../lib/api'
import { AuthContext } from './auth-state'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<ApiUser | null>(null)
  const [loading, setLoading] = useState(true)
  const [activeMembership, setActiveMembership] = useState<OrganizationMembership | null>(null)

  const refresh = useCallback(async () => {
    try {
      const response = await api.get<{ data: ApiUser }>('/api/v1/me')
      setUser(response.data)
      return response.data
    } catch {
      setUser(null)
      setActiveMembership(null)
      return null
    } finally {
      setLoading(false)
    }
  }, [])

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

  const value = useMemo(() => ({
    user, loading, activeMembership, refresh, signIn, signOut, selectOrganization: setActiveMembership,
  }), [user, loading, activeMembership, refresh, signIn, signOut])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
