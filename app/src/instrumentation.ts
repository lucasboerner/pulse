import type { Instrumentation } from "next";
import { sentryConfigFromEnv, sentryOptions } from "@/lib/sentry";

/**
 * Server and edge start-up. Sentry initialises only when SENTRY_APP_DSN is set; the
 * SDK is imported lazily, so with no DSN it is never even loaded. The same import
 * resolves to the Node or the edge build of @sentry/nextjs per runtime.
 */
export async function register(): Promise<void> {
  const config = sentryConfigFromEnv();

  if (!config) return;

  const Sentry = await import("@sentry/nextjs");
  Sentry.init({
    ...sentryOptions(config),
    // The diagnostics-channel hooks only record spans, and errors need none. Left
    // on, they look for @sentry/server-runtime-injection on disk, which the
    // standalone output does not carry, and warn on every boot.
    enableRuntimeChannelInjection: false,
  });
}

/**
 * Errors thrown while rendering Server Components, in route handlers, Server Actions
 * and middleware. Next.js catches them before any global handler would, so this hook
 * is the only place they surface.
 */
export const onRequestError: Instrumentation.onRequestError = async (...args) => {
  if (!sentryConfigFromEnv()) return;

  const Sentry = await import("@sentry/nextjs");
  Sentry.captureRequestError(...args);
};
