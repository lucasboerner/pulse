/**
 * Opt-in error reporting, shared by the three runtimes Next.js runs code in: Node
 * (Server Components, route handlers, Server Actions), edge (middleware) and the
 * browser. With SENTRY_APP_DSN unset no runtime initialises the SDK and nothing
 * leaves the host.
 *
 * The published image is built once for every operator, so the DSN cannot be baked
 * in at build time — and the browser has no runtime `process.env`. The server reads
 * it per request and the root layout hands it to the browser in the meta tags below
 * (instrumentation-client.ts reads them).
 */

export type SentryConfig = {
  dsn: string;
  environment: string;
  release: string;
};

/** The meta tags carrying the config into the page. */
export const SENTRY_META = {
  dsn: "sentry-dsn",
  environment: "sentry-environment",
  release: "sentry-release",
} as const;

export const DEFAULT_ENVIRONMENT = "production";

/**
 * pulse@X.Y.Z — one release name for the API and the app, so "Resolve in next
 * release" works across both Sentry projects. The prod image sets SENTRY_RELEASE
 * from its build argument (ARG VERSION in app/Dockerfile); dev reports this.
 */
export const DEFAULT_RELEASE = "pulse@dev";

/**
 * Server and edge: the config from the container's environment, or null when
 * reporting is off. Call it at request time — read during a build it would see the
 * build machine's environment, not the operator's.
 */
export function sentryConfigFromEnv(): SentryConfig | null {
  const dsn = process.env.SENTRY_APP_DSN;

  if (!dsn) return null;

  return {
    dsn,
    environment: process.env.SENTRY_ENVIRONMENT || DEFAULT_ENVIRONMENT,
    release: process.env.SENTRY_RELEASE || DEFAULT_RELEASE,
  };
}

/** Init options common to every runtime. */
export function sentryOptions(config: SentryConfig) {
  return {
    ...config,
    // Errors only: without a tracesSampleRate the SDK starts no performance tracing.
    //
    // SDK v11 replaced sendDefaultPii with dataCollection. This is the
    // `sendDefaultPii: false` stance — no client IP, no user, no cookies — made
    // stricter: no headers (X-Forwarded-For carries the client IP behind a proxy),
    // no request or response bodies (the login Server Action's body carries the
    // password), no local variables.
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: false,
      httpBodies: [],
      stackFrameVariables: false,
    },
    // Turning Sentry on must not change the app: leave fetch error messages as
    // they are and enhance only the copy sent to Sentry.
    enhanceFetchErrorMessages: "report-only" as const,
  };
}
