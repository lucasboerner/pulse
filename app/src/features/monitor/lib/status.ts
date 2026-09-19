import type { Monitor } from "@/features/monitor/types";

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
}

const META: Record<DisplayStatus, StatusMeta> = {
  up: { label: "Up", badge: "success" },
  degraded: { label: "Degraded", badge: "warning" },
  down: { label: "Down", badge: "destructive" },
  paused: { label: "Paused", badge: "default" },
  pending: { label: "Pending", badge: "outline" },
};

/** The display status for a monitor: paused → pending → its last check status. */
export function displayStatus(monitor: Monitor): DisplayStatus {
  if (!monitor.enabled) return "paused";
  if (monitor.lastStatus == null) return "pending";
  return monitor.lastStatus;
}

export function statusMeta(status: DisplayStatus): StatusMeta {
  return META[status];
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
