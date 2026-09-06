"use client"

import { useCallback, useEffect, useState } from "react"
import { Plus, RefreshCw, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { AutomationAlertBanner } from "@/components/automation/alert-banner"
import { RunHistory } from "@/components/automation/run-history"
import { StatusHeader } from "@/components/automation/status-header"
import { TaskForm } from "@/components/automation/task-form"
import { TaskTable } from "@/components/automation/task-table"
import { StockbitTokenCard } from "@/components/automation/token-card"
import {
  createScheduledTask,
  deleteScheduledTask,
  fetchAutomationStatus,
  fetchScheduledTasks,
  fetchTaskRuns,
  runScheduledTask,
  toggleScheduledTask,
  updateScheduledTask,
  type AutomationStatus,
  type CommandSpec,
  type ScheduledTask,
  type StockbitTokenState,
  type TaskFormValues,
  type TaskRun,
} from "@/lib/automation-client"

export function AutomationClient() {
  const { accessToken } = useAuth()

  const [status, setStatus] = useState<AutomationStatus | null>(null)
  const [tasks, setTasks] = useState<ScheduledTask[]>([])
  const [commands, setCommands] = useState<CommandSpec[]>([])

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const [editing, setEditing] = useState<ScheduledTask | null>(null)
  const [creating, setCreating] = useState(false)

  const [historyTask, setHistoryTask] = useState<ScheduledTask | null>(null)
  const [runs, setRuns] = useState<TaskRun[]>([])
  const [runsLoading, setRunsLoading] = useState(false)

  const load = useCallback(
    async (fresh = false) => {
      if (!accessToken) return

      setLoading(true)
      setError(null)

      try {
        const [nextStatus, list] = await Promise.all([
          fetchAutomationStatus(accessToken, fresh),
          fetchScheduledTasks(accessToken),
        ])

        setStatus(nextStatus)
        setTasks(list.tasks)
        setCommands(list.meta?.commands ?? [])
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Unable to load automation")
      } finally {
        setLoading(false)
      }
    },
    [accessToken],
  )

  useEffect(() => {
    void load()
  }, [load])

  const loadRuns = useCallback(
    async (task: ScheduledTask) => {
      if (!accessToken) return

      setHistoryTask(task)
      setRunsLoading(true)

      try {
        setRuns(await fetchTaskRuns(accessToken, task.id))
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Unable to load run history")
        setRuns([])
      } finally {
        setRunsLoading(false)
      }
    },
    [accessToken],
  )

  const replace = (task: ScheduledTask) => {
    setTasks((current) => current.map((entry) => (entry.id === task.id ? task : entry)))
  }

  const save = async (values: TaskFormValues) => {
    if (!accessToken) return

    setSaving(true)
    setError(null)
    setNotice(null)

    try {
      if (editing) {
        replace(await updateScheduledTask(accessToken, editing.id, values))
        setNotice(`${values.name} updated.`)
      } else {
        const created = await createScheduledTask(accessToken, values)
        setTasks((current) => [...current, created])
        setNotice(`${created.name} created.`)
      }

      setEditing(null)
      setCreating(false)
      void load()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to save the automation")
    } finally {
      setSaving(false)
    }
  }

  const toggle = async (task: ScheduledTask) => {
    if (!accessToken) return

    setBusyId(task.id)
    setError(null)

    try {
      replace(await toggleScheduledTask(accessToken, task.id, !task.enabled))
      setNotice(`${task.name} ${task.enabled ? "disabled" : "enabled"}.`)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to change the automation")
    } finally {
      setBusyId(null)
    }
  }

  const run = async (task: ScheduledTask) => {
    if (!accessToken) return

    setBusyId(task.id)
    setError(null)
    setNotice(null)

    try {
      await runScheduledTask(accessToken, task.id, !task.enabled)
      setNotice(
        `${task.name} queued. Market conditions still apply, so a run on a non-trading day is recorded as skipped.`,
      )

      // The work happens on a queue worker, so the outcome is not known yet;
      // refreshing shows it as soon as the worker records it.
      await load()

      if (historyTask?.id === task.id) {
        await loadRuns(task)
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to start the automation")
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (task: ScheduledTask) => {
    if (!accessToken) return

    const confirmed = window.confirm(
      task.is_system
        ? `Delete the system automation "${task.name}"? It can be restored with "php artisan db:seed --class=AutomationSeeder".`
        : `Delete "${task.name}" and its run history?`,
    )

    if (!confirmed) return

    setBusyId(task.id)
    setError(null)

    try {
      await deleteScheduledTask(accessToken, task.id)
      setTasks((current) => current.filter((entry) => entry.id !== task.id))
      if (historyTask?.id === task.id) setHistoryTask(null)
      setNotice(`${task.name} deleted.`)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to delete the automation")
    } finally {
      setBusyId(null)
    }
  }

  const onTokenChange = (next: StockbitTokenState) => {
    setStatus((current) => (current ? { ...current, stockbit_token: next } : current))
    void load()
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-primary">Scheduling</p>
          <h1 className="text-3xl font-semibold tracking-tight">Automation</h1>
          <p className="mt-2 max-w-3xl text-muted-foreground">
            Market data jobs stored in the database and dispatched every minute. All market
            schedules and trading-day checks are evaluated in Asia/Jakarta (WIB / UTC+7); the
            application itself continues to store timestamps in UTC.
          </p>
        </div>

        <div className="flex gap-2">
          <Button variant="outline" onClick={() => void load(true)} disabled={loading || saving}>
            <RefreshCw className={loading ? "animate-spin" : ""} /> Refresh
          </Button>
          <Button
            onClick={() => {
              setEditing(null)
              setCreating(true)
            }}
          >
            <Plus /> New automation
          </Button>
        </div>
      </div>

      <AutomationAlertBanner compact />

      {error ? (
        <Card className="border-destructive/40">
          <CardContent className="flex items-center gap-3 py-6 text-destructive">
            <TriangleAlert className="size-5" />
            {error}
          </CardContent>
        </Card>
      ) : null}

      {notice ? (
        <Card className="border-emerald-500/40">
          <CardContent className="py-4 text-sm text-emerald-700 dark:text-emerald-400">
            {notice}
          </CardContent>
        </Card>
      ) : null}

      {loading && !status ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((index) => (
            <Card key={index} className="h-36 animate-pulse bg-muted/40" />
          ))}
        </div>
      ) : null}

      {status ? <StatusHeader status={status} /> : null}

      {creating || editing ? (
        <TaskForm
          key={editing?.id ?? "new"}
          task={editing}
          commands={commands}
          saving={saving}
          onSubmit={save}
          onCancel={() => {
            setEditing(null)
            setCreating(false)
          }}
        />
      ) : null}

      <TaskTable
        tasks={tasks}
        busyId={busyId}
        onEdit={(task) => {
          setCreating(false)
          setEditing(task)
        }}
        onToggle={(task) => void toggle(task)}
        onRun={(task) => void run(task)}
        onDelete={(task) => void remove(task)}
        onHistory={(task) => void loadRuns(task)}
      />

      {historyTask ? (
        <RunHistory task={historyTask} runs={runs} loading={runsLoading} />
      ) : null}

      {status ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <StockbitTokenCard
            token={accessToken ?? ""}
            status={status.stockbit_token}
            onChange={onTokenChange}
          />

          <Card>
            <CardContent className="space-y-3 text-sm">
              <p className="font-medium">Diagnosing a missed run</p>
              <ul className="list-disc space-y-1.5 pl-5 text-muted-foreground">
                <li>
                  A <em>skipped</em> run means the scheduler fired and the market condition said
                  no. No run at all means it never fired — check that cron is invoking{" "}
                  <code className="text-xs">php artisan schedule:run</code> every minute.
                </li>
                <li>
                  <code className="text-xs">php artisan scheduler:status</code> shows every
                  database task, its next occurrence and its last outcome.
                </li>
                <li>
                  <code className="text-xs">php artisan schedule:list</code> shows the single
                  static entry that drives all of it.
                </li>
                <li>
                  <code className="text-xs">php artisan stockbit:token:status</code> reports the
                  token from the CLI without printing it.
                </li>
                <li>
                  Trading-day conditions read <code className="text-xs">trading_calendar</code>.
                  Rebuild it with{" "}
                  <code className="text-xs">php artisan trading-calendar:build</code> when the
                  status card above reports it incomplete.
                </li>
              </ul>
            </CardContent>
          </Card>
        </div>
      ) : null}
    </div>
  )
}
