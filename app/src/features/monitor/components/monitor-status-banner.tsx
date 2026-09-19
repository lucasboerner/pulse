"use client";

import { useOptimistic, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { PauseIcon, PencilIcon, PlayIcon, Trash2Icon } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { setMonitorEnabledAction } from "@/lib/api/actions";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { displayStatus, hostFromUrl, statusHeadline } from "@/features/monitor/lib/status";
import { StatusDot } from "@/features/monitor/components/status-dot";
import { MonitorStatusBadge } from "@/features/monitor/components/monitor-status-badge";
import { MonitorFormDialog } from "@/features/monitor/components/monitor-form-dialog";
import { DeleteMonitorDialog } from "@/features/monitor/components/delete-monitor-dialog";

interface MonitorStatusBannerProps {
  monitor: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
  /** The open incident's cause, appended to the muted line when down or degraded. */
  causeLine: string | null;
}

// The full-width status banner: a dot, the headline sentence, the status badge and
// the host on the muted line below; on the right the Pause/Resume, Edit and Delete
// controls. Delete is the one destructive control on the screen. The status flips in
// place because the (live) monitor is passed down from MonitorDetailLive.
export function MonitorStatusBanner({
  monitor,
  users,
  currentUserId,
  causeLine,
}: MonitorStatusBannerProps) {
  const router = useRouter();
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [, startTransition] = useTransition();
  const [enabled, setEnabled] = useOptimistic(monitor.enabled);

  const status = displayStatus({ ...monitor, enabled });
  const host = hostFromUrl(monitor.url);
  const showCause = (status === "down" || status === "degraded") && Boolean(causeLine);

  function onTogglePause() {
    startTransition(async () => {
      setEnabled(!enabled);
      const result = await setMonitorEnabledAction(monitor.id, !enabled);
      if (result.error) {
        toast.error(result.error);
        return;
      }
      toast(enabled ? `${monitor.name} paused.` : `${monitor.name} resumed.`);
    });
  }

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-4 border border-border bg-card p-5">
        <div className="flex min-w-0 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-2.5">
            <StatusDot status={status} />
            <span className="text-[15px]">{statusHeadline(status)}</span>
            <MonitorStatusBadge status={status} />
          </div>
          <p className="min-w-0 truncate text-[13px] text-muted-foreground">
            {host}
            {showCause ? ` · ${causeLine}` : ""}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <Button variant="outline" size="sm" onClick={onTogglePause}>
            {enabled ? <PauseIcon /> : <PlayIcon />}
            {enabled ? "Pause" : "Resume"}
          </Button>
          <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
            <PencilIcon />
            Edit
          </Button>
          <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
            <Trash2Icon />
            Delete
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
      <DeleteMonitorDialog
        monitor={monitor}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        onDeleted={() => router.push("/monitors")}
      />
    </>
  );
}
