import { getToken, getCurrentUsername } from "@/lib/auth";
import { mintSubscriberToken, MERCURE_TOPIC } from "@/lib/mercure";

// A same-origin proxy for the Mercure subscription. The browser's EventSource
// connects here; the server opens the hub stream with a subscribe-only capability
// token and pipes the server-sent events straight back. Keeping the browser on one
// origin removes every cross-origin hazard at once — no CORS, no mixed-content block
// over https, and no cross-subdomain cookie — and lets the client use a plain
// EventSource, which cannot send an Authorization header. The API credential in the
// httpOnly cookie is never exposed; only this short-lived, subscribe-scoped token
// reaches the hub, and it never leaves the server.
export const dynamic = "force-dynamic";

const SUBSCRIBER_TOKEN_TTL_SECONDS = 3600;

export async function GET(request: Request): Promise<Response> {
  const sessionToken = await getToken();
  if (!sessionToken) return new Response("Unauthorized", { status: 401 });

  // The internal hub address (Docker service name), reachable only from the server.
  const hubUrl = process.env.MERCURE_URL;
  const secret = process.env.MERCURE_JWT_SECRET;
  if (!hubUrl || !secret) {
    return new Response("Live updates are not configured", { status: 503 });
  }

  let subscriberToken: string;
  try {
    const username = await getCurrentUsername();
    subscriberToken = await mintSubscriberToken({
      secret,
      // The hub validates the audience against the URL it serves; internally that is
      // the plain hub URL the proxy connects to (no TLS termination in front of it).
      audience: hubUrl,
      subject: username ?? "pulse",
      topics: [MERCURE_TOPIC],
      ttlSeconds: SUBSCRIBER_TOKEN_TTL_SECONDS,
    });
  } catch {
    return new Response("Live updates are not available", { status: 503 });
  }

  const target = new URL(hubUrl);
  // Mercure.rocks 1.0 subscribes with the `match` topic selector.
  target.searchParams.set("match", MERCURE_TOPIC);

  let upstream: Response;
  try {
    upstream = await fetch(target, {
      headers: { Authorization: `Bearer ${subscriberToken}`, Accept: "text/event-stream" },
      // The browser closing its EventSource aborts this request, which aborts the
      // upstream hub stream — no orphaned connections.
      signal: request.signal,
      cache: "no-store",
    });
  } catch {
    return new Response("The live-update hub is unreachable", { status: 502 });
  }

  if (!upstream.ok || !upstream.body) {
    return new Response("The live-update hub rejected the subscription", { status: 502 });
  }

  return new Response(upstream.body, {
    status: 200,
    headers: {
      "Content-Type": "text/event-stream",
      // Never let a cache or proxy buffer or transform a live stream.
      "Cache-Control": "no-cache, no-transform",
      Connection: "keep-alive",
      "X-Accel-Buffering": "no",
    },
  });
}
