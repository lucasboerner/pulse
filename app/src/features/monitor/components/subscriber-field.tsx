"use client";

import { CheckIcon } from "lucide-react";

import { cn } from "@/lib/utils";
import type { InstanceUser } from "@/features/monitor/types";

interface SubscriberFieldProps {
  users: InstanceUser[];
  value: string[];
  onChange: (value: string[]) => void;
}

// Alert recipients subscribe per monitor. Zero subscribers is a legal state the
// interface makes visible rather than validates away, so this is a plain
// multi-select list with no "at least one" rule.
export function SubscriberField({ users, value, onChange }: SubscriberFieldProps) {
  if (users.length === 0) {
    return (
      <p className="text-[12px] leading-normal text-muted-foreground">
        No operators exist yet — create one with{" "}
        <code className="text-foreground">app:user:create</code> to receive alerts.
      </p>
    );
  }

  function toggle(id: string) {
    onChange(value.includes(id) ? value.filter((entry) => entry !== id) : [...value, id]);
  }

  return (
    <div className="flex max-h-40 flex-col overflow-y-auto rounded-md border border-input">
      {users.map((user) => {
        const selected = value.includes(user.id);
        return (
          <button
            type="button"
            key={user.id}
            onClick={() => toggle(user.id)}
            aria-pressed={selected}
            className="flex items-center justify-between gap-2 border-b border-border px-3 py-2 text-left transition-colors duration-[120ms] last:border-b-0 hover:bg-accent"
          >
            <span className="flex min-w-0 flex-col">
              <span className="truncate text-[13px]">{user.username}</span>
              <span className="truncate text-[11px] text-muted-foreground">{user.email}</span>
            </span>
            <span
              className={cn(
                "flex size-4 shrink-0 items-center justify-center rounded-[4px] border",
                selected ? "border-primary bg-primary text-primary-foreground" : "border-input",
              )}
            >
              {selected ? <CheckIcon className="size-3" /> : null}
            </span>
          </button>
        );
      })}
    </div>
  );
}
