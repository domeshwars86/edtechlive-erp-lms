# Known Issues and Open Work

- [ ] Full client/API session verification has not been completed against a persistent local database and configured email transport.
- [ ] No automated component or end-to-end test suite exists.
- [ ] Dashboard routes and role navigation need a browser/mobile visual review.
- [ ] `public/sitemap.xml` currently uses a reserved `.example` host; replace with the approved canonical domain before launch.
- [ ] Contact form validates locally but has no delivery API; it explicitly tells users that submission is not transmitted.
- [ ] Authentication APIs are connected, but verification/reset delivery depends on configuring an email transport.
- [ ] Sample courses and provisional plan scope/pricing are not commercial offers.
- [ ] Privacy/terms/cookie/acceptable-use/refund copy is draft material pending legal review.
- [ ] Organization invitations/switching endpoints and detailed role/resource policies are not implemented.
- [ ] PostgreSQL migrations are written but unexecuted; database service status was not accessible through the local service inventory.
- [ ] Academic ERP is partial: sessions, classes, sections, subjects, and basic student/teacher rosters are implemented; account invitations, guardians, attendance, schedules, and reports are not.
- [ ] LMS, mobile apps, integrations and deployment are not implemented.
- [ ] The local development mailer logs messages instead of delivering them; configure SMTP or another approved transport for verification and reset links.
- [ ] npm audit still reports nine high severity findings through `picomatch@4.0.7`, used by Vite and TypeScript-ESLint. Registry metadata reports 4.0.7 as the latest picomatch release and suggests a Vite 6.2.7 downgrade; that incompatible downgrade was not applied. Recheck when upstream publishes a patched dependency or compatible fix.
- [ ] External Google Fonts import can fail offline; local font hosting is a possible production improvement.
