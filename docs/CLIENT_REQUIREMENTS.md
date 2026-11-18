# Client Requirements — edTechLive

**Version:** 0.1 · **Date:** 18 November 2026 · **Status:** Working baseline

## Product objective

Build a maintainable, multi-tenant education ERP and learning platform that helps institutions and educators organize academic administration, deliver learning, assess progress and communicate with learners and families. Initial approach is free/open-source first, with Render as a deployment target and future cloud portability.

## Audiences

- Schools, colleges, coaching institutes and academies.
- Independent educators and course creators.
- Students and their parents or guardians.
- Institution administrators, management and platform operators.

## Primary outcomes

1. A polished public website explains the product and routes visitors to useful next steps.
2. Role-oriented web workspaces support administration and learning workflows.
3. An API-backed platform provides secure organization-scoped persistence.
4. Shared APIs support Android and iOS apps after the web platform is functional.

## Scope and priorities

Feature priorities and current status are maintained in `FEATURE_MATRIX.md`. The mandated delivery order is public website, dashboard frontends, Laravel backend and identity/tenancy, API connection, ERP, LMS/exams, free-first integrations, reports/payments/AI, web QA/staging, mobile, then commercial readiness and subscriptions.

## Website requirements

Responsive public home, about, feature catalog, courses/search/filter/detail direction, institution/teacher/student solutions, provisional pricing, contact/enquiry, login/register/forgot/reset/role/organization selection, and draft privacy, terms, cookie, acceptable-use and refund information. Navigation, keyboard focus, meaningful labels, form feedback, mobile layout and SEO basics are required. Do not invent testimonials, customers, company history, awards or confirmed prices.

## Platform requirements

- Organization types: school, college, coaching institute, academy and independent educator.
- One person can hold memberships and different roles across organizations.
- Organization and resource isolation must be enforced server-side and tested.
- Academic scope includes sessions, classes, sections, batches, subjects, teachers, students, attendance, timetables, documents and reports.
- Learning scope includes courses, modules, lessons, resources, live-class details, assignments, quizzes, submissions, results and progress.
- Communication supports email, in-app alerts, manual WhatsApp click-to-chat and push notifications where configured.
- Payments begin with fee records/manual entries and sandbox integration; no unapproved live charges.
- AI is optional, provider-configurable, rate-limited and reviewed by educators; do not use real student data without appropriate authorization and processing controls.

## Constraints and assumptions

- Start with free/open-source tooling and avoid unapproved paid services.
- Public pricing, plan limits, service levels and final policy language are undecided.
- Google Meet is launched through supported links or APIs; do not embed or record unsupported Meet streams.
- Production storage must be private and durable; app-local ephemeral files are not an acceptable store for student records.
- Indian privacy and child-data obligations and any other relevant jurisdictions require qualified legal review before production.
- Backend connectivity, support channels, email delivery, payments, AI provider and hosting credentials are not currently configured in this repository.
