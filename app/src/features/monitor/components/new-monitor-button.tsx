"use client";

import { useState } from "react";
import { PlusIcon } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { MonitorFormDialog } from "@/features/monitor/components/monitor-form-dialog";
import type { InstanceUser } from "@/features/monitor/types";

interface NewMonitorButtonProps {
  users: InstanceUser[];
  currentUserId: string | null;
  variant?: "default" | "outline";
  label?: string;
  /** Collapses to the plus icon alone below sm — for the cramped page header. */
  compact?: boolean;
}

export function NewMonitorButton({
  users,
  currentUserId,
  variant = "default",
  label = "New Monitor",
  compact = false,
}: NewMonitorButtonProps) {
  const [open, setOpen] = useState(false);

  return (
    <>
      {/* Compact: below sm the header has no room for the label, so the button is
          the plus icon alone and the label lives on as its accessible name. */}
      <Button
        variant={variant}
        size="sm"
        onClick={() => setOpen(true)}
        aria-label={compact ? label : undefined}
        className={cn(compact && "max-sm:w-[30px] max-sm:gap-0 max-sm:px-0")}
      >
        <PlusIcon />
        <span className={cn(compact && "max-sm:sr-only")}>{label}</span>
      </Button>
      <MonitorFormDialog
        mode="create"
        users={users}
        currentUserId={currentUserId}
        open={open}
        onOpenChange={setOpen}
      />
    </>
  );
}
