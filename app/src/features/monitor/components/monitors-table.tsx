"use client";

import { useMemo, useState } from "react";
import { SearchIcon } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { displayStatus, hostFromUrl } from "@/features/monitor/lib/status";
import { useLiveMonitors } from "@/features/monitor/live/monitor-live-context";
import { MonitorRow, MONITOR_TABLE_GRID } from "@/features/monitor/components/monitor-row";
import { cn } from "@/lib/utils";

const STATUS_FILTERS = [
  { value: "all", label: "All statuses" },
  { value: "up", label: "Up" },
  { value: "down", label: "Down" },
  { value: "degraded", label: "Degraded" },
  { value: "paused", label: "Paused" },
  { value: "pending", label: "Pending" },
] as const;

interface MonitorsTableProps {
  monitors: Monitor[];
  users: InstanceUser[];
  currentUserId: string | null;
  now: number;
}

// Search and the status filter are client state over the rows the Server
// Component already delivered — no refetch. The "no match" empty state carries
// Clear Filters.
export function MonitorsTable({ monitors, users, currentUserId, now }: MonitorsTableProps) {
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<string>("all");

  // Merge in any live status pushed over Mercure before filtering, so a row flips
  // and the status filter both track the pushed check result in place.
  const live = useLiveMonitors(monitors);

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    return live.filter((monitor) => {
      if (status !== "all" && displayStatus(monitor) !== status) return false;
      if (!term) return true;
      return (
        monitor.name.toLowerCase().includes(term) ||
        monitor.url.toLowerCase().includes(term) ||
        hostFromUrl(monitor.url).toLowerCase().includes(term)
      );
    });
  }, [live, query, status]);

  const isFiltering = query.trim() !== "" || status !== "all";

  function clearFilters() {
    setQuery("");
    setStatus("all");
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative w-[280px] max-w-full">
          <SearchIcon className="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search name or host"
            className="pl-8"
          />
        </div>
        <div className="w-[170px]">
          <Select value={status} onValueChange={setStatus}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {STATUS_FILTERS.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <span className="ml-auto text-[12px] tabular-nums text-muted-foreground">
          {filtered.length} of {live.length}
        </span>
      </div>

      <div className="overflow-hidden rounded-lg border border-border bg-card">
        <div className="overflow-x-auto">
          <div className="min-w-[820px]">
            <div
              className={cn(
                "grid h-[38px] items-center gap-3 border-b border-border bg-sidebar px-4 text-[11px] tracking-[0.08em] uppercase text-muted-foreground",
                MONITOR_TABLE_GRID,
              )}
            >
              <span>Monitor</span>
              <span>Type</span>
              <span>Status</span>
              <span>Interval</span>
              <span>Last Checked</span>
              <span className="text-right">Actions</span>
            </div>

            {filtered.length === 0 ? (
              <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  {isFiltering ? "No monitors match this filter." : "No monitors yet."}
                </p>
                {isFiltering ? (
                  <Button variant="outline" size="sm" onClick={clearFilters}>
                    Clear Filters
                  </Button>
                ) : null}
              </div>
            ) : (
              filtered.map((monitor) => (
                <MonitorRow
                  key={monitor.id}
                  monitor={monitor}
                  users={users}
                  currentUserId={currentUserId}
                  now={now}
                />
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
