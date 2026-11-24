import { useCallback, useEffect, useState } from 'react'
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { ApiError, mobileApi, type OrganizationMembership } from './api'

type Role = OrganizationMembership['role']
type Status = 'active' | 'suspended'
type Permission = { key: string; label: string; description: string }
type Member = {
  id: number
  user: { id: number; name: string; email: string }
  role: Role
  status: Status
  permissions: { effective: string[]; overrides: string[]; editable: boolean }
}
type MemberDraft = { role: Role; status: Status; permissions: string[] }
type AccessAudit = {
  id: number
  actor: string | null
  subject: string | null
  event_type: 'membership' | 'permissions' | 'membership_and_permissions'
  previous_role: Role | null
  role: Role | null
  previous_status: Status | null
  status: Status | null
  previous_permissions: string[] | null
  permissions: string[] | null
  created_at: string
}
type MembershipResponse = {
  data: Member[]
  meta: { current_page: number; last_page: number; total: number }
  permission_catalog: Permission[]
  can_manage_memberships: boolean
  can_manage_permissions: boolean
}

const roles: Role[] = ['admin', 'management', 'independent_educator', 'teacher', 'student', 'parent']
const roleLabels: Record<Role, string> = {
  admin: 'Admin', management: 'Management', independent_educator: 'Independent educator',
  teacher: 'Teacher', student: 'Student', parent: 'Parent / guardian',
}
const roleRank: Record<Role, number> = { admin: 100, independent_educator: 100, management: 50, teacher: 30, student: 10, parent: 10 }
const isPrivileged = (role: Role) => role === 'admin' || role === 'independent_educator'
const permissionReadDependencies: Record<string, string> = {
  'organization.memberships.manage': 'organization.memberships.read',
  'organization.memberships.audit': 'organization.memberships.read',
  'organization.finance.manage': 'organization.finance.read',
}
const permissionWriteDependencies: Record<string, string[]> = {
  'organization.memberships.read': ['organization.memberships.manage', 'organization.memberships.audit'],
  'organization.finance.read': ['organization.finance.manage'],
}

function updatePermissions(current: string[], permission: string, enabled: boolean): string[] {
  if (!enabled) {
    return current.filter(key => key !== permission && !(permissionWriteDependencies[permission] ?? []).includes(key))
  }
  const requiredRead = permissionReadDependencies[permission]
  return [...new Set([...current, permission, ...(requiredRead ? [requiredRead] : [])])]
}

