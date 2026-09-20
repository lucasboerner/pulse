"use client";

import { useState } from "react";

import type { Incident, IncidentDetail } from "@/features/monitor/types";
import { formatUtcTimestamp } from "@/features/monitor/lib/status";
import { incidentHeadline } from "@/features/monitor/lib/incident";
import { StatusDot } from "@/features/monitor/components/status-dot";
import { IncidentDetailDialog } from "@/features/monitor/components/incident-detail-dialog";

interface IncidentHistoryRowProps {
  incident: Incident;
  /** The owning monitor's name — the detail dialog's title. The incident payload
   *  carries only the monitor's UUID, and the page already knows the name. */
  monitorName: string;
  now: number;
}

// One entry in the detail page's incident log: a severity-coloured dot, one line of
// text and a muted timestamp line. The whole entry is a button that opens the
// incident's detail dialog, where the cause is shown in full rather than truncated.
// An ongoing incident is measured against the render clock.
export function IncidentHistoryRow({ incident, monitorName, now }: IncidentHistoryRowProps) {
  const [open, setOpen] = useState(false);

  // An ongoing incident omits endedAt from the payload, so it is undefined rather
  // than null — match loosely.
  const ongoing = incident.endedAt == null;
  const timestamps = ongoing
    ? `Started ${formatUtcTimestamp(incident.startedAt)}`
    : `${formatUtcTimestamp(incident.startedAt)} → ${formatUtcTimestamp(incident.endedAt)}`;

  // The dialog already sits on this monitor's page, so it offers no link back to it.
  const detail: IncidentDetail = {
    monitorId: null,
    monitorName,
    severity: incident.severity,
    startedAt: incident.startedAt,
    endedAt: incident.endedAt,
    cause: incident.cause,
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        className="flex w-full items-start gap-3 px-4 py-2.75 text-left outline-none transition-colors duration-[120ms] hover:bg-accent focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/40 sm:px-5"
      >
        <StatusDot status={incident.severity} className="mt-1.5" />
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="text-[13px]">{incidentHeadline(incident, now)}</span>
          <span className="text-[11px] tabular-nums text-muted-foreground">{timestamps}</span>
          {incident.cause ? (
            <span className="truncate text-[11px] text-muted-foreground" title={incident.cause}>
              {incident.cause}
            </span>
          ) : null}
        </span>
      </button>

      <IncidentDetailDialog incident={detail} now={now} open={open} onOpenChange={setOpen} />
    </>
  );
}
