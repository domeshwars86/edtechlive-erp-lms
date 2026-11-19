import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, Check, LoaderCircle, Search, ShieldCheck, UserRoundCog } from 'lucide-react'
import { useAuth } from '../auth/useAuth'
import { api, ApiError } from '../lib/api'
import './MembershipAccess.css'

type Role = 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'independent_educator'
type Status = 'active' | 'suspended'
type Permission = { key: string; label: string; description: string }
type MemberPermissions = { effective: string[]; overrides: string[]; editable: boolean }
type Member = { id: number; user: { id: number; name: string; email: string }; role: Role; status: Status; accepted_at: string | null; permissions: MemberPermissions }
type Audit = { id: number; actor: string | null; subject: string | null; event_type?: 'membership' | 'permissions' | 'membership_and_permissions'; previous_role?: Role; role?: Role; previous_status?: Status; status?: Status; previous_permissions?: string[] | null; permissions?: string[] | null; created_at: string }
type Page = { current_page: number; last_page: number; total: number }
type Value = { role: Role; status: Status; permissions: string[] }
const roleLabels: Record<Role, string> = { admin: 'Administrator', management: 'Management', teacher: 'Teacher', student: 'Student', parent: 'Parent or guardian', independent_educator: 'Independent educator' }
const roleRank: Record<Role, number> = { admin: 100, independent_educator: 100, management: 50, teacher: 30, student: 10, parent: 10 }
const privilegedRole = (role: Role) => role === 'admin' || role === 'independent_educator'

