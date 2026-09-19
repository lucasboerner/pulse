"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";

import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { useLiveMonitors } from "@/features/monitor/live/monitor-live-context";
import { MonitorStatusBanner } from "@/features/monitor/components/monitor-status-banner";

// A pushed check result flips the banner immediately; the heavier server-rendered
// regions (chart, checks table, incident list, the header's "checked … ago") catch
// up on a debounced refresh, so a 15-second interval can't cause a refresh storm.
const REFRESH_DEBOUNCE_MS = 3000;

interface MonitorDetailLiveProps {
  monitor: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
  causeLine: string | null;
}

// The banner's live surface: it merges the Mercure override onto the server-rendered
// monitor so the headline and badge flip in place, and when this monitor's status or
// last-check time changes it schedules a router.refresh() so the rest of the page
// re-reads from the server.
export function MonitorDetailLive({
  monitor,
  users,
  currentUserId,
  causeLine,
}: MonitorDetailLiveProps) {
  const router = useRouter();
  const live = useLiveMonitors([monitor])[0] ?? monitor;
  const lastKeyRef = useRef(`${monitor.lastStatus}|${monitor.lastCheckedAt}`);

  useEffect(() => {
    const key = `${live.lastStatus}|${live.lastCheckedAt}`;
    if (key === lastKeyRef.current) return;
    lastKeyRef.current = key;
    const timer = setTimeout(() => router.refresh(), REFRESH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [live.lastStatus, live.lastCheckedAt, router]);

  return (
    <MonitorStatusBanner
      monitor={live}
      users={users}
      currentUserId={currentUserId}
      causeLine={causeLine}
    />
  );
}
