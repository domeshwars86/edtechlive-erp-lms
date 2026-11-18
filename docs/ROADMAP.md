# Roadmap

1. **Public website:** responsive brand and complete public routes, interactions, accessible behavior, static prerendering, production build and QA. **Frontend gate passed; legal/domain follow-ups remain.**
2. **Role-based dashboards:** Super Admin, Admin, Management, Teacher, Student, Parent and Independent Educator frontends. **Initial frontend gate passed; backend services remain disconnected.**
3. **Backend foundation:** Laravel 13/Sanctum auth, email verification, password recovery, organization registration and membership reads are implemented. PostgreSQL verification and policy-based permissions remain.
4. **Connect APIs:** web login, account registration/recovery, session restore/logout and active organization selection are connected; dashboard identity is real while dashboard business data stays demonstrative. Next: implement academic ERP APIs and replace sample dashboard panels with authorized records.
5. **Academic ERP:** sessions, classes, sections, subjects, rosters, teacher assignments, staff/student/parent invitations, teacher-scoped attendance, student/guardian workspaces, weekly timetable, and attendance summary reports have tenant-scoped APIs and web screens. Academic report coverage remains limited to attendance; additional reports and broader attendance correction workflows remain.
6. **LMS and assessment:** courses, content, assignments, quizzes, submissions, results and progress.
7. **Free-first integrations:** supported Meet links/API, manual recording upload, email, WhatsApp click-to-chat and push.
8. **Reports, payments, AI:** basic reports and fee records, payment sandbox, optional AI provider adapter.
9. **Web QA and staging:** tenant isolation, security, accessibility, responsive and end-to-end workflows; deploy only to authorized staging.
10. **Mobile apps:** React Native student/teacher essentials, then limited admin/management workflows.
11. **Commercial readiness:** reviewed privacy/legal terms, operations, support, backups, monitoring and launch readiness.
12. **Future expansion:** entitlements, subscriptions and advanced features.

Academic ERP is in progress; its milestone is not complete until people, attendance, schedules, policies, and workflows have persistent APIs and validation. Do not claim the module complete before its gate. Calendar estimates are targets, not completion claims.