export function MembershipAccessPanel({ organizationId, token, userId }: { organizationId: number; token: string; userId: number }) {
  const [members, setMembers] = useState<Member[]>([])
  const [catalog, setCatalog] = useState<Permission[]>([])
  const [audit, setAudit] = useState<AccessAudit[]>([])
  const [canManage, setCanManage] = useState(false)
  const [canManagePermissions, setCanManagePermissions] = useState(false)
  const [canReviewAudit, setCanReviewAudit] = useState(false)
  const [drafts, setDrafts] = useState<Record<number, MemberDraft>>({})
  const [searchDraft, setSearchDraft] = useState('')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<Status | ''>('active')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [expandedMember, setExpandedMember] = useState<number | null>(null)
  const [loading, setLoading] = useState(false)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const query = new URLSearchParams({ page: String(page), per_page: '20' })
      if (search) query.set('search', search)
      if (statusFilter) query.set('status', statusFilter)
      const base = `/api/v1/organizations/${organizationId}`
      const result = await mobileApi.get<MembershipResponse>(`${base}/memberships?${query}`, token)
      setMembers(result.data)
      setCatalog(result.permission_catalog)
      setCanManage(result.can_manage_memberships)
      setCanManagePermissions(result.can_manage_permissions)
      setDrafts(Object.fromEntries(result.data.map(member => [member.id, {
        role: member.role, status: member.status, permissions: member.permissions.overrides,
      }])))
      setLastPage(result.meta.last_page)
      setTotal(result.meta.total)
      try {
        const history = await mobileApi.get<{ data: AccessAudit[] }>(`${base}/membership-access-audit?per_page=6`, token)
        setAudit(history.data)
        setCanReviewAudit(true)
      } catch (reason) {
        if (reason instanceof ApiError && reason.status === 403) {
          setAudit([])
          setCanReviewAudit(false)
        } else throw reason
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load organization members.')
    } finally { setLoading(false) }
  }, [organizationId, page, search, statusFilter, token])

  useEffect(() => { void load() }, [load])

  async function save(member: Member) {
    const draft = drafts[member.id]
    if (!draft) return
    const changes: { role?: Role; status?: Status; permissions?: string[] } = {}
    if (draft.role !== member.role) changes.role = draft.role
    if (draft.status !== member.status) changes.status = draft.status
    const permissionsChanged = JSON.stringify([...draft.permissions].sort()) !== JSON.stringify([...member.permissions.overrides].sort())
    if (permissionsChanged && canManagePermissions && member.permissions.editable && draft.status === 'active' && !isPrivileged(draft.role)) {
      changes.permissions = draft.permissions
    }
    if (Object.keys(changes).length === 0) return
    setSavingId(member.id); setError(''); setMessage('')
    try {
      await mobileApi.patch(`/api/v1/organizations/${organizationId}/memberships/${member.id}`, token, changes)
      setMessage(`Access updated for ${member.user.name}.`)
      await load()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update this membership.')
    } finally { setSavingId(null) }
  }

  function requestSave(member: Member) {
    const draft = drafts[member.id]
    if (!draft) return
    if (member.status !== 'suspended' && draft.status === 'suspended') {
      Alert.alert('Suspend workspace access?', `${member.user.name} will lose access to this organization.`, [
        { text: 'Keep active', style: 'cancel' },
        { text: 'Suspend access', style: 'destructive', onPress: () => { void save(member) } },
      ])
    } else void save(member)
  }

  return <View style={styles.panel}>
    <View style={styles.heading}>
      <View style={{ flex: 1 }}><Text style={styles.eyebrow}>ORGANIZATION ACCESS</Text><Text style={styles.title}>Members and roles</Text><Text style={styles.muted}>Role and status changes are recorded in this workspace’s access history. You can’t change your own access.</Text></View>
      <Pressable onPress={() => void load()} disabled={loading} accessibilityRole="button" accessibilityLabel="Refresh members"><Text style={styles.link}>Refresh</Text></Pressable>
    </View>
    {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
    {message ? <Text accessibilityLiveRegion="polite" style={styles.success}>{message}</Text> : null}
    <View style={styles.searchRow}>
      <TextInput value={searchDraft} onChangeText={setSearchDraft} onSubmitEditing={() => { setPage(1); setSearch(searchDraft.trim()) }} placeholder="Search name or email" returnKeyType="search" style={[styles.input, { flex: 1 }]} accessibilityLabel="Search organization members" />
      <Pressable style={styles.secondaryButton} onPress={() => { setPage(1); setSearch(searchDraft.trim()) }} accessibilityRole="button"><Text style={styles.secondaryText}>Search</Text></Pressable>
    </View>
    <View style={styles.choices}>
      {([{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }, { value: '', label: 'All' }] as const).map(item => {
        const selected = statusFilter === item.value
        return <Pressable key={item.label} onPress={() => { setPage(1); setStatusFilter(item.value) }} style={[styles.choice, selected && styles.choiceSelected]} accessibilityRole="radio" accessibilityState={{ selected }}><Text style={[styles.choiceText, selected && styles.choiceSelectedText]}>{item.label}</Text></Pressable>
      })}
    </View>
    {loading && members.length === 0 ? <View style={styles.loading}><ActivityIndicator /><Text style={styles.muted}>Loading members…</Text></View> : members.length === 0 ? <Text style={styles.empty}>No members match this filter.</Text> : members.map(member => {
      const draft = drafts[member.id] ?? { role: member.role, status: member.status, permissions: member.permissions.overrides }
      const isSelf = member.user.id === userId
      const delegatedPrivilegedTarget = !canManagePermissions && isPrivileged(member.role)
      const mayEditMembership = canManage && !isSelf && !delegatedPrivilegedTarget
      const allowedRoles = roles.filter(role => canManagePermissions || (roleRank[role] <= roleRank[member.role] && (role !== 'parent' || member.role === 'parent')))
      const roleDirty = draft.role !== member.role
      const statusDirty = draft.status !== member.status
      const permissionsDirty = JSON.stringify([...draft.permissions].sort()) !== JSON.stringify([...member.permissions.overrides].sort())
      const dirty = roleDirty || statusDirty || (permissionsDirty && canManagePermissions && member.permissions.editable && draft.status === 'active' && !isPrivileged(draft.role))
      const expanded = expandedMember === member.id
      return <View style={styles.memberCard} key={member.id}>
        <Pressable style={styles.memberHeading} onPress={() => setExpandedMember(expanded ? null : member.id)} accessibilityRole="button" accessibilityState={{ expanded }}>
          <View style={{ flex: 1 }}><Text style={styles.memberName}>{member.user.name}{isSelf ? ' · You' : ''}</Text><Text style={styles.muted}>{member.user.email}</Text><Text style={styles.meta}>{roleLabels[member.role]} · {member.status} · {member.permissions.effective.length} effective permissions</Text></View><Text style={styles.link}>{expanded ? 'Close' : 'Access'}</Text>
        </Pressable>
        {expanded ? <View style={styles.editor}>
          {mayEditMembership ? <>
            <Text style={styles.label}>Role</Text><View style={styles.choices}>{allowedRoles.map(role => {
              const selected = draft.role === role
              return <Pressable key={role} onPress={() => setDrafts(current => ({ ...current, [member.id]: { ...draft, role } }))} style={[styles.choice, selected && styles.choiceSelected]} accessibilityRole="radio" accessibilityState={{ selected }}><Text style={[styles.choiceText, selected && styles.choiceSelectedText]}>{roleLabels[role]}</Text></Pressable>
            })}</View>
            <Text style={styles.label}>Workspace access</Text><View style={styles.choices}>{(['active', 'suspended'] as const).map(status => {
              const selected = draft.status === status
              return <Pressable key={status} onPress={() => setDrafts(current => ({ ...current, [member.id]: { ...draft, status } }))} style={[styles.choice, selected && styles.choiceSelected]} accessibilityRole="radio" accessibilityState={{ selected }}><Text style={[styles.choiceText, selected && styles.choiceSelectedText]}>{status === 'active' ? 'Active' : 'Suspended'}</Text></Pressable>
            })}</View>
          </> : <Text style={styles.muted}>{isSelf ? 'Your own membership can’t be changed here.' : delegatedPrivilegedTarget ? 'Delegated managers can’t change administrator access.' : 'You can review this member but do not have permission to change access.'}</Text>}
          {canManagePermissions && member.permissions.editable ? <>
            <Text style={styles.label}>Additional permissions</Text><Text style={styles.muted}>These permissions apply only to this organization’s membership and finance workflows.</Text>
            {catalog.map(permission => {
              const checked = draft.permissions.includes(permission.key)
              const disabled = savingId === member.id || draft.status === 'suspended' || isPrivileged(draft.role)
              return <Pressable key={permission.key} onPress={() => {
                const next = updatePermissions(draft.permissions, permission.key, !checked)
                setDrafts(current => ({ ...current, [member.id]: { ...draft, permissions: [...new Set(next)] } }))
              }} disabled={disabled} style={styles.permissionRow} accessibilityRole="checkbox" accessibilityState={{ checked, disabled }}>
                <Text style={styles.checkbox}>{checked ? '☑' : '☐'}</Text><View style={{ flex: 1 }}><Text style={styles.memberName}>{permission.label}</Text><Text style={styles.muted}>{permission.description}</Text></View>
              </Pressable>
            })}
          </> : null}
          {mayEditMembership || (canManagePermissions && member.permissions.editable) ? <Pressable onPress={() => requestSave(member)} disabled={!dirty || savingId === member.id} style={[styles.saveButton, (!dirty || savingId === member.id) && styles.disabled]} accessibilityRole="button"><Text style={styles.saveText}>{savingId === member.id ? 'Saving…' : 'Save access changes'}</Text></Pressable> : null}
        </View> : null}
      </View>
    })}
    {lastPage > 1 ? <View style={styles.pager}><Pressable onPress={() => setPage(value => Math.max(1, value - 1))} disabled={page <= 1 || loading} accessibilityRole="button"><Text style={styles.link}>Previous</Text></Pressable><Text style={styles.meta}>Page {page} of {lastPage} · {total} members</Text><Pressable onPress={() => setPage(value => Math.min(lastPage, value + 1))} disabled={page >= lastPage || loading} accessibilityRole="button"><Text style={styles.link}>Next</Text></Pressable></View> : null}
    <Text style={styles.label}>Recent access history</Text>
    {!canReviewAudit ? <Text style={styles.empty}>Your membership does not include access history.</Text> : audit.length === 0 ? <Text style={styles.empty}>No access changes have been recorded.</Text> : audit.map(item => {
      const permissionEvent = item.event_type !== 'membership'
      const detail = permissionEvent
        ? `Permissions: ${(item.previous_permissions ?? []).join(', ') || 'none'} → ${(item.permissions ?? []).join(', ') || 'none'}`
        : `${roleLabels[item.previous_role ?? 'admin']} → ${roleLabels[item.role ?? 'admin']} · ${item.previous_status ?? 'active'} → ${item.status ?? 'active'}`
      return <Text key={item.id} style={styles.auditItem}>{item.subject ?? 'Member'} · {item.actor ?? 'Workspace administrator'} · {detail} · {new Date(item.created_at).toLocaleDateString()}</Text>
    })}
  </View>
}

