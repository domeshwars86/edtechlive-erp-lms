import { StyleSheet, Text, View } from 'react-native'
import type { AssessmentReport, EnrollmentReport, TeacherWorkloadReport } from './api'

export function CourseProgressReportPanel({ report, loading, role }: { report: { data: { course_id: number; title: string; lessons: number; learners: number; average_percent: number; completed_learners: number }[]; summary: { courses: number; learners: number; average_percent: number } } | null; loading: boolean; role: 'teacher' | 'manager' }) {
  return <View>
    <Text style={styles.sectionTitle} accessibilityRole="header">Course progress · {report?.summary.learners ?? 0} learners</Text>
    <Text style={styles.description}>{role === 'teacher' ? 'Completion trends for published courses assigned to you.' : 'Completion trends across published courses in this workspace.'}</Text>
    {report ? <>
      {report.summary.courses ? <View style={styles.summaryGrid}>
        <View style={styles.summaryCard}><Text style={styles.summaryValue}>{report.summary.average_percent}%</Text><Text style={styles.summaryLabel}>Average completion</Text></View>
        <View style={styles.summaryCard}><Text style={styles.summaryValue}>{report.summary.courses}</Text><Text style={styles.summaryLabel}>Published courses</Text></View>
        <View style={styles.summaryCard}><Text style={styles.summaryValue}>{report.summary.learners}</Text><Text style={styles.summaryLabel}>Active enrollments</Text></View>
      </View> : null}
      {report.data.slice(0, 5).map(course => <View style={styles.recordCard} key={course.course_id}>
        <Text style={styles.cardTitle}>{course.title}</Text>
        <Text style={styles.cardMeta}>{course.average_percent}% average · {course.completed_learners}/{course.learners} learners completed · {course.lessons} lessons</Text>
      </View>)}
      {report.summary.courses === 0 ? <Text style={styles.emptyText}>No published courses are available to report.</Text> : null}
    </> : <Text style={styles.emptyText}>{loading ? 'Loading course progress…' : 'Course progress is unavailable.'}</Text>}
  </View>
}

export function AssessmentReportPanel({ report, loading, role }: { report: AssessmentReport | null; loading: boolean; role: 'teacher' | 'manager' }) {
  return <View>
    <Text style={styles.sectionTitle} accessibilityRole="header">Quiz and assignment outcomes</Text>
    <Text style={styles.description}>{role === 'teacher' ? 'Published assessments in your assigned courses. Student identities are not included.' : 'Published course assessment outcomes. Averages exclude quiz answers awaiting review.'}</Text>
    {report ? <>
      <Text style={styles.subheading}>Quizzes</Text>
      <View style={styles.summaryGrid}>{[
        ['Published', report.summary.published_quizzes],
        ['Submitted', report.summary.submitted_attempts],
        ['In progress', report.summary.in_progress_attempts],
        ['Awaiting review', report.summary.awaiting_review],
        ['Learner participations', report.summary.learner_quiz_participations],
        ['Average score', report.summary.average_score_percent === null ? '—' : `${report.summary.average_score_percent}%`],
      ].map(([label, value]) => <View style={styles.summaryCard} key={label}><Text style={styles.summaryValue}>{value}</Text><Text style={styles.summaryLabel}>{label}</Text></View>)}</View>
      {report.quizzes.length ? report.quizzes.map(quiz => <View style={styles.recordCard} key={quiz.quiz_id}>
        <Text style={styles.cardTitle}>{quiz.quiz}</Text><Text style={styles.cardMeta}>{quiz.course} · {quiz.learners} learners · {quiz.submitted_attempts} submitted · {quiz.in_progress_attempts} in progress</Text>
        <Text style={styles.cardMeta}>{quiz.awaiting_review} awaiting review · {quiz.average_score_percent === null ? 'No graded average' : `${quiz.average_score_percent}% average`}</Text>
      </View>) : <Text style={styles.emptyText}>No published quizzes are available.</Text>}
      <Text style={styles.subheading}>Assignments</Text>
      <View style={styles.summaryGrid}>{[
        ['Published', report.assignment_summary.published_assignments],
        ['Submissions', report.assignment_summary.submissions],
        ['Graded', report.assignment_summary.graded_submissions],
        ['Awaiting review', report.assignment_summary.awaiting_review],
        ['Learners', report.assignment_summary.learner_submissions],
        ['Average score', report.assignment_summary.average_score_percent === null ? '—' : `${report.assignment_summary.average_score_percent}%`],
      ].map(([label, value]) => <View style={styles.summaryCard} key={label}><Text style={styles.summaryValue}>{value}</Text><Text style={styles.summaryLabel}>{label}</Text></View>)}</View>
      {report.assignments.length ? report.assignments.map(assignment => <View style={styles.recordCard} key={assignment.assignment_id}>
        <Text style={styles.cardTitle}>{assignment.assignment}</Text><Text style={styles.cardMeta}>{assignment.course} · {assignment.learners} learners · {assignment.submissions} submissions · {assignment.graded_submissions} graded</Text>
        <Text style={styles.cardMeta}>{assignment.awaiting_review} awaiting review · {assignment.average_score_percent === null ? 'No graded average' : `${assignment.average_score_percent}% average`}</Text>
      </View>) : <Text style={styles.emptyText}>No published assignments are available.</Text>}
    </> : <Text style={styles.emptyText}>{loading ? 'Loading assessment outcomes…' : 'Assessment outcomes are unavailable.'}</Text>}
  </View>
}

