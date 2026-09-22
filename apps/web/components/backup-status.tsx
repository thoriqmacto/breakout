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

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import {
  STATE_HINTS,
  STATE_LABELS,
  disconnectDrive,
  startDriveConnection,
  symbolOf,
  type BackupFile,
  type BackupState,
  type Collection,
  type DriveConnection,
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

export function DriveHealthCard({
  health,
  connection,
  onConnectionChanged,
}: {
  health: DriveHealth
  connection: DriveConnection | null
  onConnectionChanged: () => void
}) {
  const healthy = health.status === "healthy"

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
                {healthy
                  ? "Connected"
                  : health.refresh_token_status === "renew_required"
                    ? "Authentication required"
                    : "Unavailable"}
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

        <DriveConnectPanel connection={connection} onChanged={onConnectionChanged} />

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

/**
 * Granting Drive access, in one click instead of nine steps.
 *
 * This replaced a collapsible list that told the reader to open the OAuth
 * Playground, tick "use your own credentials", mint a refresh token by hand,
 * paste it into .env and re-cache the config. Every one of those steps was a
 * chance to put the wrong value somewhere unreadable, and the whole procedure
 * had to be remembered again the next time the grant lapsed -- which, on a
 * consent screen still in Testing, is weekly.
 *
 * Consent happens on Google's own origin, so connecting is a full navigation
 * rather than a fetch, and the browser comes back to this page carrying a
 * ?drive= outcome. Nothing sensitive passes through here: the API mints the
 * URL, receives the callback, and keeps the token.
 */
function DriveConnectPanel({
  connection,
  onChanged,
}: {
  connection: DriveConnection | null
  onChanged: () => void
}) {
  const { accessToken } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (connection === null) return null

  if (!connection.configured) {
    return (
      <div className="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground">
        <p className="font-medium text-foreground">Connecting is not available yet</p>
        <p className="mt-1">
          The server needs <code className="font-mono text-xs">GOOGLE_DRIVE_CLIENT_ID</code>,{" "}
          <code className="font-mono text-xs">GOOGLE_DRIVE_CLIENT_SECRET</code> and{" "}
          <code className="font-mono text-xs">GOOGLE_DRIVE_REDIRECT_URI</code>, and that redirect URI
          has to be registered on the OAuth client in Google Cloud Console.
        </p>
      </div>
    )
  }

  const connect = async () => {
    if (!accessToken) return

    setBusy(true)
    setError(null)

    try {
      // Assigned rather than opened: consent is on accounts.google.com, and
      // the API redirects back here when it is done.
      window.location.href = await startDriveConnection(accessToken)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to start the connection.")
      setBusy(false)
    }
  }

  const disconnect = async () => {
    if (!accessToken) return

    // Backups stop the moment this succeeds, so it asks first.
    if (
      !window.confirm(
        "Disconnect Google Drive? Scheduled backups and the mirror push will fail until it is " +
          "connected again.",
      )
    ) {
      return
    }

    setBusy(true)
    setError(null)

    try {
      await disconnectDrive(accessToken)
      onChanged()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to disconnect.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-2 rounded-lg border px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 text-sm">
          <p className="font-medium">
            {connection.connected ? "Google account" : "No account connected"}
          </p>
          <p className="text-muted-foreground">
            {connection.connected
              ? connection.account ?? `Grant ending ${connection.fingerprint ?? "—"}`
              : "Grant access to store backups in Drive."}
          </p>
        </div>

        <div className="flex shrink-0 gap-2">
          <Button size="sm" onClick={() => void connect()} disabled={busy}>
            {connection.connected ? "Reconnect" : "Connect Google Drive"}
          </Button>
          {connection.connected && connection.source === "store" ? (
            <Button size="sm" variant="ghost" onClick={() => void disconnect()} disabled={busy}>
              Disconnect
            </Button>
          ) : null}
        </div>
      </div>

      {connection.connected_at ? (
        <p className="text-xs text-muted-foreground">
          Connected {formatTime(connection.connected_at)}.
        </p>
      ) : null}

      {/*
        Worth saying plainly: this installation is still running on the token
        pasted into .env, so the button has never been used here. Reconnecting
        moves it into the encrypted store, after which renewals no longer touch
        the environment or need a deploy.
      */}
      {connection.source === "env" ? (
        <p className="text-xs text-muted-foreground">
          Using <code className="font-mono">GOOGLE_DRIVE_REFRESH_TOKEN</code> from the environment.
          Reconnect to replace it with a grant stored on the server, which can then be renewed from
          this page.
        </p>
      ) : null}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  )
}

/**
 * Why a local scan came back with nothing, in the operator's terms.
 *
 * "Unavailable" was the only alternative to "scanned", and the card was hard
 * coded to the latter anyway. These distinguish the three cases that need
 * three different fixes.
 */
const LOCAL_SCAN_LABELS: Record<string, string> = {
  ok: "Working copy, scanned",
  missing: "Directory does not exist",
  unreadable: "Directory exists but this server cannot read it",
  unconfigured: "No directory configured",
  failed: "The directory could not be listed",
  unavailable: "The local disk is not configured",
}

export function LocalLocationCard({
  available,
  scanStatus = "ok",
}: {
  available: boolean
  scanStatus?: string
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-4 py-5">
        <HardDrive className="size-8 text-primary" />
        <div className="flex-1">
          <p className="font-semibold">Local</p>
          <p className="text-sm text-muted-foreground">
            {LOCAL_SCAN_LABELS[scanStatus] ?? (available ? "Working copy, scanned" : "Unavailable")}
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
        {collection.scan.local !== "ok" ? (
          <div className="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
            <p className="font-medium text-amber-800 dark:text-amber-300">
              Local files were not read — {LOCAL_SCAN_LABELS[collection.scan.local] ?? collection.scan.local}
            </p>
            {collection.scan.local_path ? (
              <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                {collection.scan.local_path}
              </p>
            ) : null}
            <p className="mt-2 text-xs text-muted-foreground">
              Nothing below is evidence that a local copy is missing — this server could not look.
            </p>
          </div>
        ) : null}

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
