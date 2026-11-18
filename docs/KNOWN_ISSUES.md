# Known Issues and Open Work

- [ ] Dependencies and checks have not yet completed in this session.
- [ ] No automated component or end-to-end test suite exists.
- [ ] Dashboard routes and role navigation need a browser/mobile visual review.
- [ ] `public/sitemap.xml` currently uses a reserved `.example` host; replace with the approved canonical domain before launch.
- [ ] Contact form validates locally but has no delivery API; it explicitly tells users that submission is not transmitted.
- [ ] Authentication, organization registration and role selection are previews only.
- [ ] Sample courses and provisional plan scope/pricing are not commercial offers.
- [ ] Privacy/terms/cookie/acceptable-use/refund copy is draft material pending legal review.
- [ ] Registration, login, organization invitations/switching and detailed policies are not implemented.
- [ ] PostgreSQL migrations are written but unexecuted; database service status was not accessible through the local service inventory.
- [ ] Academic ERP, LMS, mobile apps, integrations and deployment are not implemented.
- [ ] npm audit still reports nine high severity findings through `picomatch@4.0.7`, used by Vite and TypeScript-ESLint. Registry metadata reports 4.0.7 as the latest picomatch release and suggests a Vite 6.2.7 downgrade; that incompatible downgrade was not applied. Recheck when upstream publishes a patched dependency or compatible fix.
- [ ] External Google Fonts import can fail offline; local font hosting is a possible production improvement.
