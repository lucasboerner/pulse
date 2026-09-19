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
 * check pipeline and are read-only here.
 */
export interface Monitor extends ApiResource {
  name: string;
  url: string;
  _type: CheckType;
  intervalSeconds: number;
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
 * The detail page's aggregate read (flattened from JSON:API): the 24-hour window,
 * its counts and uptime, the median and p95 latency, the 48-bucket chart series,
 * the newest raw checks and the 30-day incident count. Latency and ratio fields are
 * null when the window holds no check (or no check with a latency).
 */
export interface MonitorHistory extends ApiResource {
  windowStart: string;
  windowEnd: string;
  checkCount: number;
  upCount: number;
  degradedCount: number;
  downCount: number;
  uptimeRatio: number | null;
  medianLatencyMs: number | null;
  p95LatencyMs: number | null;
  series: HistoryBucket[];
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
