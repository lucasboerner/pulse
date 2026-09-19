"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";

import { loginFormSchema, type LoginFormValues } from "@/features/auth/lib/validation";
import { loginAction } from "@/lib/api/actions";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

export function LoginForm() {
  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginFormSchema),
    defaultValues: { username: "", password: "" },
  });
  const { isSubmitting, errors } = form.formState;

  async function onSubmit(values: LoginFormValues) {
    const result = await loginAction(values);
    // Success redirects server-side, so only a failure reaches here.
    if (result?.fieldErrors) {
      for (const [field, message] of Object.entries(result.fieldErrors)) {
        form.setError(field as keyof LoginFormValues, { type: "server", message });
      }
      return;
    }
    if (result?.error) {
      form.setError("root", { type: "server", message: result.error });
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
        {errors.root ? (
          <p className="text-[12px] leading-normal text-destructive">{errors.root.message}</p>
        ) : null}

        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Username</FormLabel>
              <FormControl>
                <Input autoComplete="username" autoFocus {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Password</FormLabel>
              <FormControl>
                <Input type="password" autoComplete="current-password" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={isSubmitting} className="mt-1 w-full">
          {isSubmitting ? "Signing in…" : "Sign in"}
        </Button>
      </form>
    </Form>
  );
}
