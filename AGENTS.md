# edTechLive project guidance

## Product scope

Build edTechLive as a multi-tenant education ERP and LMS for institutions, educators, learners and parents. Follow `docs/CLIENT_REQUIREMENTS.md`, `docs/FEATURE_MATRIX.md` and `docs/ROADMAP.md`; keep the public website first, dashboard frontends second, then backend and integrations. Do not claim planned or sample capabilities are operational.

## Engineering conventions

- Preserve working functionality and use strict TypeScript for the web frontend.
- Keep UI accessible: semantic HTML, keyboard access, visible focus, meaningful labels, responsive layouts and reduced-motion support.
- Treat all browser-only forms and sample content as demonstrations unless a real API persists or sends the data.
- Enforce organization and resource permissions on the server; hiding a UI control is not authorization.
- Never put credentials or real student data in source control, public storage, screenshots or third-party AI prompts without an authorized data-processing basis.
- Keep external services configurable and document assumptions and decision changes.
- Update `docs/IMPLEMENTATION_STATUS.md`, `docs/KNOWN_ISSUES.md` and relevant feature documentation as implementation changes.
- Before reporting a check as passing, run it and report its actual result. Do not claim deployment, browser QA or test coverage that did not occur.

## Current structure

The repository starts as a Vite, React and TypeScript public frontend. Future apps belong under `apps/` when the dashboard/backend milestones begin; avoid moving the working website without need.

## Commands

- `npm run dev` — local frontend development
- `npm run typecheck` — strict TypeScript project check
- `npm run lint` — ESLint
- `npm run build` — type check and production bundle

See `README.md` for current setup and limitations.
