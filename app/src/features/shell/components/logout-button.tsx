"use client";

import { LogOutIcon } from "lucide-react";

import { logoutAction } from "@/lib/api/actions";
import { Button } from "@/components/ui/button";

export function LogoutButton() {
  return (
    <form action={logoutAction}>
      <Button
        type="submit"
        variant="ghost"
        size="sm"
        className="w-full justify-start px-3 text-muted-foreground hover:text-foreground"
      >
        <LogOutIcon />
        Sign out
      </Button>
    </form>
  );
}
