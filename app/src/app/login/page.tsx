import type { Metadata } from "next";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoginForm } from "@/features/auth/components/login-form";
import { Wordmark } from "@/features/shell/components/wordmark";

export const metadata: Metadata = {
  title: "Sign in — Pulse",
};

interface LoginPageProps {
  // The post-login destination carried by the middleware (e.g. an alert mail's deep
  // link). Validated server-side in the action; passed through here untouched.
  searchParams: Promise<{ next?: string }>;
}

// /login lives outside the shell: a single centred card, the wordmark, two
// fields and one primary button. There is no registration and no password
// recovery — operators are created with `bin/console app:user:create`.
export default async function LoginPage({ searchParams }: LoginPageProps) {
  const { next } = await searchParams;

  return (
    <main className="flex flex-1 items-center justify-center px-4 py-12">
      <div className="flex w-full max-w-[340px] flex-col gap-6">
        <Wordmark />
        <Card>
          <CardHeader>
            <CardTitle>Sign in</CardTitle>
            <CardDescription>Operator access to this instance.</CardDescription>
          </CardHeader>
          <CardContent>
            <LoginForm next={next} />
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
