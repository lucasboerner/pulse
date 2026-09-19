"use client";

import { useState } from "react";
import { PlusIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import { MonitorFormDialog } from "@/features/monitor/components/monitor-form-dialog";
import type { InstanceUser } from "@/features/monitor/types";

interface NewMonitorButtonProps {
  users: InstanceUser[];
  currentUserId: string | null;
  variant?: "default" | "outline";
  label?: string;
}

export function NewMonitorButton({
  users,
  currentUserId,
  variant = "default",
  label = "New Monitor",
}: NewMonitorButtonProps) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button variant={variant} size="sm" onClick={() => setOpen(true)}>
        <PlusIcon />
        {label}
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
