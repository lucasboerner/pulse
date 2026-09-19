import { SignJWT } from "jose";

// The Mercure hub is Mercure.rocks 1.0 and enforces RFC 9068: a subscriber token
// must carry a `typ: at+jwt` header and an `authorization_details` claim (not the
// legacy `mercure` claim), with iss/aud/sub/client_id/exp. This mirrors the shape
// the backend's own publisher token uses, so the hub accepts it.
const AUTHORIZATION_DETAIL_TYPE = "https://mercure.rocks/authorization-detail";
const ISSUER = "https://localhost";
const CLIENT_ID = "pulse";

interface MintArgs {
  secret: string;
  /** The exact URL the browser subscribes to (MERCURE_PUBLIC_URL) — the hub
   *  derives the expected audience from the request URL. */
  audience: string;
  subject: string;
  topics: string[];
  ttlSeconds: number;
}

export async function mintSubscriberToken(args: MintArgs): Promise<string> {
  const key = new TextEncoder().encode(args.secret);
  const now = Math.floor(Date.now() / 1000);

  return new SignJWT({
    client_id: CLIENT_ID,
    authorization_details: [
      {
        type: AUTHORIZATION_DETAIL_TYPE,
        actions: ["subscribe"],
        topics: args.topics.map((topic) => ({ match: topic })),
      },
    ],
  })
    .setProtectedHeader({ alg: "HS256", typ: "at+jwt" })
    .setIssuer(ISSUER)
    .setAudience(args.audience)
    .setSubject(args.subject)
    .setIssuedAt(now)
    .setExpirationTime(now + args.ttlSeconds)
    .sign(key);
}

/**
 * The cookie domain that lets the app's browser send `mercureAuthorization` to
 * the hub. Same-origin (production) → host-only cookie (undefined). Different
 * hosts sharing a parent (dev: app.pulse.orb.local vs mercure.pulse.orb.local) →
 * the shared parent (pulse.orb.local). No shared parent → host-only, and the
 * cross-site cookie simply will not reach the hub (live updates degrade to none).
 */
export function mercureCookieDomain(appHost: string, hubHost: string): string | undefined {
  const app = appHost.split(":")[0];
  if (app === hubHost) return undefined;

  const appLabels = app.split(".").filter(Boolean);
  const hubLabels = hubHost.split(".").filter(Boolean);
  const shared: string[] = [];
  let i = appLabels.length - 1;
  let j = hubLabels.length - 1;
  while (i >= 0 && j >= 0 && appLabels[i] === hubLabels[j]) {
    shared.unshift(appLabels[i]);
    i -= 1;
    j -= 1;
  }

  // Fewer than two labels is a TLD (or nothing) — not a settable cookie domain.
  return shared.length >= 2 ? shared.join(".") : undefined;
}
