"use client";

import { LogOutIcon } from "lucide-react";

import { cn } from "@/lib/utils";
import { logoutAction } from "@/lib/api/actions";
import { Button } from "@/components/ui/button";

interface LogoutButtonProps {
  /** Overrides the sidebar's full-width shape — the mobile top bar sizes to content. */
  className?: string;
}

export function LogoutButton({ className }: LogoutButtonProps) {
  return (
    <form action={logoutAction}>
      <Button
        type="submit"
        variant="ghost"
        size="sm"
        className={cn(
          "w-full justify-start px-3 text-muted-foreground hover:text-foreground",
          className,
        )}
      >
        <LogOutIcon />
        Sign out
      </Button>
    </form>
  );
}
