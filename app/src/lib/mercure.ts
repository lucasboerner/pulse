import { SignJWT } from "jose";

// The list topic the overview and table subscribe to. The worker publishes to it
// (and to per-monitor topics) after writing a check result.
export const MERCURE_TOPIC = "pulse://monitors";

// The Mercure hub is Mercure.rocks 1.0 and enforces RFC 9068: a subscriber token
// must carry a `typ: at+jwt` header and an `authorization_details` claim (not the
// legacy `mercure` claim), with iss/aud/sub/client_id/exp. This mirrors the shape
// the backend's own publisher token uses, so the hub accepts it.
const AUTHORIZATION_DETAIL_TYPE = "https://mercure.rocks/authorization-detail";
const ISSUER = "https://localhost";
const CLIENT_ID = "pulse";

interface MintArgs {
  secret: string;
  /** The exact hub URL the token is used against; the hub validates its `aud` against
   *  the URL it serves, so this is the internal address the /mercure proxy connects to. */
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
