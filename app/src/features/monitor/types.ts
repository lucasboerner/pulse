import type { ApiResource } from "@/types/api";

// The check type. Only HTTP ships in version 1; the enum leaves room for the
// domain-expiry, TLS and DNS checks the backend reserves.
export type CheckType = "http";

// A monitor's last observed status. Null is the *Pending* state of a monitor
// whose first check has not landed yet. Degraded exists in the mapping but the
// backend never emits it.
export type CheckStatus = "up" | "down" | "degraded";

/**
 * A monitor as the rest of the app consumes it (flattened from JSON:API).
 *
 * JSON:API reserves `type` for the resource type ("Monitor"), so the monitor's
 * own check type arrives as `_type` over the wire and stays `_type` after
 * flattening. Relationships (`monitorGroup`, `subscribers`) are UUID strings.
 * `region`, `nextCheckAt`, `lastCheckedAt` and `lastStatus` are owned by the
 * check pipeline and are read-only here. `downIntervalSeconds` null means a
 * failing monitor keeps its regular interval.
 */
export interface Monitor extends ApiResource {
  name: string;
  url: string;
  _type: CheckType;
  intervalSeconds: number;
  downIntervalSeconds: number | null;
  timeoutMs: number;
  expectedStatusCode: number | null;
  enabled: boolean;
  region: string;
  nextCheckAt: string | null;
  lastCheckedAt: string | null;
  lastStatus: CheckStatus | null;
  monitorGroup: string | null;
  subscribers: string[];
}

// An incident's severity. Degraded has a place in the model but nothing emits it
// in version 1, mirroring CheckStatus.
export type IncidentSeverity = "down" | "degraded";

/**
 * One outage for a monitor (flattened from JSON:API). `endedAt` null means the
 * incident is still ongoing; the Ongoing / Resolved labels and the duration are
 * derived from the two timestamps. `monitor` is the owning monitor's UUID.
 */
export interface Incident extends ApiResource {
  startedAt: string;
  endedAt: string | null;
  severity: IncidentSeverity;
  cause: string | null;
  monitor: string;
}

/** One point in the 24-hour response series: a half-hour bucket. A bucket with no
 *  check carries a null latency and a null status, which breaks the chart line. */
export interface HistoryBucket {
  bucketStart: string;
  medianLatencyMs: number | null;
  status: CheckStatus | null;
}

/** One raw check row for the recent-checks table. */
export interface RecentCheck {
  checkedAt: string;
  status: CheckStatus;
  httpStatusCode: number | null;
  latencyMs: number | null;
  errorMessage: string | null;
}

/**
 * One day of a monitor's status strip. `status` is the worst status observed that
 * day; null means no check landed that day (a short grey bar). Nulls INSIDE this
 * array are preserved by JSON:API (only null *top-level* attributes are dropped).
 */
export interface DailyStatus {
  day: string;
  status: CheckStatus | null;
}

/**
 * A monitor's aggregate rollup for the overview "All Systems" list (a nested
 * object inside the metrics resource, so it is not flattened — its fields arrive
 * exactly as sent). `avgLatencyMs` and `uptimeRatio30d` are null when the 30-day
 * window holds no check with a latency; `lastStatus` null is the pending state.
 */
export interface MonitorRollup {
  monitorId: string;
  name: string;
  url: string;
  enabled: boolean;
  lastStatus: CheckStatus | null;
  avgLatencyMs: number | null;
  uptimeRatio30d: number | null;
  dailyStatus: DailyStatus[];
}

/**
 * One entry in the overview's recent-incidents feed. `endedAt` null means the
 * incident is still open; a null top-level attribute would flatten to undefined,
 * but this rides inside an array so it stays null — still, compare with `== null`.
 * `monitorId` is the owning monitor's UUID, so the feed can link back to it.
 */
export interface IncidentSummary {
  monitorId: string;
  monitorName: string;
  severity: IncidentSeverity;
  startedAt: string;
  endedAt: string | null;
  cause: string | null;
}

/**
 * One incident as the detail dialog reads it — the common denominator of the
 * overview's `IncidentSummary` and the detail page's full `Incident`, which carry
 * the monitor's name and its id respectively. `monitorId` is null when the
 * incident is already shown on that monitor's own page, which suppresses the
 * dialog's link back to it.
 */
export interface IncidentDetail {
  monitorId: string | null;
  monitorName: string;
  severity: IncidentSeverity;
  startedAt: string;
  endedAt: string | null;
  cause: string | null;
}

/**
 * The overview's fleet-wide aggregate read (flattened from JSON:API): the four
 * headline counts, aggregate 30-day uptime and average response, the open-incident
 * count, a cross-monitor 24h response series, the per-monitor rollups for the
 * "All Systems" list and the newest incidents. Aggregate latency/uptime are null
 * when no check (or no check with a latency) falls in the window.
 */
export interface MetricsSummary extends ApiResource {
  monitorsTotal: number;
  monitorsUp: number;
  monitorsPaused: number;
  needingAttention: number;
  uptimeRatio30d: number | null;
  avgResponseMs: number | null;
  openIncidents: number;
  responseSeries: HistoryBucket[];
  monitors: MonitorRollup[];
  recentIncidents: IncidentSummary[];
}

/**
 * The detail page's aggregate read (flattened from JSON:API): the 24-hour window,
 * its counts and uptime, the 7-day and 30-day uptime ratios, the median and p95
 * latency, the 48-bucket chart series, the 90-day daily status strip, the newest
 * raw checks and the 30-day incident count. Latency and ratio fields are null when
 * the window holds no check (or no check with a latency).
 */
export interface MonitorHistory extends ApiResource {
  windowStart: string;
  windowEnd: string;
  checkCount: number;
  upCount: number;
  degradedCount: number;
  downCount: number;
  uptimeRatio: number | null;
  uptimeRatio7d: number | null;
  uptimeRatio30d: number | null;
  medianLatencyMs: number | null;
  p95LatencyMs: number | null;
  series: HistoryBucket[];
  /** 90 entries, worst-status-wins per day; null status = no check that day. */
  dailyStatus: DailyStatus[];
  recentChecks: RecentCheck[];
  incidentCount30d: number;
}

/** An operator of the instance, offered as an alert recipient. Read-only. */
export interface InstanceUser extends ApiResource {
  username: string;
  email: string;
}

/** A monitor group. Read-only over the API in this phase. */
export interface MonitorGroup extends ApiResource {
  name: string;
}
