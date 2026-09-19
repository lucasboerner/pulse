import { NextResponse } from "next/server";

import { TOKEN_COOKIE, MERCURE_COOKIE } from "@/lib/auth";

// The central landing for an expired or rejected session: the data layer bounces
// a 401 here so the cookie is cleared before /login, which a straight redirect
// would not do (the middleware gate judges the token only structurally, and would
// send a still-present token back into a loop).
export async function GET(request: Request): Promise<NextResponse> {
  const response = NextResponse.redirect(new URL("/login", request.url));
  response.cookies.delete(TOKEN_COOKIE);
  response.cookies.delete(MERCURE_COOKIE);
  return response;
}
