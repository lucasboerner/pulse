import { NextResponse, type NextRequest } from "next/server";
import { decodeJwt } from "jose";

import { mintSubscriberToken, mercureCookieDomain } from "@/lib/mercure";

// These names must match lib/auth.ts. They are duplicated here because middleware
// runs in the edge runtime and cannot import the next/headers-based helpers.
const TOKEN_COOKIE = "pulse_token";
const MERCURE_COOKIE = "mercureAuthorization";
const MERCURE_TOPIC = "pulse://monitors";
const MERCURE_TTL_SECONDS = 3600;

const PUBLIC_PATHS = ["/login", "/logout"];

/**
 * Route gating is user experience only — the API stays the authority. A missing
 * token bounces to /login; a present token on /login bounces to the overview.
 * For an authenticated page we also mint the httpOnly Mercure subscriber cookie
 * (when absent) so the browser's EventSource can authenticate to the hub.
 */
export async function middleware(request: NextRequest): Promise<NextResponse> {
  const { pathname } = request.nextUrl;
  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  const isPublic = PUBLIC_PATHS.some(
    (path) => pathname === path || pathname.startsWith(`${path}/`),
  );

  if (!token) {
    if (isPublic) return NextResponse.next();
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }

  if (pathname === "/login") {
    const url = request.nextUrl.clone();
    url.pathname = "/";
    return NextResponse.redirect(url);
  }

  const response = NextResponse.next();
  if (!isPublic && !request.cookies.get(MERCURE_COOKIE)) {
    await attachMercureCookie(request, response, token);
  }
  return response;
}

async function attachMercureCookie(
  request: NextRequest,
  response: NextResponse,
  token: string,
): Promise<void> {
  const secret = process.env.MERCURE_JWT_SECRET;
  const publicUrl = process.env.MERCURE_PUBLIC_URL;
  if (!secret || !publicUrl) return;

  let hubHost: string;
  try {
    hubHost = new URL(publicUrl).hostname;
  } catch {
    return;
  }

  let subject = "pulse";
  try {
    const payload = decodeJwt(token);
    if (typeof payload.username === "string" && payload.username) subject = payload.username;
    else if (typeof payload.sub === "string" && payload.sub) subject = payload.sub;
  } catch {
    // A token we cannot decode still yields a valid subscriber token via the
    // fallback subject; the hub does not care what the subject is.
  }

  let jwt: string;
  try {
    jwt = await mintSubscriberToken({
      secret,
      audience: publicUrl,
      subject,
      topics: [MERCURE_TOPIC],
      ttlSeconds: MERCURE_TTL_SECONDS,
    });
  } catch {
    return;
  }

  const appHost = (request.headers.get("host") ?? request.nextUrl.host).split(":")[0];
  response.cookies.set(MERCURE_COOKIE, jwt, {
    httpOnly: true,
    sameSite: "lax",
    secure: request.nextUrl.protocol === "https:",
    path: "/",
    domain: mercureCookieDomain(appHost, hubHost),
    maxAge: MERCURE_TTL_SECONDS,
  });
}

export const config = {
  // Run on everything except Next internals and static asset files.
  matcher: [
    "/((?!_next/static|_next/image|favicon.ico|icon.svg|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico)$).*)",
  ],
};
