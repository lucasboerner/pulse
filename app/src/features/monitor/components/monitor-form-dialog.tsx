"use client";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { MonitorForm } from "@/features/monitor/components/monitor-form";
import type { InstanceUser, Monitor } from "@/features/monitor/types";

interface MonitorFormDialogProps {
  mode: "create" | "edit";
  monitor?: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

// The 540px New / Edit modal — three banded regions split by hairlines. It holds
// only local state, so the Dialog/Sheet router-hook caveat does not apply.
export function MonitorFormDialog({
  mode,
  monitor,
  users,
  currentUserId,
  open,
  onOpenChange,
}: MonitorFormDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        className="w-full gap-0 border border-border bg-card p-0 shadow-lg sm:max-w-[540px]"
      >
        <DialogHeader className="flex flex-col gap-1 border-b border-border p-5">
          <DialogTitle className="text-[18px] font-semibold">
            {mode === "create" ? "New Monitor" : "Edit Monitor"}
          </DialogTitle>
          <DialogDescription className="text-[13px]">
            {mode === "create"
              ? "Add a target to monitor. Its first check runs immediately."
              : "Changes apply from the next check."}
          </DialogDescription>
        </DialogHeader>
        <MonitorForm
          key={monitor?.id ?? "new"}
          mode={mode}
          monitor={monitor}
          users={users}
          currentUserId={currentUserId}
          onSuccess={() => onOpenChange(false)}
          onCancel={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
