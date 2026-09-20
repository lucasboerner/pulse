import { z } from "zod";
import type { Monitor } from "@/features/monitor/types";

// The interval choices the form offers. The 15-second minimum is enforced here
// (the select cannot produce anything smaller) and again server-side.
export const INTERVAL_OPTIONS = [
  { value: "15", label: "Every 15s" },
  { value: "30", label: "Every 30s" },
  { value: "60", label: "Every 60s" },
  { value: "300", label: "Every 5m" },
  { value: "900", label: "Every 15m" },
] as const;

// Only HTTP ships in version 1 — but it is a real select, not a hidden field, so
// the reserved room for other check types is visible.
export const CHECK_TYPE_OPTIONS = [{ value: "http", label: "HTTP" }] as const;

export const DEFAULT_TIMEOUT_MS = 2000;

const intervalValues = INTERVAL_OPTIONS.map((option) => option.value) as [
  string,
  ...string[],
];

// One schema, two consumers: the client via zodResolver and the Server Action's
// re-run. Field names match the API's `source.pointer` last segment so a 422 maps
// straight back onto the field (name, url, intervalSeconds, timeoutMs).
export const monitorFormSchema = z.object({
  name: z.string().trim().min(1, "Name is required."),
  url: z.string().trim().min(1, "Host or URL is required."),
  checkType: z.enum(["http"]),
  intervalSeconds: z.enum(intervalValues),
  timeoutMs: z
    .string()
    .trim()
    .min(1, "Timeout is required.")
    .refine(
      (value) => /^\d+$/.test(value) && Number.parseInt(value, 10) > 0,
      "Timeout must be a positive number of milliseconds.",
    ),
  enabled: z.boolean(),
  subscribers: z.array(z.string()),
});

export type MonitorFormValues = z.infer<typeof monitorFormSchema>;

/** The default values for a new monitor, with the current operator pre-selected. */
export function newMonitorDefaults(currentUserId: string | null): MonitorFormValues {
  return {
    name: "",
    url: "",
    checkType: "http",
    intervalSeconds: "60",
    timeoutMs: String(DEFAULT_TIMEOUT_MS),
    enabled: true,
    subscribers: currentUserId ? [currentUserId] : [],
  };
}

/** Prefills the form from an existing monitor for the Edit modal. */
export function monitorToFormValues(monitor: Monitor): MonitorFormValues {
  const interval = String(monitor.intervalSeconds);
  return {
    name: monitor.name,
    url: monitor.url,
    checkType: "http",
    intervalSeconds: intervalValues.includes(interval) ? interval : "60",
    timeoutMs: String(monitor.timeoutMs),
    enabled: monitor.enabled,
    subscribers: monitor.subscribers,
  };
}

// FormValues → the API's JSON:API `attributes`. The check type is written as
// `_type` (JSON:API reserves `type`); trims and integer coercion happen here.
export function toMonitorAttributes(values: MonitorFormValues): Record<string, unknown> {
  return {
    name: values.name.trim(),
    url: values.url.trim(),
    _type: values.checkType,
    intervalSeconds: Number.parseInt(values.intervalSeconds, 10),
    timeoutMs: Number.parseInt(values.timeoutMs, 10),
    enabled: values.enabled,
  };
}

// FormValues.subscribers (UUIDs) → the JSON:API to-many relationship. An empty
// list is legal and replaces the whole set on update (zero subscribers is a
// state the interface makes visible rather than validates away).
export function toSubscribersRelationship(subscribers: string[]): Record<string, unknown> {
  return {
    subscribers: {
      data: subscribers.map((id) => ({ type: "User", id: `/api/users/${id}` })),
    },
  };
}
