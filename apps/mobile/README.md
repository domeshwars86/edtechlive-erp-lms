# edTechLive Mobile

Expo React Native application for Android and iOS. Verified account sign-in uses a Sanctum personal access token with secure token persistence and organization selection. The Account screen updates the shared account name and email and optionally changes the password after current-password confirmation; email changes expose the local signed verification link and verification-status refresh, and password changes revoke other API tokens. Role-scoped views include Admin, Management and Independent Educator finance snapshots; Admin/Management roster, attendance, course-progress, identity-free school exam section-performance and exam-series comparison reports; assigned teachers' identity-free exam-series comparisons; learner/family attendance, homework, results and fees; course/lesson reading, scheduled single-choice and multiple-select quizzes, teacher attendance with reason-required corrections for saved attendance marks, assigned school-exam mark entry, assignment grading, role-scoped meeting creation for managers and teachers, meeting links and recent published meeting recordings, and published study materials. Meeting times use the device's local time and are converted to UTC before saving; the API enforces tenant and role access. The finance snapshot is read-only and uses the existing role-checked API to show aggregate issued, received, outstanding, overdue, expense and period cash-flow amounts. Exam score, paper-section total, assignment, tenant and teaching-assignment checks remain API-enforced. Material and recording downloads open in the device browser through two-minute signed links issued after server-side enrollment, guardian-link, or meeting checks; the account token is not placed in the URL.

## Local setup

Use Node.js 20.19.4 or newer in the Node 20 line (or a newer SDK 56 supported LTS runtime).

1. Copy `.env.example` to `.env` and set `EXPO_PUBLIC_API_URL` to a reachable Laravel API origin. Android emulators can use `http://10.0.2.2:8001`; physical devices need the development computer's LAN IP and an API server bound to that interface. Do not commit production secrets in Expo public variables.
2. Install dependencies with `npm install`.
3. Start the Metro development server with `npm start`.

Tokens are stored using Expo SecureStore, not AsyncStorage. The API remains authoritative for verification, organization access, course enrollment, and permissions. Native Android/iOS builds require the respective SDK/toolchain and are not available in every development environment.
