"use client"

import { useTheme } from "next-themes"
import { Toaster as Sonner, type ToasterProps } from "sonner"

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme()

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      position="bottom-right"
      duration={2600}
      className="toaster group"
      toastOptions={{
        classNames: {
          // Lyra toast: card fill, hairline border with a 2px teal left edge,
          // gently rounded, 13px single line. Confirms facts, never celebrates.
          toast:
            "!rounded-md !border !border-border !border-l-2 !border-l-primary !bg-card !text-[13px] !text-foreground",
        },
      }}
      style={
        {
          "--normal-bg": "var(--card)",
          "--normal-text": "var(--foreground)",
          "--normal-border": "var(--border)",
        } as React.CSSProperties
      }
      {...props}
    />
  )
}

export { Toaster }
