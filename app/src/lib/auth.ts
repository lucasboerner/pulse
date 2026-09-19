import { cookies } from "next/headers";
import { decodeJwt } from "jose";

// The JSON Web Token lives in an httpOnly cookie that JavaScript never touches.
// The data layer (client.ts / actions.ts) is the only code that reads it and
// forwards it as an Authorization bearer; no component sees it.
export const TOKEN_COOKIE = "pulse_token";

// The Mercure subscriber token — a separate, httpOnly cookie the browser's
// EventSource sends to the hub. Minted in middleware, cleared here on logout.
export const MERCURE_COOKIE = "mercureAuthorization";

const isProduction = process.env.NODE_ENV === "production";

/** The bearer token for the current request, if the operator is signed in. */
export async function getToken(): Promise<string | undefined> {
  const store = await cookies();
  return store.get(TOKEN_COOKIE)?.value;
}

/**
 * Stores the token in an httpOnly, SameSite=Lax cookie, Secure in production,
 * with its expiry taken from the token's own `exp` claim. Called from the login
 * Server Action.
 */
export async function setSessionCookie(token: string): Promise<void> {
  const store = await cookies();
  let expires: Date | undefined;
  try {
    const { exp } = decodeJwt(token);
    if (typeof exp === "number") expires = new Date(exp * 1000);
  } catch {
    // A token we cannot decode still gets stored; the API is the authority and
    // will reject it, which the central 401 handling turns into a re-login.
  }

  store.set(TOKEN_COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    secure: isProduction,
    path: "/",
    expires,
  });
}

/**
 * The signed-in operator's username, decoded from the token. Used to resolve the
 * current operator in the users collection so the monitor form can pre-select
 * them as a subscriber. The API stays the authority; this is convenience only.
 */
export async function getCurrentUsername(): Promise<string | null> {
  const token = await getToken();
  if (!token) return null;
  try {
    const payload = decodeJwt(token);
    if (typeof payload.username === "string" && payload.username) return payload.username;
    if (typeof payload.sub === "string" && payload.sub) return payload.sub;
  } catch {
    return null;
  }
  return null;
}

/** Clears the session — both the API token and the Mercure subscriber token. */
export async function clearSession(): Promise<void> {
  const store = await cookies();
  store.delete(TOKEN_COOKIE);
  store.delete(MERCURE_COOKIE);
}
