"use client";

import { useTransition } from "react";
import { toast } from "sonner";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { deleteMonitorAction } from "@/lib/api/actions";
import type { Monitor } from "@/features/monitor/types";

interface DeleteMonitorDialogProps {
  monitor: Monitor;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

// The 420px confirm modal. The destructive action routes through here — never an
// inline undo — and the copy states that the history is kept.
export function DeleteMonitorDialog({ monitor, open, onOpenChange }: DeleteMonitorDialogProps) {
  const [pending, startTransition] = useTransition();

  function onConfirm() {
    startTransition(async () => {
      const result = await deleteMonitorAction(monitor.id);
      if (result.error) {
        toast.error(result.error);
        return;
      }
      toast(`${monitor.name} deleted. History kept.`);
      onOpenChange(false);
    });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton={false}
        className="w-full gap-0 border border-border bg-card p-0 shadow-lg sm:max-w-[420px]"
      >
        <div className="flex flex-col gap-2 p-5">
          <DialogTitle className="text-[18px] font-semibold">Delete Monitor</DialogTitle>
          <DialogDescription className="text-[13px] leading-normal text-muted-foreground">
            Delete <span className="text-foreground">{monitor.name}</span>? Its check history is
            kept in the database, and the same target can be added again.
          </DialogDescription>
        </div>
        <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted px-5 py-4">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={pending}>
            Cancel
          </Button>
          <Button variant="destructive" size="sm" onClick={onConfirm} disabled={pending}>
            {pending ? "Deleting…" : "Delete"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
