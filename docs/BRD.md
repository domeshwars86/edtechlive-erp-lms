# Business Requirements Document

**Version:** 0.1 · **Status:** Baseline for discovery

## Business problem

Educational organizations and independent teachers coordinate academic records, teaching, materials and communication across disconnected workflows. edTechLive aims to provide a coherent workspace while preserving organization-specific access and keeping learning at the center.

## Business goals

- Give each user role a clear view of relevant work.
- Reduce avoidable context switching in common academic workflows.
- Protect organization, learner and child data with robust access controls.
- Support a free-first entry path and future sustainable commercial plans.
- Keep integrations replaceable and the product maintainable by a small team.

## Stakeholders

Platform owner/operator; institution management and administrators; teachers; learners; parents/guardians; support and engineering; third-party identity, storage, communication, meeting, payment and AI providers.

## Business capabilities

| Capability | Business value | Priority |
|---|---|---|
| Public discovery and enquiry | Explain product fit and gather interest | P0 |
| Identity, memberships and tenancy | Securely support multiple organizations and roles | P0 |
| Academic administration | Organize people, sessions and classroom structures | P0 |
| Learning delivery | Provide courses, content and class schedules | P0 |
| Assessment and progress | Support assignments, quizzes and results | P1 |
| Communication and meetings | Coordinate classes and announcements | P1 |
| Reporting and fee records | Support management visibility and finance basics | P1 |
| AI and paid plans | Add optional advanced capabilities | P2/Future |
| Native mobile apps | Extend key workflows to phones | P1, after web |

## Success measures

Define after discovery and baseline testing: completion of core workflows; permission-denial correctness; adoption and active use; successful class/resource access; task completion time; reliability; accessibility; support burden. Do not claim numeric outcomes before measurement.

## Out of scope for the initial public website

The public website does not itself provision accounts, enroll learners, collect payments, send automated messages, create Meet sessions, expose private student records, or provide operational AI. Separate authenticated application workflows now support selected account and academic/LMS operations; payment collection, provider automation, production privacy/legal commitments and other planned capabilities remain out of scope until implemented and reviewed.

## Risks and dependencies

Legal/compliance review; hosting and storage budget; OAuth/API approvals and quotas; email domain reputation; payment merchant setup; data migration and retention; user onboarding; dependable backups and support operations.
