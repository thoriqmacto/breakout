import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/** Statuses a run can end in. Only `success` is unambiguously good. */
export type RunStatus =
  | "pending"
  | "running"
  | "success"
  | "failed"
  | "skipped"
  | "blocked_token"

export type TaskCondition = "none" | "trading_day" | "last_trading_day_of_week"

export type TokenStatus =
  | "healthy"
  | "expiring_soon"
  | "expired"
  | "missing"
  | "expiry_unknown"

export type ParameterType =
  | "boolean"
  | "integer"
  | "date"
  | "enum"
  | "string"
  | "symbol_list"

export type ParameterSpec = {
  name: string
  type: ParameterType
  label: string
  values: string[] | null
}

/**
 * One allowlisted Artisan command. The form can only offer these, and only the
 * parameters each one declares — the API rejects anything else.
 */
export type CommandSpec = {
  command: string
  label: string
  description: string | null
  stockbit_bulk: boolean
  arguments: ParameterSpec[]
  options: ParameterSpec[]
}

export type TaskParameters = {
  arguments: Record<string, unknown>
  options: Record<string, unknown>
}

export type MirrorResult = {
  disk?: string | null
  uploaded?: number | string[]
  skipped_unchanged?: number
  failed?: string[]
  status?: string
  message?: string
}

export type TaskRun = {
  id: number
  scheduled_task_id: number
  scheduled_for: string | null
  trigger: "schedule" | "manual"
  status: RunStatus
  skip_reason: string | null
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
  exit_code: number | null
  output: string | null
  error: string | null
  metadata: Record<string, unknown>
  market_date: string | null
  range_from: string | null
  range_to: string | null
  ticker_count: number | null
  success_ticker_count: number | null
  failed_ticker_count: number | null
  partial: boolean
  gdrive: MirrorResult | null
  gdrive_broker_summary: MirrorResult | null
  created_at: string | null
}

export type ScheduledTask = {
  id: number
  name: string
  slug: string
  description: string | null
  command: string
  parameters: TaskParameters
  /** Human-readable only. The backend stores and executes the structure. */
  command_preview: string
  command_allowed: boolean
  stockbit_bulk: boolean
  cron_expression: string
  timezone: string
  condition: TaskCondition
  priority: number
  enabled: boolean
  sync_gdrive_after_success: boolean
  is_system: boolean
  last_run_at: string | null
  last_success_at: string | null
  last_failure_at: string | null
  next_run_at: string | null
  next_run_local: string | null
  latest_run: TaskRun | null
  created_at: string | null
  updated_at: string | null
}

export type TaskListMeta = {
  timezone: string
  application_timezone: string
  conditions: TaskCondition[]
  commands: CommandSpec[]
}

/** Never includes the bearer — only whether one exists and how long it lasts. */
export type StockbitTokenState = {
  status: TokenStatus
  configured: boolean
  source: string
  fingerprint: string | null
  expires_at: string | null
  expires_in_seconds: number | null
  expires_in_human: string | null
  warn_after_minutes: number
  min_ttl_minutes: number
  message: string
  can_start_bulk_job: boolean
}

