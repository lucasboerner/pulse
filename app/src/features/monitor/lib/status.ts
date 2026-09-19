import type { CheckStatus } from "@/features/monitor/types";

// What a monitor reads as on screen. A paused monitor (enabled === false) and a
// pending monitor (no check has landed, lastStatus === null) are display states
// layered on top of the backend's up/down/degraded.
export type DisplayStatus = "up" | "down" | "degraded" | "paused" | "pending";

export type StatusBadgeVariant =
  | "success"
  | "warning"
  | "destructive"
  | "outline"
  | "default";

interface StatusMeta {
  label: string;
  badge: StatusBadgeVariant;
  /** The detail banner's headline sentence for this status. */
  headline: string;
}

const META: Record<DisplayStatus, StatusMeta> = {
  up: { label: "Up", badge: "success", headline: "All checks passing" },
  degraded: { label: "Degraded", badge: "warning", headline: "Degraded responses" },
  down: { label: "Down", badge: "destructive", headline: "Not responding" },
  paused: { label: "Paused", badge: "default", headline: "Checks paused" },
  pending: { label: "Pending", badge: "outline", headline: "Waiting for the first check" },
};

/**
 * The display status for a monitor: paused → pending → its last check status.
 * Takes just the two fields it reads, so it serves a full `Monitor` and a
 * `MonitorRollup` (or any live-merged shape) alike.
 */
export function displayStatus(monitor: {
  enabled: boolean;
  lastStatus: CheckStatus | null;
}): DisplayStatus {
  if (!monitor.enabled) return "paused";
  if (monitor.lastStatus == null) return "pending";
  return monitor.lastStatus;
}

export function statusMeta(status: DisplayStatus): StatusMeta {
  return META[status];
}

/** The detail banner's headline sentence for a display status. */
export function statusHeadline(status: DisplayStatus): string {
  return META[status].headline;
}

/**
 * The dot colour for a display status. Status hue lives only in the dot, the
 * badge tint and (elsewhere) an HTTP code — never a row or card fill. Paused and
 * pending both read as muted.
 */
export function statusDotClass(status: DisplayStatus): string {
  switch (status) {
    case "up":
      return "bg-success";
    case "degraded":
      return "bg-warning";
    case "down":
      return "bg-destructive";
    default:
      return "bg-muted-foreground";
  }
}

/** The host of a monitor's target, for the second line under its name. */
export function hostFromUrl(url: string): string {
  try {
    return new URL(url).host || url;
  } catch {
    // Bare host / IP targets have no scheme, so URL() throws — show them as-is.
    return url;
  }
}

/** A compact interval label: 15 → "15s", 300 → "5m", 3600 → "1h". */
export function intervalLabel(seconds: number): string {
  if (seconds < 120) return `${seconds}s`;
  if (seconds % 3600 === 0) return `${seconds / 3600}h`;
  if (seconds % 60 === 0) return `${seconds / 60}m`;
  return `${seconds}s`;
}

/**
 * A relative timestamp for the last check. Pass a fixed `now` (from the server
 * render) when the value is shown inside a client component, so SSR and hydration
 * produce the identical string; it refreshes when the page re-reads on a Mercure
 * signal. Server components can rely on the default.
 */
export function relativeTime(iso: string | null, now: number = Date.now()): string {
  if (!iso) return "never";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "never";
  const seconds = Math.max(0, Math.round((now - then) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.round(hours / 24)}d ago`;
}

const MONTHS = [
  "JAN", "FEB", "MAR", "APR", "MAY", "JUN",
  "JUL", "AUG", "SEP", "OCT", "NOV", "DEC",
] as const;

/**
 * An absolute UTC timestamp in the incident log's style — `14 SEP 04:24 UTC`.
 * The incident history renders times in UTC to match the mock; relative times
 * elsewhere keep `relativeTime`.
 */
export function formatUtcTimestamp(iso: string | null): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  const day = String(date.getUTCDate()).padStart(2, "0");
  const month = MONTHS[date.getUTCMonth()];
  const hours = String(date.getUTCHours()).padStart(2, "0");
  const minutes = String(date.getUTCMinutes()).padStart(2, "0");
  return `${day} ${month} ${hours}:${minutes} UTC`;
}

/**
 * A compact duration from a count of seconds — `45s`, `6m 12s`, `2h 5m`, `1d 3h`.
 * Two units at most; the largest two that apply.
 */
export function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.floor(seconds));
  if (total < 60) return `${total}s`;
  const minutes = Math.floor(total / 60);
  if (minutes < 60) return `${minutes}m ${total % 60}s`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ${minutes % 60}m`;
  const days = Math.floor(hours / 24);
  return `${days}d ${hours % 24}h`;
}
