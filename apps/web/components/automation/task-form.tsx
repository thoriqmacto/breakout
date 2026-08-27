"use client"

import { useMemo, useState, type FormEvent } from "react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  CONDITION_HINTS,
  CONDITION_LABELS,
  buildCommandPreview,
  type CommandSpec,
  type ParameterSpec,
  type ScheduledTask,
  type TaskCondition,
  type TaskFormValues,
  type TaskParameters,
} from "@/lib/automation-client"

const CONDITIONS: TaskCondition[] = ["none", "trading_day", "last_trading_day_of_week"]

/**
 * Timezones offered in the picker. Asia/Jakarta is first and is the default:
 * every market schedule in this system is a WIB time, and a task created in
 * the browser's own zone would drift against the IDX session.
 */
const TIMEZONES = [
  "Asia/Jakarta",
  "Asia/Makassar",
  "Asia/Jayapura",
  "Asia/Singapore",
  "Asia/Qatar",
  "Europe/London",
  "UTC",
]

const CRON_PRESETS: { label: string; value: string }[] = [
  { label: "Daily at 16:00", value: "0 16 * * *" },
  { label: "Daily at 09:00", value: "0 9 * * *" },
  { label: "Weekdays at 16:00", value: "0 16 * * 1-5" },
  { label: "Hourly", value: "0 * * * *" },
]

const selectClass =
  "h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"

function emptyParameters(): TaskParameters {
  return { arguments: {}, options: {} }
}

function defaultsFor(task: ScheduledTask | null, commands: CommandSpec[]): TaskFormValues {
  if (task) {
    return {
      name: task.name,
      description: task.description ?? "",
      command: task.command,
      parameters: {
        arguments: { ...(task.parameters?.arguments ?? {}) },
        options: { ...(task.parameters?.options ?? {}) },
      },
      cron_expression: task.cron_expression,
      timezone: task.timezone,
      condition: task.condition,
      priority: task.priority,
      enabled: task.enabled,
      sync_gdrive_after_success: task.sync_gdrive_after_success,
    }
  }

  return {
    name: "",
    description: "",
    command: commands[0]?.command ?? "",
    parameters: emptyParameters(),
    cron_expression: "0 16 * * *",
    timezone: "Asia/Jakarta",
    condition: "none",
    priority: 100,
    enabled: true,
    sync_gdrive_after_success: false,
  }
}

/**
 * Create/edit form.
 *
 * The command is chosen from the server's allowlist and each parameter is
 * rendered from that command's declared specification, so the form cannot even
 * express a request the API would reject as unapproved. The preview underneath
 * is text for a human to read; the submitted payload is the structure.
 */
