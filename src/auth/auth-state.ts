import { createContext } from 'react'
import type { ApiUser, OrganizationMembership } from '../lib/api'

export type AuthContextValue = {
  user: ApiUser | null
  loading: boolean
  activeMembership: OrganizationMembership | null
  refresh: () => Promise<ApiUser | null>
  updateUser: (user: ApiUser) => void
  signIn: (email: string, password: string, remember?: boolean) => Promise<ApiUser>
  signOut: () => Promise<void>
  selectOrganization: (membership: OrganizationMembership) => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
