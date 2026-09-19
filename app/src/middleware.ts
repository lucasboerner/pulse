import { NextResponse, type NextRequest } from "next/server";

// Must match lib/auth.ts. Duplicated here because middleware runs in the edge
// runtime and cannot import the next/headers-based helpers.
const TOKEN_COOKIE = "pulse_token";
const PUBLIC_PATHS = ["/login", "/logout"];

/**
 * Route gating is user experience only — the API stays the authority. A missing
 * token bounces to /login; a present token on /login bounces to the overview.
 */
export function middleware(request: NextRequest): NextResponse {
  const { pathname } = request.nextUrl;
  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  const isPublic = PUBLIC_PATHS.some(
    (path) => pathname === path || pathname.startsWith(`${path}/`),
  );

  if (!token) {
    if (isPublic) return NextResponse.next();
    // Carry the requested path (and query) so the login can land the visitor back
    // there — an alert mail's deep link must survive the sign-in bounce. The overview
    // is the default, so `/` is not worth carrying.
    const target = `${request.nextUrl.pathname}${request.nextUrl.search}`;
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    url.search = "";
    if (target && target !== "/") url.searchParams.set("next", target);
    return NextResponse.redirect(url);
  }

  if (pathname === "/login") {
    const url = request.nextUrl.clone();
    url.pathname = "/";
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  // Run on everything except Next internals and static asset files.
  matcher: [
    "/((?!_next/static|_next/image|favicon.ico|icon.svg|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico)$).*)",
  ],
};
