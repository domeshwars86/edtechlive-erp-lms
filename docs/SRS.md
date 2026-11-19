# Software Requirements Specification

**Version:** 0.1 · **Status:** Baseline; refine during implementation

## System context

The planned system is a public React web experience, role-based web application, versioned Laravel REST API, PostgreSQL database, queue/cache infrastructure as justified, private object storage, and React Native clients. The repository currently implements the public frontend, seven dashboard roles, connected Laravel auth and tenant-scoped ERP/LMS workflows, plus an Expo mobile vertical slice. PostgreSQL production validation, full deployment, and several advanced modules remain open; see `IMPLEMENTATION_STATUS.md` for verified scope.

## Functional requirements

1. Visitors can navigate all public product pages and use course search and level filters.
2. Visitors can submit a consented contact enquiry to the throttled API; staff review is restricted to an allowlisted verified platform operator, and email delivery requires configuration.
3. Users can register, verify email, sign in, recover passwords, and select active organization memberships; real email delivery and broader identity controls remain deployment requirements.
4. Authenticated users see only the workspaces and records allowed by their organization membership and permissions.
5. Administrators maintain organization, academic session, class, section, subject, teacher and student records. Batch management remains planned.
6. Teachers manage courses, lessons, resources, class schedules, assignments, submissions, assessments and results.
7. Students access permitted learning content, submit work and view their own results/progress.
8. Parents access only records of explicitly linked children permitted by the institution.
9. Management views authorized aggregate academic and attendance reports and management meeting details.
10. An explicitly allowlisted, verified platform operator can suspend/reactivate organizations with a required reason and audit event. Broader user, role, billing and system configuration remains planned.
11. Integrations use provider abstractions, explicit configuration, safe failure handling and appropriate consent.

## Quality requirements

- Responsive at 320, 375, 430, 768, 1024, 1280 and 1440 CSS pixels; no unintended horizontal overflow.
- Target WCAG 2.2 AA where practical; semantic structure, keyboard use, contrast and focus visibility.
- API authorization and tenant scoping are enforced server-side and covered by cross-tenant tests.
- Secrets remain in deployment secret stores; uploads are validated and private by default.
- Paginate list APIs, index tenant and relationship keys, avoid N+1 queries and queue heavy jobs.
- Record operational health, errors and backups without exposing learner information in logs.

## Current website acceptance

All defined public routes render; nav adapts to small screens; course search and level filtering work; enquiry submission persists consented requests and reports notification status; sample course/pricing content is labelled; legal pages show draft status; unavailable routes show a useful 404; TypeScript, lint and production build pass. Authenticated backend workflows have API coverage, while a full browser end-to-end suite and persistent production-shaped database verification remain open.

## Open questions

Detailed institution workflows, role/permission matrix, retention and deletion, identity provider and MFA policy, organization invitation/verification rules, course enrollment model, fee accounting scope, languages, SLA and final pricing remain to be agreed through product discovery.
