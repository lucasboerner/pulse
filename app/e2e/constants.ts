// Shared configuration for the end-to-end suite. Everything a spec, the config and
// the global setup all need to agree on lives here, so there is one source of truth.
//
// The URLs and the operator credentials are overridable by environment variable with a
// committed default, so the same suite runs against the local compose stack and against
// the CI stack without editing a file. The operator only ever exists in a throwaway
// local or CI database (the run resets it), so a committed default password is safe.

/** The Next.js app the browser drives — the `app` service's mapped port. */
export const APP_URL = process.env.E2E_BASE_URL ?? "http://localhost:3000";

/** The backend, for seeding fixtures over the API — the `api` service's plain-HTTP
 *  host port (compose maps 8080:80). The browser never uses this; only the test process. */
export const API_URL = process.env.E2E_API_URL ?? "http://localhost:8080";

/** The single test operator, created in global setup. */
export const OPERATOR = {
  username: process.env.E2E_USERNAME ?? "e2e-operator",
  email: process.env.E2E_EMAIL ?? "e2e@pulse.test",
  password: process.env.E2E_PASSWORD ?? "e2e-password-2026",
};

/** Where the signed-in operator's session is stored after global setup logs in once. */
export const STORAGE_STATE = "e2e/.auth/operator.json";
