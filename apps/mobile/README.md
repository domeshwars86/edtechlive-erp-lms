# edTechLive Mobile

Expo React Native application for Android and iOS. Verified account sign-in uses a Sanctum personal access token with secure token persistence and organization selection. Role-scoped views include admin/management roster, attendance, course-progress, and identity-free school exam section-performance summaries; learner/family attendance, homework, results and fees; course/lesson reading, scheduled single-choice and multiple-select quizzes, teacher attendance/grading, meeting links and recent published meeting recordings, and published study materials. Material and recording downloads open in the device browser through two-minute signed links issued after server-side enrollment, guardian-link, or meeting checks; the account token is not placed in the URL.

## Local setup

Use Node.js 20.19.4 or newer in the Node 20 line (or a newer SDK 56 supported LTS runtime).

1. Copy `.env.example` to `.env` and set `EXPO_PUBLIC_API_URL` to a reachable Laravel API origin. Android emulators can use `http://10.0.2.2:8001`; physical devices need the development computer's LAN IP and an API server bound to that interface. Do not commit production secrets in Expo public variables.
2. Install dependencies with `npm install`.
3. Start the Metro development server with `npm start`.

Tokens are stored using Expo SecureStore, not AsyncStorage. The API remains authoritative for verification, organization access, course enrollment, and permissions. Native Android/iOS builds require the respective SDK/toolchain and are not available in every development environment.
