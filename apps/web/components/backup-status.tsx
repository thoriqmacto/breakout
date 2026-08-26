"use client"

import { useState } from "react"
import {
  ArrowDown,
  ArrowUp,
  CheckCircle2,
  ChevronDown,
  Cloud,
  HardDrive,
  HelpCircle,
  TriangleAlert,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import {
  STATE_HINTS,
  STATE_LABELS,
  symbolOf,
  type BackupFile,
  type BackupState,
  type Collection,
  type DriveHealth,
  type PushResult,
} from "@/lib/backup-client"

export const formatBytes = (bytes: number | null) =>
  bytes === null
    ? "—"
    : new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(
        bytes,
      ) + "B"

export const formatTime = (value: string | null) =>
  value
    ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(
        new Date(value),
      )
    : "—"

/** Only `synced` is green. Everything else is amber, red, or neutral. */
const STATE_TONE: Record<BackupState, string> = {
  synced: "text-emerald-700 dark:text-emerald-400",
  local_only: "text-amber-700 dark:text-amber-400",
  gdrive_only: "text-amber-700 dark:text-amber-400",
  local_newer: "text-amber-700 dark:text-amber-400",
  gdrive_newer: "text-amber-700 dark:text-amber-400",
  different: "text-destructive",
  compare_error: "text-muted-foreground",
}

function StateIcon({ state }: { state: BackupState }) {
  switch (state) {
    case "synced":
      return <CheckCircle2 className="size-4" />
    case "local_only":
    case "local_newer":
      return <ArrowUp className="size-4" />
    case "gdrive_only":
    case "gdrive_newer":
      return <ArrowDown className="size-4" />
    case "compare_error":
      return <HelpCircle className="size-4" />
    default:
      return <TriangleAlert className="size-4" />
  }
}

/**
 * Ask before overwriting Drive where the direction of the difference is
 * unknown. Only `different` reaches this; `local_newer` and `local_only` have
 * an unambiguous direction, and `gdrive_newer` is never pushable at all.
 */
export function confirmOverwrite(names: string[]): boolean {
  if (typeof window === "undefined") return true

  const subject =
    names.length === 1
      ? `${names[0]} has`
      : `${names.length} files (${names.slice(0, 3).join(", ")}${names.length > 3 ? ", …" : ""}) have`

  return window.confirm(
    `${subject} contents that differ from Google Drive, and the modification times do not say which is newer.\n\n` +
      "Pushing replaces the Drive copy with the local one. Continue?",
  )
}

export function StateBadge({ state }: { state: BackupState }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap ${STATE_TONE[state]}`}
      title={STATE_HINTS[state]}
    >
      <StateIcon state={state} />
      {STATE_LABELS[state]}
    </span>
  )
}

export function DriveHealthCard({ health }: { health: DriveHealth }) {
  const [open, setOpen] = useState(false)
  const healthy = health.status === "healthy"
  const needsRenewal = health.refresh_token_status === "renew_required"

  const tokenLabel: Record<DriveHealth["refresh_token_status"], string> = {
    valid: "Valid",
    renew_required: "Needs renewal",
    not_configured: "Not configured",
    unknown: "Unknown",
  }

  return (
    <Card className={healthy ? undefined : "border-destructive/40"}>
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <Cloud className="size-8 text-primary" />
            <div>
              <CardTitle>Google Drive</CardTitle>
              <CardDescription className="flex items-center gap-2">
                <span
                  className={`size-2.5 rounded-full ${healthy ? "bg-emerald-500" : "bg-destructive"}`}
                />
                {healthy ? "Connected" : needsRenewal ? "Authentication required" : "Unavailable"}
              </CardDescription>
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div className="flex justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">OAuth</dt>
            <dd className="font-medium">{healthy ? "Healthy" : health.status.replace(/_/g, " ")}</dd>
          </div>
          <div className="flex justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">Refresh token</dt>
            <dd className="font-medium">{tokenLabel[health.refresh_token_status]}</dd>
          </div>
          <div className="flex justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">Drive access</dt>
            <dd className="font-medium">{health.can_read ? "Available" : "Unavailable"}</dd>
          </div>
          <div className="flex justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">Last checked</dt>
            <dd className="font-medium">{formatTime(health.checked_at)}</dd>
          </div>
        </dl>

        {!healthy ? <p className="text-sm text-destructive">{health.message}</p> : null}

        {health.guidance.length > 0 ? (
          <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            {health.guidance.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        ) : null}

        {healthy ? (
          <p className="text-sm text-muted-foreground">
            Access tokens are renewed automatically from the configured refresh token. Google does
            not publish an expiry date for a refresh token, so this status reflects an OAuth
            exchange performed just now, not a countdown.
          </p>
        ) : null}

        {needsRenewal ? (
          <div className="rounded-lg border">
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium"
            >
              Show renewal instructions
              <ChevronDown className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
            </button>
            {open ? (
              <ol className="list-decimal space-y-1.5 border-t px-4 py-3 pl-9 text-sm text-muted-foreground">
                <li>Open the Google OAuth Playground.</li>
                <li>Enable &ldquo;Use your own OAuth credentials&rdquo;.</li>
                <li>Enter the same client ID and client secret already configured on the server.</li>
                <li>Set the access type to Offline.</li>
                <li>
                  Authorise the scope{" "}
                  <code className="font-mono text-xs">https://www.googleapis.com/auth/drive</code>.
                </li>
                <li>Exchange the authorisation code for tokens and copy the refresh token.</li>
                <li>
                  Set <code className="font-mono text-xs">GOOGLE_DRIVE_REFRESH_TOKEN</code> on the
                  server.
                </li>
                <li>
                  Run <code className="font-mono text-xs">php artisan optimize:clear</code>, then{" "}
                  <code className="font-mono text-xs">php artisan config:cache</code>, then{" "}
                  <code className="font-mono text-xs">php artisan gdrive:check</code>.
                </li>
                <li>Refresh this page.</li>
              </ol>
            ) : null}
          </div>
        ) : null}

        <p className="text-xs text-muted-foreground">
          If the Google OAuth application is still in Testing mode, Google issues refresh tokens with
          a limited lifetime. For unattended backups, configure the application for persistent use.
          This page cannot detect the publishing status, so treat this as a reminder rather than a
          diagnosis.
        </p>
      </CardContent>
    </Card>
  )
}

export function LocalLocationCard({ available }: { available: boolean }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-4 py-5">
        <HardDrive className="size-8 text-primary" />
        <div className="flex-1">
          <p className="font-semibold">Local</p>
          <p className="text-sm text-muted-foreground">
            {available ? "Working copy, scanned" : "Unavailable"}
          </p>
        </div>
        <span
          className={`size-3 rounded-full ${available ? "bg-emerald-500" : "bg-amber-500"}`}
        />
      </CardContent>
    </Card>
  )
}

export function BackupSummary({
  collections,
  health,
  pending,
  pushing,
  onPush,
}: {
  collections: Collection[]
  health: DriveHealth
  pending: number
  pushing: boolean
  onPush: () => void
}) {
  const historical = collections.find((c) => c.key === "historical")

  if (!health.can_read) {
    return (
      <Card className="border-destructive/40">
        <CardContent className="flex flex-col gap-2 py-5">
          <p className="font-medium text-destructive">
            Google Drive could not be verified. Backup synchronization cannot be confirmed.
          </p>
          <p className="text-sm text-muted-foreground">{health.message}</p>
        </CardContent>
      </Card>
    )
  }

  if (pending === 0) {
    return (
      <Card>
        <CardContent className="flex items-center gap-3 py-5 text-emerald-700 dark:text-emerald-400">
          <CheckCircle2 className="size-5" />
          <p className="font-medium">
            All {historical?.counts.total ?? 0} historical files are synchronized with Google Drive.
          </p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="border-amber-500/50">
      <CardContent className="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <TriangleAlert className="size-5 text-amber-600" />
          <p className="font-medium">
            {pending} historical backup {pending === 1 ? "file is" : "files are"} not synchronized
            with Google Drive.
          </p>
        </div>
        <Button
          onClick={() => {
            const ambiguous = (historical?.files ?? [])
              .filter((file) => file.can_push && file.state === "different")
              .map((file) => file.name)

            if (ambiguous.length > 0 && !confirmOverwrite(ambiguous)) return
            onPush()
          }}
          disabled={pushing}
        >
          {pushing ? "Pushing…" : "Push pending to Drive"}
        </Button>
      </CardContent>
    </Card>
  )
}

export function PushOutcome({ result }: { result: PushResult }) {
  const failed = result.failed.length > 0

  return (
    <Card className={failed ? "border-destructive/40" : "border-emerald-500/40"}>
      <CardContent className="space-y-1 py-4 text-sm">
        <p className="font-medium">
          Uploaded {result.uploaded.length} · Skipped {result.skipped.length} · Failed{" "}
          {result.failed.length}
        </p>
        {failed ? (
          <p className="text-destructive">Failed: {result.failed.join(", ")}</p>
        ) : null}
        {result.rejected.length > 0 ? (
          <p className="text-muted-foreground">
            Not eligible for push: {result.rejected.join(", ")}
          </p>
        ) : null}
      </CardContent>
    </Card>
  )
}

function FileRow({
  file,
  pushable,
  pushing,
  onPush,
}: {
  file: BackupFile
  pushable: boolean
  pushing: boolean
  onPush: (symbol: string) => void
}) {
  return (
    <tr className="hover:bg-muted/30">
      <td className="max-w-[240px] truncate px-4 py-3 font-mono text-xs" title={file.name}>
        {file.name}
      </td>
      <td className="px-4 py-3">
        <StateBadge state={file.state} />
      </td>
      <td className="px-4 py-3 text-muted-foreground">
        <div>{formatBytes(file.local?.size ?? null)}</div>
        <div className="text-xs">{formatTime(file.local?.modified_at ?? null)}</div>
        {file.local?.hash ? (
          <div className="font-mono text-xs opacity-60">{file.local.hash.slice(0, 8)}…</div>
        ) : null}
      </td>
      <td className="px-4 py-3 text-muted-foreground">
        <div>{formatBytes(file.gdrive?.size ?? null)}</div>
        <div className="text-xs">{formatTime(file.gdrive?.modified_at ?? null)}</div>
        {file.gdrive?.hash ? (
          <div className="font-mono text-xs opacity-60">{file.gdrive.hash.slice(0, 8)}…</div>
        ) : null}
      </td>
      <td className="px-4 py-3 text-right">
        {pushable && file.can_push ? (
          <Button
            size="sm"
            variant="outline"
            disabled={pushing}
            // `different` means both copies changed and the timestamps do not
            // say which is authoritative, so pushing may discard the better
            // one. Every other pushable state has a clear direction.
            onClick={() => {
              if (file.state === "different" && !confirmOverwrite([file.name])) return
              onPush(symbolOf(file.name))
            }}
          >
            {pushing ? "Pushing…" : "Push"}
          </Button>
        ) : null}
      </td>
    </tr>
  )
}

export function CollectionCard({
  collection,
  pushing,
  onPush,
}: {
  collection: Collection
  pushing: boolean
  onPush: (symbol: string) => void
}) {
  const { counts } = collection

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <CardTitle>{collection.label}</CardTitle>
            <CardDescription>
              {counts.total} unique backup files discovered
              {collection.pushable ? null : " · read-only, no mirror configured"}
            </CardDescription>
          </div>
          <div className="flex flex-wrap gap-2 text-xs">
            <span className="rounded-full bg-muted px-3 py-1.5">Local {counts.local}</span>
            <span className="rounded-full bg-muted px-3 py-1.5">Drive {counts.gdrive}</span>
            <span className="rounded-full bg-emerald-500/10 px-3 py-1.5 text-emerald-700 dark:text-emerald-400">
              In sync {counts.synced}
            </span>
            {counts.pending_push > 0 ? (
              <span className="rounded-full bg-amber-500/10 px-3 py-1.5 text-amber-700 dark:text-amber-400">
                Pending {counts.pending_push}
              </span>
            ) : null}
            {counts.errors > 0 ? (
              <span className="rounded-full bg-muted px-3 py-1.5">Unknown {counts.errors}</span>
            ) : null}
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-4 py-3">File</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Local</th>
                <th className="px-4 py-3">Google Drive</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {collection.files.map((file) => (
                <FileRow
                  key={file.name}
                  file={file}
                  pushable={collection.pushable}
                  pushing={pushing}
                  onPush={onPush}
                />
              ))}
            </tbody>
          </table>
          {collection.files.length === 0 ? (
            <p className="p-8 text-center text-muted-foreground">No backup files found.</p>
          ) : null}
        </div>
      </CardContent>
    </Card>
  )
}

/** What each state means, so the page is operationally useful. */
export function StateGuidance() {
  const [open, setOpen] = useState(false)
  const states: BackupState[] = [
    "synced",
    "local_newer",
    "local_only",
    "gdrive_newer",
    "different",
    "compare_error",
  ]

  return (
    <Card>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex w-full items-center justify-between gap-2 px-6 py-4 text-left text-sm font-medium"
      >
        What these statuses mean
        <ChevronDown className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
      </button>
      {open ? (
        <CardContent className="space-y-3 border-t pt-4 text-sm">
          <p className="text-muted-foreground">
            Google Drive is durable cold storage. The local CSV files remain the working copy, and
            the database stays the query layer. &ldquo;In sync&rdquo; means the contents were
            compared and found identical — not merely that a file of the same name exists in both
            places.
          </p>
          <dl className="space-y-2">
            {states.map((state) => (
              <div key={state}>
                <dt className={`font-medium ${STATE_TONE[state]}`}>{STATE_LABELS[state]}</dt>
                <dd className="text-muted-foreground">{STATE_HINTS[state]}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
      ) : null}
    </Card>
  )
}
