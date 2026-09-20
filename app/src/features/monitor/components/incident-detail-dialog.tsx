"use client";

import type { ReactNode } from "react";
import Link from "next/link";

import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { IncidentDetail } from "@/features/monitor/types";
import { formatUtcTimestamp } from "@/features/monitor/lib/status";
import {
  incidentDuration,
  incidentHeadline,
  incidentState,
} from "@/features/monitor/lib/incident";

interface IncidentDetailRowProps {
  label: string;
  children: ReactNode;
}

// One label/value row, in the Configuration card's shape: the uppercase micro-label
// on the left, the value right-aligned, a hairline beneath every row but the last.
function IncidentDetailRow({ label, children }: IncidentDetailRowProps) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border py-2.75 last:border-b-0">
      <span className="shrink-0 pt-0.5 text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        {label}
      </span>
      <div className="min-w-0 text-right text-[13px]">{children}</div>
    </div>
  );
}

interface IncidentDetailDialogProps {
  incident: IncidentDetail;
  /** The request-time clock, so an ongoing incident's duration agrees on server and
   *  client — threaded in the way MonitorsTable already does. */
  now: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * The 420px read-only modal behind every incident entry: the monitor's name and the
 * state badge, the one-line headline, then the timestamps, the duration and the full
 * cause — which wraps here rather than being truncated the way the feeds do it.
 *
 * An incident opened from a feed that sits away from its monitor (the overview) also
 * offers a link there; on the monitor's own page `monitorId` is null and the link is
 * left out.
 */
export function IncidentDetailDialog({
  incident,
  now,
  open,
  onOpenChange,
}: IncidentDetailDialogProps) {
  const state = incidentState(incident);
  // An ongoing incident has no end — from a top-level JSON:API attribute it arrives
  // absent rather than null, so match loosely.
  const ongoing = incident.endedAt == null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton={false}
        className="w-full gap-0 border border-border bg-card p-0 shadow-lg sm:max-w-[420px]"
      >
        <div className="flex flex-col gap-2 p-5">
          <div className="flex items-start justify-between gap-3">
            <DialogTitle className="min-w-0 truncate text-[18px] font-semibold">
              {incident.monitorName}
            </DialogTitle>
            <Badge variant={state.variant} dot>
              {state.label}
            </Badge>
          </div>
          <DialogDescription className="text-[13px] leading-normal text-muted-foreground">
            {incidentHeadline(incident, now)}
          </DialogDescription>
        </div>

        <div className="flex flex-col border-t border-border px-5">
          <IncidentDetailRow label="Severity">
            <span className="capitalize">{incident.severity}</span>
          </IncidentDetailRow>
          <IncidentDetailRow label="Started">
            <span className="tabular-nums">{formatUtcTimestamp(incident.startedAt)}</span>
          </IncidentDetailRow>
          <IncidentDetailRow label="Ended">
            <span className="tabular-nums">
              {ongoing ? "still open" : formatUtcTimestamp(incident.endedAt)}
            </span>
          </IncidentDetailRow>
          <IncidentDetailRow label="Duration">
            <span className="tabular-nums">{incidentDuration(incident, now)}</span>
          </IncidentDetailRow>
          <IncidentDetailRow label="Cause">
            {incident.cause ? (
              <span className="break-words whitespace-pre-line">{incident.cause}</span>
            ) : (
              <span className="text-muted-foreground">not recorded</span>
            )}
          </IncidentDetailRow>
        </div>

        <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted px-5 py-4">
          {incident.monitorId ? (
            <Button asChild variant="outline" size="sm">
              <Link href={`/monitors/${incident.monitorId}`}>View monitor</Link>
            </Button>
          ) : null}
          <DialogClose asChild>
            <Button size="sm">Close</Button>
          </DialogClose>
        </div>
      </DialogContent>
    </Dialog>
  );
}
