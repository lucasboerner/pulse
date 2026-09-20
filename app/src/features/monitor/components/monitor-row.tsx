"use client";

import { useOptimistic, useState, useTransition } from "react";
import Link from "next/link";
import { PencilIcon, Trash2Icon } from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import {
  displayStatus,
  hostFromUrl,
  intervalLabel,
  relativeTime,
} from "@/features/monitor/lib/status";
import { setMonitorEnabledAction } from "@/lib/api/actions";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { MonitorStatusBadge } from "@/features/monitor/components/monitor-status-badge";
import { MonitorFormDialog } from "@/features/monitor/components/monitor-form-dialog";
import { DeleteMonitorDialog } from "@/features/monitor/components/delete-monitor-dialog";

// Kept identical between the header and every row so the columns line up. It is an
// `md:` rule on purpose: below that breakpoint the row is not a table row at all
// but a stacked card, laid out by the two-column base grid instead.
export const MONITOR_TABLE_GRID =
  "md:grid-cols-[minmax(180px,2fr)_112px_80px_90px_120px_150px]";

interface MonitorRowProps {
  monitor: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
  now: number;
}

// One monitor, in two shapes from one DOM. From md up it is a six-column table row
// matching MonitorsTable's header. Below md the same children fall into a card:
// name and badge on the first line, the meta values wrapped onto the second (the
// wrapper is `display: contents` from md up, so those three become columns again),
// and the controls on the third.
export function MonitorRow({ monitor, users, currentUserId, now }: MonitorRowProps) {
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [, startTransition] = useTransition();
  const [enabled, setEnabled] = useOptimistic(monitor.enabled);

  const status = displayStatus({ ...monitor, enabled });

  function onToggle(next: boolean) {
    startTransition(async () => {
      setEnabled(next);
      const result = await setMonitorEnabledAction(monitor.id, next);
      if (result.error) {
        toast.error(result.error);
        return;
      }
      toast(next ? `${monitor.name} resumed.` : `${monitor.name} paused.`);
    });
  }

  return (
    <>
      <div
        className={cn(
          "group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2.5 border-b border-border px-4 py-3.5 transition-colors duration-[120ms] last:border-b-0 hover:bg-accent md:gap-y-0 md:py-0",
          MONITOR_TABLE_GRID,
          !enabled && "opacity-40",
        )}
      >
        <Link
          href={`/monitors/${monitor.id}`}
          className="flex min-w-0 flex-col gap-0.5 md:py-2.75"
        >
          <span className="truncate text-[14px] font-medium">{monitor.name}</span>
          <span className="truncate text-[12px] text-muted-foreground">
            {hostFromUrl(monitor.url)}
          </span>
        </Link>

        <div className="flex items-center justify-end md:justify-start">
          <MonitorStatusBadge status={status} />
        </div>

        <div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 md:contents">
          <span className="text-[12px] tracking-[0.06em] uppercase text-muted-foreground">
            {monitor._type}
          </span>

          <span className="font-mono text-[13px] tabular-nums text-muted-foreground">
            every {intervalLabel(monitor.intervalSeconds)}
          </span>

          <span className="font-mono text-[13px] tabular-nums text-muted-foreground">
            {relativeTime(monitor.lastCheckedAt, now)}
          </span>
        </div>

        <div className="col-span-2 flex items-center justify-end gap-2 md:col-span-1">
          <Switch
            checked={enabled}
            onCheckedChange={onToggle}
            aria-label={enabled ? "Pause monitoring" : "Resume monitoring"}
          />
          <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
            <PencilIcon />
            Edit
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            className="text-muted-foreground hover:text-destructive"
            onClick={() => setDeleteOpen(true)}
            aria-label={`Delete ${monitor.name}`}
          >
            <Trash2Icon />
          </Button>
        </div>
      </div>

      <MonitorFormDialog
        mode="edit"
        monitor={monitor}
        users={users}
        currentUserId={currentUserId}
        open={editOpen}
        onOpenChange={setEditOpen}
      />
      <DeleteMonitorDialog monitor={monitor} open={deleteOpen} onOpenChange={setDeleteOpen} />
    </>
  );
}