const styles = StyleSheet.create({
  panel: { marginTop: 22, gap: 10, padding: 14, borderRadius: 12, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e2eaf2' },
  heading: { flexDirection: 'row', gap: 10, alignItems: 'flex-start' },
  eyebrow: { color: '#697f9b', fontSize: 10, fontWeight: '800', letterSpacing: 1.2 },
  title: { color: '#183654', fontSize: 18, fontWeight: '800', marginTop: 4 },
  muted: { color: '#617892', fontSize: 12, lineHeight: 18 },
  error: { color: '#a42331', backgroundColor: '#fff0f1', borderRadius: 8, padding: 10, fontSize: 13 },
  success: { color: '#226648', backgroundColor: '#eef8f1', borderRadius: 8, padding: 10, fontSize: 13 },
  searchRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  input: { minHeight: 44, borderWidth: 1, borderColor: '#d9e3ed', borderRadius: 8, paddingHorizontal: 10, color: '#173653', backgroundColor: '#fff' },
  secondaryButton: { minHeight: 44, justifyContent: 'center', borderRadius: 8, paddingHorizontal: 12, backgroundColor: '#eaf1fa' },
  secondaryText: { color: '#2c61c2', fontSize: 13, fontWeight: '800' },
  choices: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  choice: { minHeight: 38, justifyContent: 'center', borderWidth: 1, borderColor: '#dbe5ef', borderRadius: 8, paddingHorizontal: 10, backgroundColor: '#fff' },
  choiceSelected: { backgroundColor: '#eaf2ff', borderColor: '#7aa7e8' },
  choiceText: { color: '#395674', fontSize: 12, fontWeight: '700' },
  choiceSelectedText: { color: '#2359aa' },
  loading: { minHeight: 70, flexDirection: 'row', alignItems: 'center', gap: 10 },
  empty: { color: '#657b93', fontSize: 13, paddingVertical: 8 },
  memberCard: { borderWidth: 1, borderColor: '#e6edf4', borderRadius: 10, backgroundColor: '#fbfcfe', overflow: 'hidden' },
  memberHeading: { minHeight: 64, flexDirection: 'row', alignItems: 'center', gap: 10, padding: 11 },
  memberName: { color: '#193857', fontSize: 13, lineHeight: 18, fontWeight: '800' },
  meta: { color: '#667d96', fontSize: 11, lineHeight: 16 },
  link: { color: '#2c61c2', fontSize: 12, fontWeight: '800' },
  editor: { gap: 8, padding: 11, borderTopWidth: 1, borderTopColor: '#e6edf4' },
  label: { color: '#526b85', fontSize: 12, fontWeight: '800', marginTop: 5 },
  permissionRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 8, paddingVertical: 8 },
  checkbox: { width: 24, color: '#2c61c2', fontSize: 18, fontWeight: '700' },
  saveButton: { minHeight: 44, alignItems: 'center', justifyContent: 'center', backgroundColor: '#2c61c2', borderRadius: 9, marginTop: 5 },
  saveText: { color: '#fff', fontSize: 13, fontWeight: '800' },
  disabled: { opacity: 0.5 },
  pager: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 8 },
  auditItem: { color: '#546d86', fontSize: 11, lineHeight: 17, paddingVertical: 6, borderTopWidth: 1, borderTopColor: '#eef2f6' },
})