export type AutomationAlert = {
  id: number
  type: string
  key: string
  severity: "info" | "warning" | "critical"
  title: string
  message: string
  context: Record<string, unknown>
  resolved_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type AutomationStatus = {
  scheduler: {
    timezone: string
    timezone_label: string
    application_timezone: string
    now_utc: string
    now_local: string
    dispatcher_command: string
    enabled_task_count: number
    total_task_count: number
    last_scheduled_run_at: string | null
    next_run: {
      task_id: number
      slug: string
      name: string
      at: string
      at_local: string
      timezone: string
    } | null
  }
  stockbit_token: StockbitTokenState
  google_drive: {
    health: {
      status: string
      configured: boolean
      connected: boolean
      can_read: boolean
      message: string
      guidance: string[]
      checked_at: string
    }
    broker_summary_mirror_disk: string | null
    bars_mirror_disk: string | null
  }
  trading_calendar: {
    today: string
    today_known: boolean
    today_is_trading_day: boolean
    week_status: string
    week_from: string | null
    week_to: string | null
    today_is_last_trading_day: boolean
    missing_dates: string[]
  }
  alerts: AutomationAlert[]
}

export type TaskFormValues = {
  name: string
  description: string
  command: string
  parameters: TaskParameters
  cron_expression: string
  timezone: string
  condition: TaskCondition
  priority: number
  enabled: boolean
  sync_gdrive_after_success: boolean
}

export const RUN_STATUS_LABELS: Record<RunStatus, string> = {
  pending: "Queued",
  running: "Running",
  success: "Success",
  failed: "Failed",
  skipped: "Skipped",
  blocked_token: "Token blocked",
}

export const RUN_STATUS_TONE: Record<RunStatus, string> = {
  pending: "bg-muted text-muted-foreground",
  running: "bg-sky-500/10 text-sky-700 dark:text-sky-400",
  success: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
  failed: "bg-destructive/10 text-destructive",
  skipped: "bg-amber-500/10 text-amber-700 dark:text-amber-400",
  blocked_token: "bg-destructive/10 text-destructive",
}

export const CONDITION_LABELS: Record<TaskCondition, string> = {
  none: "Always",
  trading_day: "IDX trading day",
  last_trading_day_of_week: "Last trading day of week",
}

export const CONDITION_HINTS: Record<TaskCondition, string> = {
  none: "Runs on every occurrence of the schedule.",
  trading_day:
    "Runs only when trading_calendar records the local Asia/Jakarta date as a trading day. A date with no calendar row is skipped rather than assumed.",
  last_trading_day_of_week:
    "Runs only when the local Asia/Jakarta date is the final trading day of its Monday–Sunday week, determined from trading_calendar. An incomplete week is skipped rather than guessed.",
}

export const TOKEN_STATUS_LABELS: Record<TokenStatus, string> = {
  healthy: "Healthy",
  expiring_soon: "Expiring soon",
  expired: "Expired",
  missing: "Not configured",
  expiry_unknown: "Expiry unknown",
}

export const TOKEN_STATUS_TONE: Record<TokenStatus, string> = {
  healthy: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
  expiring_soon: "bg-amber-500/10 text-amber-700 dark:text-amber-400",
  expired: "bg-destructive/10 text-destructive",
  missing: "bg-destructive/10 text-destructive",
  expiry_unknown: "bg-muted text-muted-foreground",
}

/** Human-readable reasons a run stood down without doing anything. */
export const SKIP_REASON_LABELS: Record<string, string> = {
  not_trading_day: "Not an IDX trading day",
  not_last_trading_day_of_week: "Not the week's final trading day",
  trading_calendar_incomplete: "Trading calendar incomplete",
  trading_calendar_unavailable: "Trading calendar unreadable",
  no_trading_days_in_week: "No trading day this week",
  overlapping_run: "Previous run still in progress",
  stockbit_busy: "Another Stockbit job held the lock",
  token_missing: "No Stockbit token stored",
  token_expired: "Stockbit token expired",
  token_ttl_too_short: "Stockbit token expires too soon",
  no_price_sync_assets: "No price-synced assets",
  no_broker_summary_assets: "No broker-summary assets",
}

function messageOf(payload: ApiResponse<unknown> | null, fallback: string): string {
  return (
    (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
    fallback
  )
}

async function request<T>(
  token: string,
  path: string,
  init: RequestInit = {},
  fallback = "The request failed",
): Promise<{ data: T; meta: unknown; message: string | null }> {
  const response = await fetch(buildApiUrl(path), {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
      ...(init.headers ?? {}),
    },
  })

  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(messageOf(payload, fallback))
  }

  return {
    data: payload.data,
    meta: "meta" in payload ? payload.meta : null,
    message: "message" in payload ? (payload.message ?? null) : null,
  }
}

export async function fetchAutomationStatus(
  token: string,
  fresh = false,
): Promise<AutomationStatus> {
  const { data } = await request<AutomationStatus>(
    token,
    `/v1/automation/status${fresh ? "?fresh=1" : ""}`,
    {},
    "Unable to load automation status",
  )

  return data
}

export async function fetchScheduledTasks(
  token: string,
): Promise<{ tasks: ScheduledTask[]; meta: TaskListMeta }> {
  const { data, meta } = await request<ScheduledTask[]>(
    token,
    "/v1/scheduled-tasks",
    {},
    "Unable to load scheduled tasks",
  )

  return { tasks: data, meta: meta as TaskListMeta }
}

export async function createScheduledTask(
  token: string,
  values: TaskFormValues,
): Promise<ScheduledTask> {
  const { data } = await request<ScheduledTask>(
    token,
    "/v1/scheduled-tasks",
    { method: "POST", body: JSON.stringify(values) },
    "Unable to create the automation",
  )

  return data
}

export async function updateScheduledTask(
  token: string,
  id: number,
  values: TaskFormValues,
): Promise<ScheduledTask> {
  const { data } = await request<ScheduledTask>(
    token,
    `/v1/scheduled-tasks/${id}`,
    { method: "PUT", body: JSON.stringify(values) },
    "Unable to update the automation",
  )

  return data
}

