import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

export type RuleFieldType = "number" | "boolean"

export type RuleField = {
  key: string
  label: string
  type: RuleFieldType
  group: string
}

export type RuleSchema = {
  fields: RuleField[]
  operators: Record<string, string>
  operators_by_type: Record<RuleFieldType, string[]>
  limits: { max_depth: number; max_conditions: number }
}

/** A leaf comparison. `value` is unused by the unary predicates. */
export type RuleCondition = {
  field: string
  operator: string
  value?: number | [number, number] | null
}

/** An AND/OR group. Trees nest groups inside groups up to the schema's depth. */
export type RuleGroup = {
  op: "and" | "or"
  rules: RuleNode[]
}

export type RuleNode = RuleCondition | RuleGroup

export function isGroup(node: RuleNode): node is RuleGroup {
  return (node as RuleGroup).op !== undefined
}

export type RunStatus = "queued" | "running" | "completed" | "failed"

export type StrategyRecord = {
  id: number
  name: string
  description: string | null
  visibility: "private" | "public"
  is_active: boolean
  owner: { id: number; name: string } | null
  is_owner: boolean
  copied_from_id: number | null
  runs_count: number | null
  last_run_at: string | null
  last_run_status: RunStatus | null
  last_match_count: number | null
  rules?: RuleNode
}

export type StrategyRun = {
  id: number
  strategy_id: number
  scan_date: string | null
  status: RunStatus
  evaluated_count: number
  matched_count: number
  error: string | null
  started_at: string | null
  finished_at: string | null
}

export type ExplanationEntry = {
  field: string
  label: string
  operator: string
  value: number | [number, number] | null
  actual: number | boolean | null
  passed: boolean
}

export type StrategyMatch = {
  symbol: string
  asset: { id: number; symbol: string; name: string | null; sector: string | null } | null
  facts: Record<string, number | boolean | null>
  explanation: ExplanationEntry[]
}

function headers(accessToken: string, json = false): HeadersInit {
  return {
    Accept: "application/json",
    ...(json ? { "Content-Type": "application/json" } : {}),
    Authorization: `Bearer ${accessToken}`,
  }
}

function errorMessage(payload: ApiResponse<unknown> | null, fallback: string): string {
  if (payload && payload.status === "error") {
    // Rule validation returns a per-condition list under errors.rules; surface
    // the first one, which points at the condition the user needs to fix.
    const errors = (payload as { errors?: { rules?: string[] } }).errors
    if (errors?.rules?.length) {
      return errors.rules[0]
    }
    if (typeof payload.message === "string") {
      return payload.message
    }
  }
  return fallback
}

async function request<T>(
  url: string,
  accessToken: string,
  init: RequestInit & { json?: unknown } = {},
  fallback = "Request failed.",
): Promise<T> {
  const { json, ...rest } = init

  const response = await fetch(buildApiUrl(url), {
    ...rest,
    headers: headers(accessToken, json !== undefined),
    ...(json !== undefined ? { body: JSON.stringify(json) } : {}),
  })

  const payload = await parseJson<ApiResponse<T>>(response)

  if (!response.ok || !payload || payload.status !== "success") {
    throw new Error(errorMessage(payload, fallback))
  }

  return payload.data as T
}

export function fetchRuleSchema(accessToken: string): Promise<RuleSchema> {
  return request<RuleSchema>("/v1/strategies/schema", accessToken, {}, "Unable to load the rule schema.")
}

export async function fetchStrategies(
  accessToken: string,
  scope: "mine" | "public" | "all" = "all",
): Promise<StrategyRecord[]> {
  const data = await request<{ strategies: StrategyRecord[] }>(
    `/v1/strategies?scope=${scope}`,
    accessToken,
    {},
    "Unable to load strategies.",
  )
  return data.strategies
}

export async function fetchStrategy(accessToken: string, id: number): Promise<StrategyRecord> {
  const data = await request<{ strategy: StrategyRecord }>(
    `/v1/strategies/${id}`,
    accessToken,
    {},
    "Unable to load the strategy.",
  )
  return data.strategy
}

export type StrategyInput = {
  name: string
  description?: string | null
  visibility: "private" | "public"
  is_active?: boolean
  rules: RuleNode
}

export async function createStrategy(
  accessToken: string,
  input: StrategyInput,
): Promise<StrategyRecord> {
  const data = await request<{ strategy: StrategyRecord }>(
    "/v1/strategies",
    accessToken,
    { method: "POST", json: input },
    "Unable to create the strategy.",
  )
  return data.strategy
}

export async function updateStrategy(
  accessToken: string,
  id: number,
  input: Partial<StrategyInput>,
): Promise<StrategyRecord> {
  const data = await request<{ strategy: StrategyRecord }>(
    `/v1/strategies/${id}`,
    accessToken,
    { method: "PATCH", json: input },
    "Unable to update the strategy.",
  )
  return data.strategy
}

export async function deleteStrategy(accessToken: string, id: number): Promise<void> {
  await request<null>(
    `/v1/strategies/${id}`,
    accessToken,
    { method: "DELETE" },
    "Unable to delete the strategy.",
  )
}

export async function copyStrategy(
  accessToken: string,
  id: number,
  name?: string,
): Promise<StrategyRecord> {
  const data = await request<{ strategy: StrategyRecord }>(
    `/v1/strategies/${id}/copy`,
    accessToken,
    { method: "POST", json: name ? { name } : {} },
    "Unable to copy the strategy.",
  )
  return data.strategy
}

export async function runStrategy(
  accessToken: string,
  id: number,
  date?: string,
): Promise<StrategyRun> {
  const data = await request<{ run: StrategyRun }>(
    `/v1/strategies/${id}/run`,
    accessToken,
    { method: "POST", json: date ? { date } : {} },
    "Unable to queue the run.",
  )
  return data.run
}

export async function fetchRuns(accessToken: string, id: number): Promise<StrategyRun[]> {
  const data = await request<{ runs: StrategyRun[] }>(
    `/v1/strategies/${id}/runs`,
    accessToken,
    {},
    "Unable to load run history.",
  )
  return data.runs
}

export async function fetchRunMatches(
  accessToken: string,
  strategyId: number,
  runId: number,
): Promise<{ run: StrategyRun; matches: StrategyMatch[] }> {
  return request<{ run: StrategyRun; matches: StrategyMatch[] }>(
    `/v1/strategies/${strategyId}/runs/${runId}`,
    accessToken,
    {},
    "Unable to load run results.",
  )
}

/** Starting point for a new strategy: one empty AND group. */
export function emptyRuleTree(): RuleGroup {
  return { op: "and", rules: [] }
}

export function describeCondition(
  condition: RuleCondition,
  schema: RuleSchema | null,
): string {
  const field = schema?.fields.find((f) => f.key === condition.field)
  const label = field?.label ?? condition.field
  const operator = schema?.operators[condition.operator] ?? condition.operator

  if (condition.value === undefined || condition.value === null) {
    return `${label} ${operator}`
  }

  if (Array.isArray(condition.value)) {
    return `${label} ${operator} ${condition.value[0]} and ${condition.value[1]}`
  }

  return `${label} ${operator} ${condition.value}`
}
