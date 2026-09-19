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

/** An operator of the instance, offered as an alert recipient. Read-only. */
export interface InstanceUser extends ApiResource {
  username: string;
  email: string;
}

/** A monitor group. Read-only over the API in this phase. */
export interface MonitorGroup extends ApiResource {
  name: string;
}
