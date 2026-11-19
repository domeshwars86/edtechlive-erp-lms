# User Roles and Access Model

## Platform scope

- **Super Admin:** platform-wide role is represented in the current role model. Current web/API platform controls are limited to allowlisted verified operators who can review aggregate metrics and organization/user directories, suspend/reactivate organizations, and read the audit history for those status changes. Account mutation, role configuration, subscription controls and support tooling remain planned; platform status privileges do not imply access to student content.
- **Platform Support:** a target role for narrowly scoped diagnostics and support actions; a separate implementation and permission set are not yet available. No default access to private learning content is intended.

## Organization scope

- **Admin:** organization settings and academic masters, staff/student memberships, authorized reports and workflows.
- **Management:** read-oriented institution overview, meetings and aggregate reports; sensitive learner details only when explicitly granted.
- **Teacher:** assigned classes/courses, lesson materials, attendance, assignments, submissions and assessments.
- **Student:** own enrollment, authorized courses, class links, assignments, submissions, attendance and results.
- **Parent/Guardian:** only linked child summaries, attendance, published-course progress, assignment due/submission/grade status, assessment results and fees explicitly permitted by the institution.
- **Independent Educator:** own educator workspace, courses, enrolled learners and teaching analytics; future sales tools are disabled until implemented.

## Implemented authorization boundary

The current API stores organization membership roles with a PHP backed enum and enforces access using authenticated-user, verified-email, active-organization and resource-scope checks. Organization membership workflows also have a tenant-scoped capability catalog: Admin and Independent Educator default to membership read/manage/audit, Management defaults to read, and an Admin or Independent Educator may grant explicit catalog overrides to eligible memberships. Granting manage or audit also grants member-list read. Delegated capabilities can manage membership records and review their access audit, subject to role-escalation limits; they cannot grant or revoke capabilities. This permission system currently applies only to membership workflows. Other domain APIs continue to use their existing role and resource-scope checks.

## Rules

1. Users may have multiple organization memberships and different roles per organization.
2. Organization context is explicit and every protected query is tenant-scoped.
3. Policies check action, tenant membership, resource ownership/assignment and record relationship.
4. The server is authoritative; frontend visibility is only a usability aid.
5. Privileged changes and access to sensitive data are auditable.
6. Default to least privilege. Parent links and student access need verified relationships and revocation handling.

This is a starting access model. A complete cross-domain permission/action matrix is still required before backend authorization is considered complete.
