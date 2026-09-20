import type { IncidentSeverity } from "@/features/monitor/types";
import { formatDuration } from "@/features/monitor/lib/status";

/** The fields every incident shape carries — the detail page's full `Incident`, the
 *  overview's `IncidentSummary` and the dialog's `IncidentDetail` alike. */
interface IncidentLike {
  severity: IncidentSeverity;
  startedAt: string;
  endedAt: string | null;
}

export interface IncidentState {
  label: string;
  variant: "destructive" | "warning" | "success";
}

/**
 * How an incident reads on screen: an open one carries its live severity, a closed
 * one always reads as resolved. `endedAt` null (or, on an ongoing incident read
 * from a top-level attribute, absent) is the open marker — compare loosely.
 */
export function incidentState(incident: IncidentLike): IncidentState {
  if (incident.endedAt != null) {
    return { label: "Resolved", variant: "success" };
  }
  return incident.severity === "down"
    ? { label: "Ongoing", variant: "destructive" }
    : { label: "Degraded", variant: "warning" };
}

/**
 * How long the incident ran, measured against `now` while it is still open. Pass
 * the request-time clock the page already threads around, so SSR and hydration
 * produce the identical string.
 */
export function incidentDuration(incident: IncidentLike, now: number): string {
  const started = new Date(incident.startedAt).getTime();
  const ended = incident.endedAt == null ? now : new Date(incident.endedAt).getTime();
  return formatDuration((ended - started) / 1000);
}

/** The one-line summary under the dialog title and in the history card's entries. */
export function incidentHeadline(incident: IncidentLike, now: number): string {
  const duration = incidentDuration(incident, now);
  return incident.endedAt == null
    ? `Ongoing — ${incident.severity} for ${duration}`
    : `Resolved after ${duration}`;
}
