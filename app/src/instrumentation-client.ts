import * as Sentry from "@sentry/nextjs";
import {
  DEFAULT_ENVIRONMENT,
  DEFAULT_RELEASE,
  SENTRY_META,
  sentryOptions,
  type SentryConfig,
} from "@/lib/sentry";

/**
 * Browser start-up. Sentry initialises only when the root layout rendered a DSN into
 * the page (see lib/sentry.ts) — no tag, no SDK, no request to any Sentry host. No
 * Session Replay, no feedback widget, no tracing, and no tunnelRoute: it only works
 * for sentry.io, and the DSN may point at GlitchTip or Bugsink.
 */
function configFromPage(): SentryConfig | null {
  const read = (name: string) =>
    document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.content;
  const dsn = read(SENTRY_META.dsn);

  if (!dsn) return null;

  return {
    dsn,
    environment: read(SENTRY_META.environment) || DEFAULT_ENVIRONMENT,
    release: read(SENTRY_META.release) || DEFAULT_RELEASE,
  };
}

function init(): boolean {
  const config = configFromPage();

  if (config) Sentry.init(sentryOptions(config));

  return config !== null;
}

// The tags sit in <head>, but Next.js loads its scripts async, so this can run
// before the parser has reached them. Look again once the document is parsed.
if (!init() && document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init, { once: true });
}