export function TaskForm({
  task,
  commands,
  saving,
  onSubmit,
  onCancel,
}: {
  task: ScheduledTask | null
  commands: CommandSpec[]
  saving: boolean
  onSubmit: (values: TaskFormValues) => void
  onCancel: () => void
}) {
  const [values, setValues] = useState<TaskFormValues>(() => defaultsFor(task, commands))

  const spec = useMemo(
    () => commands.find((entry) => entry.command === values.command),
    [commands, values.command],
  )

  const preview = useMemo(
    () => buildCommandPreview(values.command, values.parameters, spec),
    [spec, values.command, values.parameters],
  )

  const setParameter = (group: keyof TaskParameters, name: string, value: unknown) => {
    setValues((current) => {
      const next = { ...current.parameters[group] }

      if (value === "" || value === null || value === undefined || value === false) {
        delete next[name]
      } else {
        next[name] = value
      }

      return { ...current, parameters: { ...current.parameters, [group]: next } }
    })
  }

  const changeCommand = (command: string) => {
    // Parameters are declared per command, so keeping the old ones would send
    // options the new command does not accept.
    setValues((current) => ({ ...current, command, parameters: emptyParameters() }))
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    onSubmit(values)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{task ? `Edit ${task.name}` : "New automation"}</CardTitle>
        <CardDescription>
          Only commands on the server allowlist can be scheduled, and each one accepts only the
          parameters it declares. Nothing here is executed as a shell command.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form onSubmit={submit} className="space-y-5">
          <div className="grid gap-4 md:grid-cols-2">
            <label className="space-y-1.5 text-sm">
              <span className="font-medium">Name</span>
              <Input
                required
                maxLength={191}
                value={values.name}
                onChange={(event) => setValues((c) => ({ ...c, name: event.target.value }))}
              />
            </label>

            <label className="space-y-1.5 text-sm">
              <span className="font-medium">Artisan command</span>
              <select
                className={selectClass}
                value={values.command}
                onChange={(event) => changeCommand(event.target.value)}
              >
                {commands.map((entry) => (
                  <option key={entry.command} value={entry.command}>
                    {entry.label} ({entry.command})
                  </option>
                ))}
              </select>
            </label>
          </div>

          <label className="block space-y-1.5 text-sm">
            <span className="font-medium">Description</span>
            <Input
              maxLength={1000}
              value={values.description}
              onChange={(event) => setValues((c) => ({ ...c, description: event.target.value }))}
            />
          </label>

          {spec?.description ? (
            <p className="text-sm text-muted-foreground">{spec.description}</p>
          ) : null}

          {spec && (spec.arguments.length > 0 || spec.options.length > 0) ? (
            <fieldset className="space-y-3 rounded-lg border p-4">
              <legend className="px-1 text-sm font-medium">Parameters</legend>

              {spec.arguments.map((parameter) => (
                <ParameterField
                  key={`argument-${parameter.name}`}
                  parameter={parameter}
                  value={values.parameters.arguments[parameter.name]}
                  onChange={(value) => setParameter("arguments", parameter.name, value)}
                />
              ))}

              {spec.options.map((parameter) => (
                <ParameterField
                  key={`option-${parameter.name}`}
                  parameter={parameter}
                  value={values.parameters.options[parameter.name]}
                  onChange={(value) => setParameter("options", parameter.name, value)}
                />
              ))}
            </fieldset>
          ) : null}

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-1.5 text-sm">
              <label className="font-medium" htmlFor="cron">
                Schedule (cron)
              </label>
              <Input
                id="cron"
                required
                value={values.cron_expression}
                onChange={(event) =>
                  setValues((c) => ({ ...c, cron_expression: event.target.value }))
                }
              />
              <div className="flex flex-wrap gap-1.5">
                {CRON_PRESETS.map((preset) => (
                  <button
                    key={preset.value}
                    type="button"
                    className="rounded-full bg-muted px-2.5 py-1 text-xs hover:bg-accent"
                    onClick={() => setValues((c) => ({ ...c, cron_expression: preset.value }))}
                  >
                    {preset.label}
                  </button>
                ))}
              </div>
            </div>

            <label className="space-y-1.5 text-sm">
              <span className="font-medium">Timezone</span>
              <select
                className={selectClass}
                value={values.timezone}
                onChange={(event) => setValues((c) => ({ ...c, timezone: event.target.value }))}
              >
                {TIMEZONES.map((zone) => (
                  <option key={zone} value={zone}>
                    {zone === "Asia/Jakarta" ? "Asia/Jakarta (WIB / UTC+7)" : zone}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <label className="space-y-1.5 text-sm">
              <span className="font-medium">Condition</span>
              <select
                className={selectClass}
                value={values.condition}
                onChange={(event) =>
                  setValues((c) => ({ ...c, condition: event.target.value as TaskCondition }))
                }
              >
                {CONDITIONS.map((condition) => (
                  <option key={condition} value={condition}>
                    {CONDITION_LABELS[condition]}
                  </option>
                ))}
              </select>
              <span className="block text-xs text-muted-foreground">
                {CONDITION_HINTS[values.condition]}
              </span>
            </label>

            <label className="space-y-1.5 text-sm">
              <span className="font-medium">Priority</span>
              <Input
                type="number"
                min={0}
                max={65535}
                value={values.priority}
                onChange={(event) =>
                  setValues((c) => ({ ...c, priority: Number(event.target.value) || 0 }))
                }
              />
              <span className="block text-xs text-muted-foreground">
                Lower runs first. Two jobs due in the same minute run in this order, one after
                the other.
              </span>
            </label>
          </div>

          <div className="flex flex-wrap gap-6">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4"
                checked={values.enabled}
                onChange={(event) => setValues((c) => ({ ...c, enabled: event.target.checked }))}
              />
              Enabled
            </label>

            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4"
                checked={values.sync_gdrive_after_success}
                onChange={(event) =>
                  setValues((c) => ({ ...c, sync_gdrive_after_success: event.target.checked }))
                }
              />
              Sync to Google Drive after a successful run
            </label>
          </div>

          <div className="space-y-1.5">
            <p className="text-sm font-medium">Command preview</p>
            <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">{preview}</pre>
            <p className="text-xs text-muted-foreground">
              Shown for reading only. The server stores the command name and the parameters
              above as structured data and runs them through Artisan directly.
            </p>
          </div>

          <div className="flex gap-2">
            <Button type="submit" disabled={saving}>
              {task ? "Save changes" : "Create automation"}
            </Button>
            <Button type="button" variant="outline" onClick={onCancel} disabled={saving}>
              Cancel
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

function ParameterField({
  parameter,
  value,
  onChange,
}: {
  parameter: ParameterSpec
  value: unknown
  onChange: (value: unknown) => void
}) {
  if (parameter.type === "boolean") {
    return (
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4"
          checked={value === true}
          onChange={(event) => onChange(event.target.checked)}
        />
        <span>
          {parameter.label} <code className="text-xs text-muted-foreground">--{parameter.name}</code>
        </span>
      </label>
    )
  }

  if (parameter.type === "enum") {
    return (
      <label className="block space-y-1.5 text-sm">
        <span>{parameter.label}</span>
        <select
          className={selectClass}
          value={typeof value === "string" ? value : ""}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="">Not set</option>
          {(parameter.values ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>
    )
  }

  if (parameter.type === "symbol_list") {
    return (
      <label className="block space-y-1.5 text-sm">
        <span>{parameter.label}</span>
        <Input
          placeholder="BBCA, ANTM, INCO"
          value={Array.isArray(value) ? value.join(", ") : ""}
          onChange={(event) => {
            const symbols = event.target.value
              .split(/[\s,]+/)
              .map((symbol) => symbol.trim().toUpperCase())
              .filter(Boolean)

            onChange(symbols.length > 0 ? symbols : "")
          }}
        />
        <span className="block text-xs text-muted-foreground">
          Leave empty to use the assets configured for this job.
        </span>
      </label>
    )
  }

  return (
    <label className="block space-y-1.5 text-sm">
      <span>{parameter.label}</span>
      <Input
        type={parameter.type === "integer" ? "number" : parameter.type === "date" ? "date" : "text"}
        value={typeof value === "string" || typeof value === "number" ? String(value) : ""}
        onChange={(event) =>
          onChange(
            parameter.type === "integer"
              ? event.target.value === ""
                ? ""
                : Number(event.target.value)
              : event.target.value,
          )
        }
      />
    </label>
  )
}