export default function MembershipAccess({ organizationId }: { organizationId: number }) {
  const { user } = useAuth()
  const base = `/api/v1/organizations/${organizationId}`
  const [members, setMembers] = useState<Member[]>([])
  const [audit, setAudit] = useState<Audit[]>([])
  const [permissionCatalog, setPermissionCatalog] = useState<Permission[]>([])
  const [canManageMemberships, setCanManageMemberships] = useState(false)
  const [canManagePermissions, setCanManagePermissions] = useState(false)
  const [canReviewAudit, setCanReviewAudit] = useState(false)
  const [selectedPermissionMember, setSelectedPermissionMember] = useState<number | null>(null)
  const [drafts, setDrafts] = useState<Record<number, Value>>({})
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('active')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<Page>({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const query = new URLSearchParams({ page: String(page), per_page: '25' })
      if (search) query.set('search', search)
      if (roleFilter) query.set('role', roleFilter)
      if (statusFilter) query.set('status', statusFilter)
      const [result, auditResult] = await Promise.all([
        api.get<{ data: Member[]; meta: Page; permission_catalog: Permission[]; can_manage_memberships: boolean; can_manage_permissions: boolean }>(`${base}/memberships?${query}`),
        api.get<{ data: Audit[] }>(`${base}/membership-access-audit?per_page=8`).catch(reason => {
          if (reason instanceof ApiError && reason.status === 403) return null
          throw reason
        }),
      ])
      setMembers(result.data)
      setMeta(result.meta)
      setPermissionCatalog(result.permission_catalog)
      setCanManageMemberships(result.can_manage_memberships)
      setCanManagePermissions(result.can_manage_permissions)
      setDrafts(Object.fromEntries(result.data.map(member => [member.id, { role: member.role, status: member.status, permissions: member.permissions.overrides }])))
      setAudit(auditResult?.data ?? [])
      setCanReviewAudit(auditResult !== null)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load organization access.') } finally { setLoading(false) }
  }, [base, page, roleFilter, search, statusFilter])

  useEffect(() => { void load() }, [load])

  async function save(member: Member) {
    const draft = drafts[member.id]
    if (!draft) return
    const changes: { role?: Role; status?: Status; permissions?: string[] } = {}
    if (draft.role !== member.role) changes.role = draft.role
    if (draft.status !== member.status) changes.status = draft.status
    if (JSON.stringify([...draft.permissions].sort()) !== JSON.stringify([...member.permissions.overrides].sort())) changes.permissions = draft.permissions
    if (Object.keys(changes).length === 0) return
    setSavingId(member.id); setError(''); setMessage('')
    try {
      await api.patch(`${base}/memberships/${member.id}`, changes)
      setMessage(`Access updated for ${member.user.name}.`)
      await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update this membership.') } finally { setSavingId(null) }
  }

  return <div className="membership-access">
    <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ORGANIZATION ACCESS</span><h2>Members and roles</h2><p className="dash-record-subtitle">Manage active workspace roles and suspend or restore access. New users should be added through Invitations.</p></div><ShieldCheck size={22} /></div>
      <div className="membership-security-note"><UserRoundCog size={17} /><span>Changes are audited. Your own role cannot be changed here, and each organization must retain an active administrator or independent educator.</span></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
      <div className="membership-toolbar"><form className="batch-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()) }}><label><Search size={15} /><input aria-label="Search members by name or email" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Search name or email" /></label><button type="submit">Search</button></form><label>Role<select value={roleFilter} onChange={event => { setRoleFilter(event.target.value); setPage(1) }}><option value="">All roles</option>{Object.entries(roleLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><label>Access status<select value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1) }}><option value="active">Active</option><option value="suspended">Suspended</option><option value="">All statuses</option></select></label></div>
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading members…</p> : members.length === 0 ? <p className="master-empty"><ShieldCheck size={18} />No members match these filters.</p> : <div className="dash-table-scroll membership-table"><table><thead><tr><th>MEMBER</th><th>CURRENT ROLE</th><th>ACCESS</th><th>UPDATE ROLE</th><th>UPDATE ACCESS</th><th>PERMISSIONS</th><th>ACTION</th></tr></thead><tbody>{members.map(member => { const draft = drafts[member.id] ?? { role: member.role, status: member.status, permissions: member.permissions.overrides }; const isSelf = member.user.id === user?.id; const delegatedPrivilegedTarget = !canManagePermissions && privilegedRole(member.role); const canEditMembership = canManageMemberships && !isSelf && !delegatedPrivilegedTarget; const assignableRoles = Object.entries(roleLabels).filter(([role]) => canManagePermissions || roleRank[role as Role] <= roleRank[member.role]); const roleStatusDirty = draft.role !== member.role || draft.status !== member.status; const permissionsDirty = JSON.stringify([...draft.permissions].sort()) !== JSON.stringify([...member.permissions.overrides].sort()); const dirty = roleStatusDirty || permissionsDirty; return <tr key={member.id}><td><b>{member.user.name}</b><small>{member.user.email}</small></td><td>{roleLabels[member.role]}</td><td><span className={`master-status ${member.status === 'active' ? 'active' : ''}`}>{member.status}</span></td><td><select aria-label={`Role for ${member.user.name}`} value={draft.role} disabled={!canEditMembership || savingId === member.id} onChange={event => setDrafts(current => ({ ...current, [member.id]: { ...draft, role: event.target.value as Role } }))}>{assignableRoles.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></td><td><select aria-label={`Access status for ${member.user.name}`} value={draft.status} disabled={!canEditMembership || savingId === member.id} onChange={event => setDrafts(current => ({ ...current, [member.id]: { ...draft, status: event.target.value as Status } }))}><option value="active">Active</option><option value="suspended">Suspended</option></select></td><td><div className="membership-permissions-cell"><span>{member.permissions.effective.length ? `${member.permissions.effective.length} effective` : 'No grants'}</span>{canManagePermissions && member.permissions.editable && <button type="button" className="master-record-action" aria-expanded={selectedPermissionMember === member.id} onClick={() => setSelectedPermissionMember(current => current === member.id ? null : member.id)}>{selectedPermissionMember === member.id ? 'Close' : 'Edit'}</button>}</div></td><td><button type="button" className="master-record-action" disabled={!canEditMembership || !dirty || savingId === member.id} onClick={() => void save(member)}>{savingId === member.id ? 'Saving…' : isSelf ? 'Your account' : 'Save'}</button></td></tr> })}</tbody></table></div>}
      {selectedPermissionMember !== null && (() => { const member = members.find(item => item.id === selectedPermissionMember); const draft = member ? drafts[member.id] : null; return member && draft ? <section className="membership-permission-editor" aria-labelledby="membership-permission-heading"><div><span className="dash-panel-kicker">ROLE CAPABILITIES</span><h3 id="membership-permission-heading">Additional permissions for {member.user.name}</h3><p>Permissions apply only within this organization. Only an administrator or independent educator can grant or remove these catalog entries. Managing memberships or viewing access history also grants member-list access.</p></div><div className="membership-permission-options">{permissionCatalog.map(permission => <label key={permission.key}><input type="checkbox" checked={draft.permissions.includes(permission.key)} disabled={!member.permissions.editable || savingId === member.id} onChange={event => { const enabled = event.target.checked; const dependent = ['organization.memberships.manage', 'organization.memberships.audit']; const permissions = permission.key === 'organization.memberships.read' && !enabled ? draft.permissions.filter(key => key !== permission.key && !dependent.includes(key)) : permission.key === 'organization.memberships.read' ? [...draft.permissions, permission.key] : enabled ? [...draft.permissions, permission.key, 'organization.memberships.read'] : draft.permissions.filter(key => key !== permission.key); setDrafts(current => ({ ...current, [member.id]: { ...draft, permissions: [...new Set(permissions)] } })) }} /><span><b>{permission.label}</b><small>{permission.description}</small></span></label>)}</div><button type="button" className="master-submit" disabled={!canManagePermissions || !member.permissions.editable || savingId === member.id || JSON.stringify([...draft.permissions].sort()) === JSON.stringify([...member.permissions.overrides].sort())} onClick={() => void save(member)}>{savingId === member.id ? 'Saving…' : 'Save permissions'}</button></section> : null })()}
      {meta.last_page > 1 && <div className="dash-table-footer"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {page} of {meta.last_page} · {meta.total} members</span><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage(value => value + 1)}>Next</button></div>}
    </section>
    <section className="dash-panel dash-masters-panel membership-audit"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACCESS HISTORY</span><h2>Recent access changes</h2><p className="dash-record-subtitle">A record of who changed roles, membership status or delegated permissions.</p></div></div>{!canReviewAudit ? <p className="master-empty">You can view members, but your workspace role does not include access history.</p> : audit.length === 0 ? <p className="master-empty">No role, access or permission changes have been recorded.</p> : <ul>{audit.map(item => { const permissionChange = item.event_type === 'permissions' || item.event_type === 'membership_and_permissions'; const detail = permissionChange ? `Permissions: ${(item.previous_permissions ?? []).join(', ') || 'none'} → ${(item.permissions ?? []).join(', ') || 'none'}` : `${roleLabels[item.previous_role ?? 'admin']} → ${roleLabels[item.role ?? 'admin']} · ${item.previous_status ?? 'active'} → ${item.status ?? 'active'}`; return <li key={item.id}><span><b>{item.subject ?? 'Member'}</b><small>Changed by {item.actor ?? 'workspace administrator'} · {detail}</small></span><time>{new Date(item.created_at).toLocaleString()}</time></li> })}</ul>}</section>
  </div>
}
