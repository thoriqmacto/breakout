"use client"

import Link from "next/link"
import { useCallback, useEffect, useState } from "react"
import { TriangleAlert, X } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import {
  dismissAutomationAlert,
  fetchAutomationAlerts,
  type AutomationAlert,
} from "@/lib/automation-client"

/**
 * The dashboard-wide attention strip.
 *
 * There is no mail or push transport in this project, so an alert is a row in
 * the database and this is its delivery: visible on every authenticated page,
 * surviving a reload, and clearing itself once the underlying condition does.
 * Dismissing resolves the alert server-side rather than hiding it locally --
 * otherwise the next person to open the dashboard would not know it had been
 * dealt with.
 */
export function AutomationAlertBanner({ compact = false }: { compact?: boolean }) {
  const { accessToken } = useAuth()
  const [alerts, setAlerts] = useState<AutomationAlert[]>([])

  const load = useCallback(async () => {
    if (!accessToken) return

    try {
      setAlerts(await fetchAutomationAlerts(accessToken))
    } catch {
      // A dashboard-wide banner must never break the page it sits above, so a
      // failure here is simply no banner.
      setAlerts([])
    }
  }, [accessToken])

  useEffect(() => {
    void load()
  }, [load])

  const dismiss = async (id: number) => {
    if (!accessToken) return
    setAlerts((current) => current.filter((alert) => alert.id !== id))

    try {
      await dismissAutomationAlert(accessToken, id)
    } catch {
      void load()
    }
  }

  if (alerts.length === 0) return null

  return (
    <div className={cn("space-y-2", compact ? "" : "mb-4")}>
      {alerts.map((alert) => (
        <div
          key={alert.id}
          className={cn(
            "flex flex-wrap items-start gap-3 rounded-lg border px-4 py-3 text-sm",
            alert.severity === "critical"
              ? "border-destructive/40 bg-destructive/10 text-destructive"
              : "border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300",
          )}
          role="status"
        >
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          <div className="min-w-0 flex-1">
            <p className="font-medium">{alert.title}</p>
            <p className="text-current/90">{alert.message}</p>
          </div>
          <div className="flex items-center gap-1">
            <Button asChild size="sm" variant="ghost">
              <Link href="/dashboard/automation">Open Automation</Link>
            </Button>
            <Button
              size="icon"
              variant="ghost"
              aria-label="Dismiss alert"
              onClick={() => void dismiss(alert.id)}
            >
              <X className="size-4" />
            </Button>
          </div>
        </div>
      ))}
    </div>
  )
}
