"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import type { CheckStatus, Monitor } from "@/features/monitor/types";

// A live status update pushed over Mercure: the monitor's identifier, its new last
// status (null while a first check is still pending) and when it was checked. This
// is the data the worker publishes — consumed directly, never used as a bare signal
// to refetch, so a row flips in place without a navigation or reload.
export interface MonitorLiveUpdate {
  id: string;
  status: CheckStatus | null;
  checkedAt: string | null;
}

interface Override {
  status: CheckStatus | null;
  checkedAt: string | null;
}

const ApplyContext = createContext<((update: MonitorLiveUpdate) => void) | null>(null);
const OverridesContext = createContext<ReadonlyMap<string, Override>>(new Map());

/**
 * Holds the live status overrides the Mercure listener pushes in, keyed by monitor
 * id. A page merges them onto the monitors its Server Component delivered with
 * useLiveMonitors, so a pushed check result flips a dot and its count together, in
 * place — no refetch, no navigation, no reload.
 */
export function MonitorLiveProvider({ children }: { children: ReactNode }) {
  const [overrides, setOverrides] = useState<ReadonlyMap<string, Override>>(() => new Map());

  const apply = useCallback((update: MonitorLiveUpdate) => {
    setOverrides((current) => {
      const previous = current.get(update.id);
      if (previous && previous.status === update.status && previous.checkedAt === update.checkedAt) {
        return current; // Unchanged — keep the reference stable so nothing re-renders.
      }
      const next = new Map(current);
      next.set(update.id, { status: update.status, checkedAt: update.checkedAt });
      return next;
    });
  }, []);

  return (
    <ApplyContext.Provider value={apply}>
      <OverridesContext.Provider value={overrides}>{children}</OverridesContext.Provider>
    </ApplyContext.Provider>
  );
}

/** The Mercure listener's handle for pushing a received update into the store. */
export function useApplyMonitorUpdate(): (update: MonitorLiveUpdate) => void {
  const apply = useContext(ApplyContext);
  if (!apply) {
    throw new Error("useApplyMonitorUpdate must be used within a MonitorLiveProvider");
  }
  return apply;
}

/**
 * Merges the live overrides onto the monitors a Server Component delivered. Only the
 * status and last-check time are pushed; every other field stays authoritative from
 * the server render. A monitor with no override is returned unchanged.
 */
export function useLiveMonitors(monitors: Monitor[]): Monitor[] {
  const overrides = useContext(OverridesContext);
  return useMemo(() => {
    if (overrides.size === 0) return monitors;
    return monitors.map((monitor) => {
      const override = overrides.get(monitor.id);
      if (!override) return monitor;
      if (override.status === monitor.lastStatus && override.checkedAt === monitor.lastCheckedAt) {
        return monitor;
      }
      return { ...monitor, lastStatus: override.status, lastCheckedAt: override.checkedAt };
    });
  }, [monitors, overrides]);
}
