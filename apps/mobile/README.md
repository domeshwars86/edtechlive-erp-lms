# edTechLive Mobile

Expo React Native application for Android and iOS. The first vertical slice supports verified account sign-in with a Sanctum personal access token, secure token persistence, organization selection, role-scoped dashboard summaries, and course/lesson reading.

## Local setup

Use Node.js 20.19.4 or newer in the Node 20 line (or a newer SDK 56 supported LTS runtime).

1. Copy `.env.example` to `.env` and set `EXPO_PUBLIC_API_URL` to a reachable Laravel API origin. Android emulators can use `http://10.0.2.2:8001`; physical devices need the development computer's LAN IP and an API server bound to that interface. Do not commit production secrets in Expo public variables.
2. Install dependencies with `npm install`.
3. Start the Metro development server with `npm start`.

Tokens are stored using Expo SecureStore, not AsyncStorage. The API remains authoritative for verification, organization access, course enrollment, and permissions. Native Android/iOS builds require the respective SDK/toolchain and are not available in every development environment.