export async function toggleScheduledTask(
  token: string,
  id: number,
  enabled: boolean,
): Promise<ScheduledTask> {
  const { data } = await request<ScheduledTask>(
    token,
    `/v1/scheduled-tasks/${id}/toggle`,
    { method: "POST", body: JSON.stringify({ enabled }) },
    "Unable to change the automation",
  )

  return data
}

export async function deleteScheduledTask(token: string, id: number): Promise<void> {
  await request<null>(
    token,
    `/v1/scheduled-tasks/${id}`,
    { method: "DELETE" },
    "Unable to delete the automation",
  )
}

export async function runScheduledTask(
  token: string,
  id: number,
  force = false,
): Promise<TaskRun> {
  const { data } = await request<TaskRun>(
    token,
    `/v1/scheduled-tasks/${id}/run`,
    { method: "POST", body: JSON.stringify({ force }) },
    "Unable to start the automation",
  )

  return data
}

export async function fetchTaskRuns(
  token: string,
  id: number,
  perPage = 25,
): Promise<TaskRun[]> {
  const { data } = await request<TaskRun[]>(
    token,
    `/v1/scheduled-tasks/${id}/runs?per_page=${perPage}`,
    {},
    "Unable to load run history",
  )

  return data
}

export async function fetchTokenStatus(token: string): Promise<StockbitTokenState> {
  const { data } = await request<StockbitTokenState>(
    token,
    "/v1/automation/stockbit-token",
    {},
    "Unable to load the Stockbit token status",
  )

  return data
}

/**
 * Send a new bearer. The response carries the resulting status; the token is
 * never echoed back by the API and is not kept anywhere on the client.
 */
export async function renewStockbitToken(
  token: string,
  bearer: string,
): Promise<StockbitTokenState> {
  const { data } = await request<StockbitTokenState>(
    token,
    "/v1/automation/stockbit-token",
    { method: "PUT", body: JSON.stringify({ token: bearer }) },
    "Unable to save the Stockbit token",
  )

  return data
}

export async function clearStockbitToken(token: string): Promise<StockbitTokenState> {
  const { data } = await request<StockbitTokenState>(
    token,
    "/v1/automation/stockbit-token",
    { method: "DELETE" },
    "Unable to clear the Stockbit token",
  )

  return data
}

export async function fetchAutomationAlerts(token: string): Promise<AutomationAlert[]> {
  const { data } = await request<AutomationAlert[]>(
    token,
    "/v1/automation/alerts",
    {},
    "Unable to load automation alerts",
  )

  return data
}

export async function dismissAutomationAlert(token: string, id: number): Promise<void> {
  await request<null>(
    token,
    `/v1/automation/alerts/${id}`,
    { method: "DELETE" },
    "Unable to dismiss the alert",
  )
}

/**
 * The same preview the API generates, recomputed locally so the form can show
 * it while the user types. It is display text: nothing parses it back, and the
 * request carries the structured parameters.
 */
export function buildCommandPreview(
  command: string,
  parameters: TaskParameters,
  spec?: CommandSpec,
): string {
  const parts = ["php", "artisan", command || "…"]

  for (const value of Object.values(parameters.arguments ?? {})) {
    for (const item of toList(value)) parts.push(item)
  }

  for (const [name, value] of Object.entries(parameters.options ?? {})) {
    const type = spec?.options.find((option) => option.name === name)?.type
    if (type === "boolean") {
      if (value === true) parts.push(`--${name}`)
      continue
    }
    for (const item of toList(value)) parts.push(`--${name}=${item}`)
  }

  return parts.join(" ")
}

function toList(value: unknown): string[] {
  if (value === null || value === undefined || value === "" || value === false) return []
  if (Array.isArray(value)) return value.map(String).filter((item) => item !== "")
  return [String(value)]
}

export function formatDuration(ms: number | null): string {
  if (ms === null) return "—"
  if (ms < 1000) return `${ms} ms`
  const seconds = ms / 1000
  if (seconds < 60) return `${seconds.toFixed(1)} s`
  const minutes = Math.floor(seconds / 60)
  return `${minutes}m ${Math.round(seconds % 60)}s`
}

export function formatTimestamp(value: string | null): string {
  if (!value) return "—"
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? "—" : date.toLocaleString()
}

/** The same instant rendered on the IDX market clock. */
export function formatJakarta(value: string | null): string {
  if (!value) return "—"
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return "—"
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Jakarta",
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date)
}

export function describeSkipReason(reason: string | null): string | null {
  if (!reason) return null
  return SKIP_REASON_LABELS[reason] ?? reason.replace(/_/g, " ")
}
