import { type APIRequestContext, expect } from "@playwright/test";

import { API_URL, OPERATOR } from "../constants";

// Seed preconditions over the backend API, then drive the UI. Creating a monitor by
// walking the New Monitor dialog every time would couple a spec to unrelated screens
// and be slow; the specs that actually test the dialog do walk it, the rest seed here.
//
// This talks to the API directly from the test process — it is never browser traffic,
// so the "no browser API calls" spec (which watches page.on("request")) is unaffected.

const JSON_API = "application/vnd.api+json";

/** Exchange the operator's credentials for a bearer token at the API. */
export async function apiToken(request: APIRequestContext): Promise<string> {
  const response = await request.post(`${API_URL}/auth/login`, {
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    data: { username: OPERATOR.username, password: OPERATOR.password },
  });
  expect(response.ok(), `login for ${OPERATOR.username} failed (${response.status()})`).toBeTruthy();
  const body = (await response.json()) as { token?: string };
  expect(typeof body.token, "login response carried no token").toBe("string");
  return body.token as string;
}

interface SeedOptions {
  name: string;
  url: string;
  intervalSeconds?: number;
  timeoutMs?: number;
  enabled?: boolean;
}

export interface SeededMonitor {
  /** The bare UUID, ready for the /monitors/<id> route. */
  id: string;
  name: string;
  url: string;
}

/** Create a monitor over the API and return its UUID. */
export async function seedMonitor(
  request: APIRequestContext,
  token: string,
  options: SeedOptions,
): Promise<SeededMonitor> {
  const response = await request.post(`${API_URL}/api/monitors`, {
    headers: { "Content-Type": JSON_API, Accept: JSON_API, Authorization: `Bearer ${token}` },
    data: {
      data: {
        type: "Monitor",
        // API Platform reserves `type` for the resource type, so the check type
        // travels as `_type` — the same convention the frontend uses over the wire.
        attributes: {
          name: options.name,
          url: options.url,
          _type: "http",
          intervalSeconds: options.intervalSeconds ?? 60,
          timeoutMs: options.timeoutMs ?? 8000,
          enabled: options.enabled ?? true,
        },
      },
    },
  });
  expect(response.status(), `seeding monitor "${options.name}" failed: ${await response.text()}`).toBe(201);
  const doc = (await response.json()) as { data: { id: string } };
  // API Platform serves the id as an IRI (/api/monitors/<uuid>); the route wants the UUID.
  const id = doc.data.id.split("/").pop() as string;
  return { id, name: options.name, url: options.url };
}
