# Known Issues and Open Work

- [ ] Full client/API session verification has not been completed against a persistent local database and configured email transport.
- [ ] No automated component or browser end-to-end suite exists; current CI covers typecheck, lint, production builds, backend API tests, and mobile bundling.
- [ ] Dashboard routes and role navigation still need a full authenticated browser/mobile visual review. Windows Chrome could not reach Vite/Laravel processes launched from the isolated Codex shell.
- [ ] `public/sitemap.xml` currently uses a reserved `.example` host; replace with the approved canonical domain before launch.
- [ ] Contact enquiries are persisted and can notify a configured mailbox; an allowlisted staff inbox tracks status/reviewer, but retention cleanup and response content tracking remain open.
- [ ] Fee records currently support staff-entered INR receivables and receipts only. There is no payment gateway, refund workflow, idempotency key, reconciliation report, or finance-specific role.
- [ ] Class and management meetings save scoped HTTPS links, but Google Meet provisioning, reminders, participant invites and recording workflows are not connected.
- [ ] Authentication APIs are connected, but verification/reset delivery depends on configuring an email transport.
- [ ] Sample courses and provisional plan scope/pricing are not commercial offers.
- [ ] Privacy/terms/cookie/acceptable-use/refund copy is draft material pending legal review.
- [ ] Organization invitation email uses the configured mail transport; local development logs messages, so SMTP or another delivery service must be configured for real inbox delivery. Active organization selection is session-scoped; a persistent selection endpoint and detailed role/resource policies are not implemented.
- [ ] PostgreSQL migrations are written but unexecuted; database service status was not accessible through the local service inventory.
- [ ] Academic ERP is partial: sessions, classes, sections, subjects, rosters, teacher/subject/section/session assignments, invitations, direct guardian-link management, teacher-scoped attendance, timetable, student/parent attendance views, attendance/course-progress reports, aggregate enrollment placement, and teacher workload reporting are implemented. Finance reports, remaining ERP entities and broader attendance correction workflows are still open.
- [ ] LMS includes ordered course modules, lessons, private files, enrollment, assignments/submissions/grading, quizzes, per-quiz results, and course-scoped student/teacher progress views. Organization-wide learning reports, malware scanning, durable production file storage, broader examinations and learning notifications remain open. The Expo mobile app covers sign-in, workspace selection, summaries, courses/lessons, student/parent profile-attendance-fee views, submissions, teacher grading/attendance, timed quizzes/results, notification read receipts, and scoped meeting links; device builds, provider integration and deployment are not verified.
- [ ] The local development mailer logs messages instead of delivering them; configure SMTP or another approved transport for verification and reset links.
- [ ] Mobile dependencies use security overrides for Expo SDK 56 transitive tooling; revalidate these overrides with the next Expo SDK upgrade. The latest web and mobile registry-backed audits report zero vulnerabilities.
- [ ] External Google Fonts import can fail offline; local font hosting is a possible production improvement.
