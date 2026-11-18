import { useState } from 'react'
import { GraduationCap, UsersRound } from 'lucide-react'
import PeopleRoster from './PeopleRoster'

type Kind = 'students' | 'teachers'

export default function ManagementPeople({ organizationId }: { organizationId: number }) {
  const [kind, setKind] = useState<Kind>('students')

  return <div className="management-people-workspace">
    <div className="management-people-tabs" role="tablist" aria-label="Choose a roster">
      <button type="button" role="tab" aria-selected={kind === 'students'} className={kind === 'students' ? 'management-people-tab active' : 'management-people-tab'} onClick={() => setKind('students')}><UsersRound size={15} /> Students</button>
      <button type="button" role="tab" aria-selected={kind === 'teachers'} className={kind === 'teachers' ? 'management-people-tab active' : 'management-people-tab'} onClick={() => setKind('teachers')}><GraduationCap size={15} /> Teachers</button>
    </div>
    <PeopleRoster key={kind} organizationId={organizationId} kind={kind} />
  </div>
}
