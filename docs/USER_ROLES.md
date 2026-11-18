# User Roles and Access Model

## Platform scope

- **Super Admin:** platform-wide organization/user operations, platform configuration, audit and support; access to student content is not implicit and should be exceptional and audited.
- **Platform Support:** narrowly scoped diagnostics and support actions; no default access to private learning content.

## Organization scope

- **Admin:** organization settings and academic masters, staff/student memberships, authorized reports and workflows.
- **Management:** read-oriented institution overview, meetings and aggregate reports; sensitive learner details only when explicitly granted.
- **Teacher:** assigned classes/courses, lesson materials, attendance, assignments, submissions and assessments.
- **Student:** own enrollment, authorized courses, class links, assignments, submissions, attendance and results.
- **Parent/Guardian:** only linked child summaries, attendance, published-course progress, assignment due/submission/grade status, assessment results and fees explicitly permitted by the institution.
- **Independent Educator:** own educator workspace, courses, enrolled learners and teaching analytics; future sales tools are disabled until implemented.

## Rules

1. Users may have multiple organization memberships and different roles per organization.
2. Organization context is explicit and every protected query is tenant-scoped.
3. Policies check action, tenant membership, resource ownership/assignment and record relationship.
4. The server is authoritative; frontend visibility is only a usability aid.
5. Privileged changes and access to sensitive data are auditable.
6. Default to least privilege. Parent links and student access need verified relationships and revocation handling.

This is a starting access model. A detailed permission/action matrix is required before backend authorization is considered complete.
