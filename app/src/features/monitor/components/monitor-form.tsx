"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import {
  monitorFormSchema,
  newMonitorDefaults,
  monitorToFormValues,
  INTERVAL_OPTIONS,
  DOWN_INTERVAL_OPTIONS,
  CHECK_TYPE_OPTIONS,
  type MonitorFormValues,
} from "@/features/monitor/lib/validation";
import { createMonitorAction, updateMonitorAction } from "@/lib/api/actions";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SubscriberField } from "@/features/monitor/components/subscriber-field";

interface MonitorFormProps {
  mode: "create" | "edit";
  monitor?: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
  onSuccess: () => void;
  onCancel: () => void;
}

export function MonitorForm({
  mode,
  monitor,
  users,
  currentUserId,
  onSuccess,
  onCancel,
}: MonitorFormProps) {
  const form = useForm<MonitorFormValues>({
    resolver: zodResolver(monitorFormSchema),
    defaultValues:
      mode === "edit" && monitor
        ? monitorToFormValues(monitor)
        : newMonitorDefaults(currentUserId),
  });
  const { isSubmitting, errors } = form.formState;

  async function onSubmit(values: MonitorFormValues) {
    const result =
      mode === "edit" && monitor
        ? await updateMonitorAction(monitor.id, values)
        : await createMonitorAction(values);

    if (result.fieldErrors) {
      for (const [field, message] of Object.entries(result.fieldErrors)) {
        form.setError(field as keyof MonitorFormValues, { type: "server", message });
      }
      return;
    }
    if (result.error) {
      form.setError("root", { type: "server", message: result.error });
      return;
    }

    toast(
      mode === "create"
        ? "Monitor created — first check queued."
        : `${values.name.trim()} updated.`,
    );
    onSuccess();
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex min-h-0 flex-col">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          {errors.root ? (
            <p className="text-[12px] leading-normal text-destructive">{errors.root.message}</p>
          ) : null}

          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Name</FormLabel>
                <FormControl>
                  <Input placeholder="api-eu-west-1" autoFocus {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="url"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Host / URL</FormLabel>
                <FormControl>
                  <Input placeholder="https://api.example.com/health" {...field} />
                </FormControl>
                <FormDescription>Hostname, IP or full URL to probe.</FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="checkType"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Check Type</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {CHECK_TYPE_OPTIONS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="intervalSeconds"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Interval</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {INTERVAL_OPTIONS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="downIntervalSeconds"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Interval While Down</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {DOWN_INTERVAL_OPTIONS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription>Recheck sooner to catch the recovery.</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="timeoutMs"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Timeout (ms)</FormLabel>
                  <FormControl>
                    <Input inputMode="numeric" placeholder="8000" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>

          <FormField
            control={form.control}
            name="subscribers"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Alert Recipients</FormLabel>
                <SubscriberField users={users} value={field.value} onChange={field.onChange} />
                <FormDescription>
                  Operators who receive alert mail when this monitor goes down.
                </FormDescription>
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="enabled"
            render={({ field }) => (
              <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
                <div className="flex flex-col gap-1">
                  <span className="text-[14px] font-medium">Monitoring Enabled</span>
                  <span className="text-[12px] text-muted-foreground">
                    Pause to stop checks without deleting history.
                  </span>
                </div>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </div>

        <div className="flex shrink-0 items-center justify-end gap-2.5 border-t border-border bg-muted px-5 py-4">
          <Button type="button" variant="outline" size="sm" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" size="sm" disabled={isSubmitting}>
            {isSubmitting ? "Saving…" : mode === "create" ? "Create Monitor" : "Save Changes"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
