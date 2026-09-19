"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { z } from "zod";

import { getToken, setSessionCookie, clearSession } from "@/lib/auth";
import { JSON_API_MEDIA_TYPE, flattenResource } from "@/lib/api/client";
import type { ActionResult, JsonApiError } from "@/types/api";
import type { Monitor } from "@/features/monitor/types";
import {
  monitorFormSchema,
  toMonitorAttributes,
  toSubscribersRelationship,
  type MonitorFormValues,
} from "@/features/monitor/lib/validation";
import { loginFormSchema, type LoginFormValues } from "@/features/auth/lib/validation";

const INTERNAL_API_URL = process.env.API_INTERNAL_URL ?? "http://api";
const UNREACHABLE = "Could not reach the server. Please try again.";
const GENERIC_FAILURE = "The request failed. Please try again.";

// ── Authentication ────────────────────────────────────────────────────────

export async function loginAction(values: LoginFormValues): Promise<ActionResult<never>> {
  const parsed = loginFormSchema.safeParse(values);
  if (!parsed.success) return { fieldErrors: zodFieldErrors(parsed.error) };

  let response: Response;
  try {
    response = await fetch(`${INTERNAL_API_URL}/auth/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        username: parsed.data.username,
        password: parsed.data.password,
      }),
      cache: "no-store",
    });
  } catch {
    return { error: UNREACHABLE };
  }

  // A missing user and a wrong password read identically — one generic message.
  if (response.status === 401) return { error: "Invalid credentials." };
  if (!response.ok) return { error: GENERIC_FAILURE };

  const body = (await response.json().catch(() => null)) as { token?: unknown } | null;
  if (typeof body?.token !== "string") return { error: GENERIC_FAILURE };

  await setSessionCookie(body.token);
  redirect("/");
}

export async function logoutAction(): Promise<void> {
  await clearSession();
  redirect("/login");
}

// ── Monitors ──────────────────────────────────────────────────────────────

export async function createMonitorAction(
  values: MonitorFormValues,
): Promise<ActionResult<Monitor>> {
  const parsed = monitorFormSchema.safeParse(values);
  if (!parsed.success) return { fieldErrors: zodFieldErrors(parsed.error) };

  const result = await mutateMonitor({
    path: "/api/monitors",
    method: "POST",
    attributes: toMonitorAttributes(parsed.data),
    relationships: toSubscribersRelationship(parsed.data.subscribers),
  });
  if (result.data) revalidateMonitorViews(result.data.id);
  return result;
}

export async function updateMonitorAction(
  id: string,
  values: MonitorFormValues,
): Promise<ActionResult<Monitor>> {
  const parsed = monitorFormSchema.safeParse(values);
  if (!parsed.success) return { fieldErrors: zodFieldErrors(parsed.error) };

  const result = await mutateMonitor({
    path: `/api/monitors/${id}`,
    method: "PATCH",
    id: `/api/monitors/${id}`,
    attributes: toMonitorAttributes(parsed.data),
    relationships: toSubscribersRelationship(parsed.data.subscribers),
  });
  if (result.data) revalidateMonitorViews(id);
  return result;
}

/** The row switch: pause or resume a monitor without touching its history. */
export async function setMonitorEnabledAction(
  id: string,
  enabled: boolean,
): Promise<ActionResult<Monitor>> {
  const result = await mutateMonitor({
    path: `/api/monitors/${id}`,
    method: "PATCH",
    id: `/api/monitors/${id}`,
    attributes: { enabled },
  });
  if (result.data) revalidateMonitorViews(id);
  return result;
}

/**
 * The detail page's subscribe/unsubscribe toggle for the signed-in operator. It
 * PATCHes only the subscriber relationship — every other field is left untouched —
 * adding or removing the operator against the monitor's current recipient set. The
 * full recipient list is still edited in the Edit modal.
 */
export async function setMonitorSubscriptionAction(
  id: string,
  operatorId: string,
  subscribed: boolean,
  currentSubscriberIds: string[],
): Promise<ActionResult<Monitor>> {
  const next = subscribed
    ? Array.from(new Set([...currentSubscriberIds, operatorId]))
    : currentSubscriberIds.filter((entry) => entry !== operatorId);

  const result = await mutateMonitor({
    path: `/api/monitors/${id}`,
    method: "PATCH",
    id: `/api/monitors/${id}`,
    relationships: toSubscribersRelationship(next),
  });
  if (result.data) revalidateMonitorViews(id);
  return result;
}

export async function deleteMonitorAction(id: string): Promise<ActionResult<{ id: string }>> {
  const token = await getToken();
  let response: Response;
  try {
    response = await fetch(`${INTERNAL_API_URL}/api/monitors/${id}`, {
      method: "DELETE",
      headers: {
        Accept: JSON_API_MEDIA_TYPE,
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      cache: "no-store",
    });
  } catch {
    return { error: UNREACHABLE };
  }

  if (response.status === 401) redirect("/logout");
  if (response.status !== 204 && !response.ok) return { error: GENERIC_FAILURE };

  revalidateMonitorViews(id);
  return { data: { id } };
}

// ── Internals ───────────────────────────────────────────────────────────────

interface MutateArgs {
  path: string;
  method: "POST" | "PATCH";
  id?: string;
  attributes?: Record<string, unknown>;
  relationships?: Record<string, unknown>;
}

/**
 * Sends a JSON:API document for a monitor write. Both POST and PATCH use the
 * `application/vnd.api+json` document shape; a 401 bounces through /logout, a 422
 * maps back onto the form fields via `source.pointer`, and the created/updated
 * resource is flattened for the success path.
 */
async function mutateMonitor(args: MutateArgs): Promise<ActionResult<Monitor>> {
  const token = await getToken();
  const data: Record<string, unknown> = { type: "Monitor" };
  if (args.id) data.id = args.id;
  if (args.attributes) data.attributes = args.attributes;
  if (args.relationships) data.relationships = args.relationships;

  let response: Response;
  try {
    response = await fetch(`${INTERNAL_API_URL}${args.path}`, {
      method: args.method,
      headers: {
        "Content-Type": JSON_API_MEDIA_TYPE,
        Accept: JSON_API_MEDIA_TYPE,
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ data }),
      cache: "no-store",
    });
  } catch {
    return { error: UNREACHABLE };
  }

  if (response.status === 401) redirect("/logout");

  if (response.status === 422) {
    const doc = (await response.json().catch(() => null)) as { errors?: JsonApiError[] } | null;
    const fieldErrors = mapJsonApiErrors(doc);
    if (Object.keys(fieldErrors).length > 0) return { fieldErrors };
    return { error: doc?.errors?.[0]?.detail ?? "Validation failed." };
  }

  if (!response.ok) return { error: GENERIC_FAILURE };

  const doc = (await response.json().catch(() => null)) as { data?: unknown } | null;
  if (!doc?.data) return { error: "The server returned an unexpected response." };
  return { data: flattenResource<Monitor>(doc.data as Parameters<typeof flattenResource>[0]) };
}

// A monitor write can change the overview, the table and — for an existing
// monitor — its detail page, so revalidate all three. Create has no detail page
// in cache yet, but passing the fresh id is harmless.
function revalidateMonitorViews(id?: string): void {
  revalidatePath("/");
  revalidatePath("/monitors");
  if (id) revalidatePath(`/monitors/${id}`);
}

// Maps a JSON:API error document onto per-field messages. The pointer looks like
// `data/attributes/url`; its last segment is the form field name.
function mapJsonApiErrors(doc: { errors?: JsonApiError[] } | null): Record<string, string> {
  const fieldErrors: Record<string, string> = {};
  for (const error of doc?.errors ?? []) {
    const pointer = error.source?.pointer;
    if (!pointer) continue;
    const field = pointer.split("/").filter(Boolean).pop();
    if (field && !fieldErrors[field]) fieldErrors[field] = error.detail ?? "Invalid value.";
  }
  return fieldErrors;
}

function zodFieldErrors(error: z.ZodError): Record<string, string> {
  const fieldErrors: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = issue.path.join(".");
    if (key && !fieldErrors[key]) fieldErrors[key] = issue.message;
  }
  return fieldErrors;
}