export function EnrollmentReportPanel({ report, loading }: { report: EnrollmentReport | null; loading: boolean }) {
  return <View>
    <Text style={styles.sectionTitle} accessibilityRole="header">Enrollment overview</Text>
    <Text style={styles.description}>Active learner placement and published-course enrollment by academic session.</Text>
    {report ? <>
      <View style={styles.summaryGrid}>{[
        ['Active students', report.summary.active_students],
        ['Unassigned students', report.summary.unassigned_students],
        ['Students in published courses', report.summary.students_with_published_courses],
        ['Active course enrollments', report.summary.active_course_enrollments],
      ].map(([label, value]) => <View style={styles.summaryCard} key={label}><Text style={styles.summaryValue}>{value}</Text><Text style={styles.summaryLabel}>{label}</Text></View>)}</View>
      {report.sessions.length ? report.sessions.map(session => <View style={styles.recordCard} key={session.session_id}>
        <Text style={styles.cardTitle}>{session.session}{session.is_current ? ' · Current' : ''}</Text>
        <Text style={styles.cardMeta}>{session.starts_on ?? 'Start date not set'} – {session.ends_on ?? 'End date not set'}{session.is_active ? '' : ' · Inactive session'}</Text>
        <Text style={styles.cardMeta}>{session.active_students} active students · {session.active_course_enrollments} active course enrollments</Text>
        {session.sections.length ? session.sections.map(section => <View style={styles.detailRow} key={section.section_id}>
          <Text style={styles.detailTitle}>{section.class} · {section.section}{section.active ? '' : ' · Inactive'}</Text>
          <Text style={styles.cardMeta}>{section.active_students} students · {section.active_course_enrollments} course enrollments · {section.capacity === null ? 'No capacity set' : `${section.capacity} capacity`}</Text>
        </View>) : <Text style={styles.cardMeta}>No sections are configured for this session.</Text>}
      </View>) : <Text style={styles.emptyText}>No academic sessions have been set up yet.</Text>}
    </> : <Text style={styles.emptyText}>{loading ? 'Loading enrollment overview…' : 'Enrollment overview is unavailable.'}</Text>}
  </View>
}

export function TeacherWorkloadReportPanel({ report, loading }: { report: TeacherWorkloadReport | null; loading: boolean }) {
  return <View>
    <Text style={styles.sectionTitle} accessibilityRole="header">Teacher workload{report ? ` · ${report.as_of}` : ''}</Text>
    <Text style={styles.description}>{report?.sessions.length ? `Active sessions: ${report.sessions.join(', ')}.` : 'Current active-session assignments and weekly timetable hours.'}</Text>
    {report ? <>
      <View style={styles.summaryGrid}>{[
        ['Active teachers', report.summary.active_teachers],
        ['With assignments', report.summary.teachers_with_assignments],
        ['Section assignments', report.summary.section_assignments],
        ['Teacher–learner links', report.summary.teacher_learner_assignments],
        ['Weekly schedule', `${report.summary.weekly_scheduled_hours} h`],
      ].map(([label, value]) => <View style={styles.summaryCard} key={label}><Text style={styles.summaryValue}>{value}</Text><Text style={styles.summaryLabel}>{label}</Text></View>)}</View>
      {report.teachers.length ? report.teachers.map(teacher => <View style={styles.recordCard} key={teacher.teacher_id}>
        <Text style={styles.cardTitle}>{teacher.teacher}</Text>
        <Text style={styles.cardMeta}>{teacher.section_assignments} section assignments · {teacher.active_learners} learner links</Text>
        <Text style={styles.cardMeta}>{teacher.weekly_timetable_slots} timetable slots · {teacher.weekly_scheduled_hours} scheduled hours per week</Text>
      </View>) : <Text style={styles.emptyText}>No active teachers are listed.</Text>}
    </> : <Text style={styles.emptyText}>{loading ? 'Loading teacher workload…' : 'Teacher workload is unavailable.'}</Text>}
  </View>
}

const styles = StyleSheet.create({
  sectionTitle: { color: '#19395f', fontSize: 17, fontWeight: '800', marginTop: 24, marginBottom: 5 },
  description: { color: '#6a7e94', fontSize: 12, lineHeight: 18, marginBottom: 10 },
  subheading: { color: '#425c77', fontSize: 13, fontWeight: '800', marginTop: 8, marginBottom: 7 },
  summaryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 8 },
  summaryCard: { flexGrow: 1, flexBasis: '46%', minWidth: 138, padding: 12, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  summaryValue: { color: '#234e82', fontSize: 21, fontWeight: '800' },
  summaryLabel: { color: '#71839a', fontSize: 10, marginTop: 4 },
  recordCard: { padding: 13, marginBottom: 8, borderRadius: 11, backgroundColor: '#fff', borderWidth: 1, borderColor: '#e8edf3' },
  cardTitle: { color: '#254566', fontSize: 13, fontWeight: '800' },
  cardMeta: { color: '#71839a', fontSize: 11, lineHeight: 17, marginTop: 4 },
  detailRow: { paddingVertical: 8, borderTopWidth: 1, borderTopColor: '#edf1f5', marginTop: 8 },
  detailTitle: { color: '#425c77', fontSize: 11, fontWeight: '700' },
  emptyText: { color: '#71839a', fontSize: 12, lineHeight: 18, paddingVertical: 12 },
})
