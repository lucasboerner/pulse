import type { Metadata } from "next";

import { LoginForm } from "@/features/auth/components/login-form";
import { Wordmark } from "@/features/shell/components/wordmark";

export const metadata: Metadata = {
  title: "Sign in — Pulse",
};

// /login lives outside the shell: a single centred panel, the wordmark, two
// fields and one primary button. There is no registration and no password
// recovery — operators are created with `bin/console app:user:create`.
export default function LoginPage() {
  return (
    <main className="flex flex-1 items-center justify-center px-4 py-12">
      <div className="flex w-full max-w-[340px] flex-col gap-6">
        <Wordmark />
        <div className="border border-border bg-card p-6">
          <LoginForm />
        </div>
      </div>
    </main>
  );
}
