"use client";

import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import type { IncidentSummary } from "@/features/monitor/types";
import { formatUtcTimestamp } from "@/features/monitor/lib/status";
import { incidentState } from "@/features/monitor/lib/incident";
import { IncidentDetailDialog } from "@/features/monitor/components/incident-detail-dialog";

interface OverviewIncidentRowProps {
  incident: IncidentSummary;
  now: number;
}

// One entry in the overview's recent-incidents feed: the monitor's name, a state
// badge, and a muted detail line of the cause and the incident's start time. The
// whole row is a button that opens the incident's detail dialog — which is where
// the full cause and the link to the monitor live.
export function OverviewIncidentRow({ incident, now }: OverviewIncidentRowProps) {
  const [open, setOpen] = useState(false);

  const state = incidentState(incident);
  const when = formatUtcTimestamp(incident.startedAt);
  const detail = incident.cause ? `${incident.cause} · ${when}` : when;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        className="flex w-full items-start justify-between gap-3 px-4 py-2.75 text-left outline-none transition-colors duration-[120ms] hover:bg-accent focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/40 sm:px-5"
      >
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="truncate text-[13px] font-medium">{incident.monitorName}</span>
          <span className="truncate text-[11px] text-muted-foreground" title={detail}>
            {detail}
          </span>
        </span>
        <Badge variant={state.variant} dot>
          {state.label}
        </Badge>
      </button>

      <IncidentDetailDialog incident={incident} now={now} open={open} onOpenChange={setOpen} />
    </>
  );
}
