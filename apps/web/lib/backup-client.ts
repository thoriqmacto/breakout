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
  report: Report
}

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

export async function fetchBackupReport(token: string, fresh = false): Promise<Report> {
  const response = await fetch(buildApiUrl(`/v1/backup-status${fresh ? "?fresh=1" : ""}`), {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
  })
  const payload = await parseJson<ApiResponse<Report>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(message(payload, "Unable to load backup status"))
  }

  return payload.data
}

export async function pushToDrive(token: string, symbols?: string[]): Promise<PushResult> {
  const response = await fetch(buildApiUrl("/v1/backup-status/mirror-push"), {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      collection: "historical",
      ...(symbols && symbols.length > 0 ? { symbols } : {}),
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
