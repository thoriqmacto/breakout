import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * A file's state is decided on content, never on a filename matching in two
 * places. "synced" means the bytes were compared and found identical.
 */
export type BackupState =
  | "synced"
  | "local_only"
  | "gdrive_only"
  | "local_newer"
  | "gdrive_newer"
  | "different"
  | "compare_error"

export type DriveStatus =
  | "healthy"
  | "not_configured"
  | "renew_required"
  | "invalid_client"
  | "scope_error"
  | "api_disabled"
  | "unreachable"
  | "drive_error"
  | "unknown_error"

export type RefreshTokenStatus = "valid" | "renew_required" | "not_configured" | "unknown"

export type FileInfo = {
  path: string
  size: number | null
  modified_at: string | null
  hash: string | null
}

export type BackupFile = {
  name: string
  state: BackupState
  can_push: boolean
  local: FileInfo | null
  gdrive: FileInfo | null
}

export type BackupCounts = {
  total: number
  local: number
  gdrive: number
  synced: number
  pending_push: number
  local_only: number
  gdrive_only: number
  local_newer: number
  remote_newer: number
  different: number
  errors: number
}

export type Collection = {
  key: string
  label: string
  pushable: boolean
  scan: { local: string; gdrive: string }
  counts: BackupCounts
  files: BackupFile[]
}

/** Credentials never appear here — only whether they are configured. */
export type DriveHealth = {
  status: DriveStatus
  configured: boolean
  connected: boolean
  refresh_token_status: RefreshTokenStatus
  can_read: boolean
  code: string | null
  message: string
  guidance: string[]
  checked_at: string
}

export type Report = {
  generated_at: string
  google_drive: DriveHealth
  locations: { key: string; label: string; available: boolean; scan_status: string }[]
  collections: Collection[]
}

export type PushResult = {
  uploaded: string[]
  skipped: string[]
  failed: string[]
  rejected: string[]
  /** Broker-summary pushes also report archive paths with no local file. */
  missing?: string[]
  report: Report
}

/**
 * The fast payload: recovery readiness, not a file-by-file comparison.
 *
 * `GET /v1/backup-status` answers "can I rebuild, and is the off-server copy
 * current?" from three reads. The forensic per-file comparison is the same
 * `Report` as before, now behind `GET /v1/backup-status/audit`, because its
 * cost grows with every archived broker-summary JSON and the page should not
 * get slower every day it is used.
 */
export type ReadinessStatus = "ready" | "degraded" | "not_ready"

export type IntegrityStatus = "healthy" | "warning" | "error"

/** Nothing here reports "in sync" from a filename; hashes are compared. */
export type MirrorState = {
  enabled: boolean
  disk: string | null
  reachable: boolean
  manifest_present: boolean
  manifest_hash: string | null
  local_manifest_hash: string | null
  in_sync: boolean
  message: string | null
}

export type ManifestSummary = {
  present: boolean
  schema_version: number | null
  generated_at: string | null
  market_date: string | null
  manifest_path: string
  manifest_hash: string | null
  asset_count: number
  healthy: number
  warning: number
  error: number
  with_gaps: number
  ohlcv_current: number
  broker_current: number
  latest_ohlcv_date: string | null
  latest_broker_daily_date: string | null
}

/**
 * A flow balance always travels with the number of sessions it was computed
 * from. "+3" over three available sessions and "+3" over twenty are different
 * statements, and a reader given only the number cannot tell them apart.
 */
export type FlowRow = {
  symbol: string
  latest_broker_date: string | null
  latest_accdist: string | null
  flow_balance: number | null
  available_sessions: number
  required_sessions: number
  price_return: number | null
  daily_windows: number
  integrity_status: IntegrityStatus
}

export type FlowSnapshot = {
  window: number
  ranked_count: number
  accumulating: FlowRow[]
  distributing: FlowRow[]
  insufficient: FlowRow[]
  insufficient_count: number
  note: string
}

export type ReadinessReport = {
  generated_at: string
  google_drive: DriveHealth
  reconciliation: ManifestSummary
  mirror: MirrorState
  raw_archive: { mirror_enabled: boolean; disk: string | null; path: string }
  readiness: { status: ReadinessStatus; blockers: string[]; warnings: string[] }
  flow_snapshot: FlowSnapshot
}

/** The deep audit returns the old report plus the readiness block. */
export type AuditReport = Report & { readiness_report: ReadinessReport }

export type MirrorCollection = "historical" | "broker_summary"

/** Presentation for each state. Only "synced" is ever green. */
export const STATE_LABELS: Record<BackupState, string> = {
  synced: "In sync",
  local_only: "Local only",
  gdrive_only: "Drive only",
  local_newer: "Local newer",
  gdrive_newer: "Drive newer",
  different: "Contents differ",
  compare_error: "Compare failed",
}

export const STATE_HINTS: Record<BackupState, string> = {
  synced: "Local and Google Drive contents are identical.",
  local_only: "No corresponding Drive backup exists. Push to create one.",
  gdrive_only: "A Drive backup exists with no local counterpart.",
  local_newer: "The local working copy changed after the last Drive backup. Push to update Drive.",
  gdrive_newer:
    "The Drive backup was modified after the local copy. Review before restoring or overwriting.",
  different:
    "Both copies exist but do not match, and modification order is unclear. Pushing overwrites Drive with the local copy.",
  compare_error: "Google Drive could not be read, so these files could not be compared.",
}

function message(payload: ApiResponse<unknown> | null, fallback: string): string {
  return (
    (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
    fallback
  )
}

async function get<T>(token: string, path: string, fallback: string): Promise<T> {
  const response = await fetch(buildApiUrl(path), {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
  })
  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(message(payload, fallback))
  }

  return payload.data
}

/** The default page load: three reads, whatever the archive size. */
export function fetchReadiness(token: string): Promise<ReadinessReport> {
  return get<ReadinessReport>(token, "/v1/backup-status", "Unable to load backup readiness")
}

/**
 * The forensic comparison, only when the reader asks for it.
 *
 * `fresh` bypasses the server-side cache, which matters straight after a push:
 * a cached report showing a file as still pending is worse than the calls it
 * saved.
 */
export function fetchAudit(token: string, fresh = false): Promise<AuditReport> {
  return get<AuditReport>(
    token,
    `/v1/backup-status/audit${fresh ? "?fresh=1" : ""}`,
    "Unable to run the deep audit",
  )
}

/** Retained for callers that want the old single-request behaviour. */
export function fetchBackupReport(token: string, fresh = false): Promise<Report> {
  return get<Report>(
    token,
    `/v1/backup-status?deep=1${fresh ? "&fresh=1" : ""}`,
    "Unable to load backup status",
  )
}

/**
 * Push local copies to Drive.
 *
 * The client names symbols, never paths, and only for the historical
 * collection; the broker-summary archive is enumerated server-side from the
 * local listing, so the browser cannot reach a file the page did not offer.
 */
export async function pushToDrive(
  token: string,
  symbols?: string[],
  collection: MirrorCollection = "historical",
): Promise<PushResult> {
  const response = await fetch(buildApiUrl("/v1/backup-status/mirror-push"), {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      collection,
      ...(collection === "historical" && symbols && symbols.length > 0 ? { symbols } : {}),
    }),
  })
  const payload = await parseJson<ApiResponse<PushResult>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(message(payload, "The mirror push failed"))
  }

  return payload.data
}

/** Strip the extension so the API receives a symbol, never a path. */
export function symbolOf(fileName: string): string {
  return fileName.replace(/\.[^.]+$/, "").toUpperCase()
}
