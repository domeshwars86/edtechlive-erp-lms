# Known Issues and Open Work

- [ ] Full client/API session verification has not been completed against a persistent local database and configured email transport.
- [ ] No automated component or end-to-end test suite exists.
- [ ] Dashboard routes and role navigation need a browser/mobile visual review.
- [ ] `public/sitemap.xml` currently uses a reserved `.example` host; replace with the approved canonical domain before launch.
- [ ] Contact enquiries are persisted and can notify a configured mailbox; there is no staff review inbox, response tracking, or retention cleanup yet.
- [ ] Authentication APIs are connected, but verification/reset delivery depends on configuring an email transport.
- [ ] Sample courses and provisional plan scope/pricing are not commercial offers.
- [ ] Privacy/terms/cookie/acceptable-use/refund copy is draft material pending legal review.
- [ ] Organization invitation email uses the configured mail transport; local development logs messages, so SMTP or another delivery service must be configured for real inbox delivery. Organization switching endpoints and detailed role/resource policies are not implemented.
- [ ] PostgreSQL migrations are written but unexecuted; database service status was not accessible through the local service inventory.
- [ ] Academic ERP is partial: sessions, classes, sections, subjects, rosters, teacher/subject/section/session assignments, teacher/student/parent invitations and account links, teacher-scoped attendance, timetable, student/parent attendance views, and attendance summary reports are implemented. Direct guardian-link management, broader academic reports, and remaining ERP entities are still open.
- [ ] LMS now has courses, lessons, private course files, enrollment, text assignments/submissions/grading and timed MCQ quizzes. Modules, malware scanning, durable production file storage, broader examinations, progress tracking and learning notifications remain open. Mobile apps, live-class integrations and deployment are not implemented.
- [ ] The local development mailer logs messages instead of delivering them; configure SMTP or another approved transport for verification and reset links.
- [ ] npm audit still reports nine high severity findings through `picomatch@4.0.7`, used by Vite and TypeScript-ESLint. Registry metadata reports 4.0.7 as the latest picomatch release and suggests a Vite 6.2.7 downgrade; that incompatible downgrade was not applied. Recheck when upstream publishes a patched dependency or compatible fix.
- [ ] External Google Fonts import can fail offline; local font hosting is a possible production improvement.
