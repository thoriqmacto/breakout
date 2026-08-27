"use client"

import { AlertTriangle, CalendarClock, Clock, CloudUpload, KeyRound } from "lucide-react"

import { Card, CardContent } from "@/components/ui/card"
import { Badge, TokenStatusBadge } from "@/components/automation/badges"
import { formatJakarta, type AutomationStatus } from "@/lib/automation-client"

/**
 * The four questions someone opening this page is actually asking: is the
 * scheduler alive, can it authenticate, can it back up, and what happens next.
 */
export function StatusHeader({ status }: { status: AutomationStatus }) {
  const drive = status.google_drive.health
  const calendar = status.trading_calendar
  const next = status.scheduler.next_run

  const calendarHealthy = calendar.today_known && calendar.week_status === "ok"

  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <Card>
        <CardContent className="space-y-2">
          <p className="flex items-center gap-2 text-xs uppercase text-muted-foreground">
            <Clock className="size-3.5" aria-hidden /> Scheduler
          </p>
          <p className="text-lg font-semibold">{status.scheduler.now_local}</p>
          <p className="text-sm text-muted-foreground">{status.scheduler.timezone_label}</p>
          <p className="text-xs text-muted-foreground">
            {status.scheduler.enabled_task_count} of {status.scheduler.total_task_count} automations
            enabled · storage in {status.scheduler.application_timezone}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-2">
          <p className="flex items-center gap-2 text-xs uppercase text-muted-foreground">
            <KeyRound className="size-3.5" aria-hidden /> Stockbit token
          </p>
          <TokenStatusBadge status={status.stockbit_token.status} />
          <p className="text-sm text-muted-foreground">
            {status.stockbit_token.expires_in_human
              ? `${status.stockbit_token.expires_in_human} remaining`
              : status.stockbit_token.message}
          </p>
          {status.stockbit_token.fingerprint ? (
            <p className="font-mono text-xs text-muted-foreground">
              {status.stockbit_token.fingerprint}
            </p>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-2">
          <p className="flex items-center gap-2 text-xs uppercase text-muted-foreground">
            <CloudUpload className="size-3.5" aria-hidden /> Google Drive
          </p>
          <Badge
            tone={
              drive.can_read
                ? "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400"
                : "bg-amber-500/10 text-amber-700 dark:text-amber-400"
            }
          >
            {drive.can_read ? "Reachable" : (drive.status ?? "Unavailable")}
          </Badge>
          <p className="text-sm text-muted-foreground">{drive.message}</p>
          <p className="text-xs text-muted-foreground">
            CSV mirror: {status.google_drive.bars_mirror_disk ?? "off"} · Broker summary:{" "}
            {status.google_drive.broker_summary_mirror_disk ?? "off"}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-2">
          <p className="flex items-center gap-2 text-xs uppercase text-muted-foreground">
            <CalendarClock className="size-3.5" aria-hidden /> Next automation
          </p>
          {next ? (
            <>
              <p className="text-lg font-semibold">{next.name}</p>
              <p className="text-sm text-muted-foreground">
                {next.at_local} {next.timezone === "Asia/Jakarta" ? "WIB" : next.timezone}
              </p>
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              Nothing scheduled. Every automation is disabled or has an unusable schedule.
            </p>
          )}
          <p className="text-xs text-muted-foreground">
            Last dispatched run: {formatJakarta(status.scheduler.last_scheduled_run_at)}
          </p>
        </CardContent>
      </Card>

      {!calendarHealthy ? (
        <Card className="border-amber-500/40 md:col-span-2 xl:col-span-4">
          <CardContent className="flex items-start gap-3 text-sm">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
            <div>
              <p className="font-medium">The trading calendar cannot answer for this week.</p>
              <p className="text-muted-foreground">
                {calendar.today_known
                  ? `Today (${calendar.today}) is recorded, but ${calendar.missing_dates.length} day(s) of this week are missing: ${calendar.missing_dates.join(", ")}.`
                  : `There is no row for today (${calendar.today}).`}{" "}
                Trading-day conditions skip rather than guess, so the weekly broker summary will
                stand down until this is rebuilt with{" "}
                <code className="text-xs">php artisan trading-calendar:build</code>.
              </p>
            </div>
          </CardContent>
        </Card>
      ) : null}
    </div>
  )
}
