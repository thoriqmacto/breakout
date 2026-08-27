"use client"

import type { ReactNode } from "react"

import { cn } from "@/lib/utils"
import {
  CONDITION_LABELS,
  RUN_STATUS_LABELS,
  RUN_STATUS_TONE,
  TOKEN_STATUS_LABELS,
  TOKEN_STATUS_TONE,
  describeSkipReason,
  type RunStatus,
  type TaskCondition,
  type TokenStatus,
} from "@/lib/automation-client"

export function Badge({
  children,
  tone,
  title,
}: {
  children: ReactNode
  tone?: string
  title?: string
}) {
  return (
    <span
      title={title}
      className={cn(
        "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium",
        tone ?? "bg-muted text-muted-foreground",
      )}
    >
      {children}
    </span>
  )
}

/**
 * A run's outcome. `partial` is shown alongside a success rather than instead
 * of it: the run did finish, but it did not do everything it set out to, and
 * collapsing that into a green tick is how a quietly-dropped ticker goes
 * unnoticed for a month.
 */
export function RunStatusBadge({
  status,
  skipReason,
  partial,
}: {
  status: RunStatus
  skipReason?: string | null
  partial?: boolean
}) {
  const reason = describeSkipReason(skipReason ?? null)

  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <Badge tone={RUN_STATUS_TONE[status]} title={reason ?? undefined}>
        {RUN_STATUS_LABELS[status]}
      </Badge>
      {partial && status === "success" ? (
        <Badge
          tone="bg-amber-500/10 text-amber-700 dark:text-amber-400"
          title="The run completed but some tickers or uploads did not."
        >
          Partial
        </Badge>
      ) : null}
      {reason ? <span className="text-xs text-muted-foreground">{reason}</span> : null}
    </span>
  )
}

export function EnabledBadge({ enabled }: { enabled: boolean }) {
  return (
    <Badge
      tone={
        enabled
          ? "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400"
          : "bg-muted text-muted-foreground"
      }
    >
      {enabled ? "Enabled" : "Disabled"}
    </Badge>
  )
}

export function ConditionBadge({ condition }: { condition: TaskCondition }) {
  return (
    <Badge
      tone={
        condition === "none"
          ? "bg-muted text-muted-foreground"
          : "bg-sky-500/10 text-sky-700 dark:text-sky-400"
      }
    >
      {CONDITION_LABELS[condition] ?? condition}
    </Badge>
  )
}

export function TokenStatusBadge({ status }: { status: TokenStatus }) {
  return <Badge tone={TOKEN_STATUS_TONE[status]}>{TOKEN_STATUS_LABELS[status]}</Badge>
}
