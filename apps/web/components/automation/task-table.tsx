"use client"

import { History, Pencil, Play, Power, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge, ConditionBadge, EnabledBadge, RunStatusBadge } from "@/components/automation/badges"
import { formatJakarta, type ScheduledTask } from "@/lib/automation-client"

export function TaskTable({
  tasks,
  busyId,
  onEdit,
  onToggle,
  onRun,
  onDelete,
  onHistory,
}: {
  tasks: ScheduledTask[]
  busyId: number | null
  onEdit: (task: ScheduledTask) => void
  onToggle: (task: ScheduledTask) => void
  onRun: (task: ScheduledTask) => void
  onDelete: (task: ScheduledTask) => void
  onHistory: (task: ScheduledTask) => void
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Scheduled tasks</CardTitle>
        <CardDescription>
          Stored in the database and dispatched every minute by{" "}
          <code className="text-xs">scheduler:dispatch</code>. Changes here take effect on the
          next tick — no deploy required.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[68rem] border-collapse text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                <th className="py-2 pr-4 font-medium">Name</th>
                <th className="py-2 pr-4 font-medium">Command</th>
                <th className="py-2 pr-4 font-medium">Schedule</th>
                <th className="py-2 pr-4 font-medium">Condition</th>
                <th className="py-2 pr-4 font-medium">Enabled</th>
                <th className="py-2 pr-4 font-medium">Last run</th>
                <th className="py-2 pr-4 font-medium">Last status</th>
                <th className="py-2 pr-4 font-medium">Next run</th>
                <th className="py-2 pr-4 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {tasks.length === 0 ? (
                <tr>
                  <td colSpan={9} className="py-6 text-center text-muted-foreground">
                    No automations yet.
                  </td>
                </tr>
              ) : null}

              {tasks.map((task) => {
                const busy = busyId === task.id

                return (
                  <tr key={task.id} className="border-b align-top last:border-0">
                    <td className="py-3 pr-4">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{task.name}</span>
                        {task.is_system ? <Badge>System</Badge> : null}
                        {task.stockbit_bulk ? (
                          <Badge
                            tone="bg-sky-500/10 text-sky-700 dark:text-sky-400"
                            title="Takes the shared Stockbit lock, so it never runs alongside another bulk job."
                          >
                            Stockbit bulk
                          </Badge>
                        ) : null}
                      </div>
                      {task.description ? (
                        <p className="mt-1 max-w-md text-xs text-muted-foreground">
                          {task.description}
                        </p>
                      ) : null}
                    </td>

                    <td className="py-3 pr-4">
                      <code className="text-xs">{task.command}</code>
                      <p className="mt-1 max-w-sm truncate text-xs text-muted-foreground" title={task.command_preview}>
                        {task.command_preview}
                      </p>
                      {!task.command_allowed ? (
                        <p className="mt-1 text-xs text-destructive">
                          No longer on the allowlist; this task will not run.
                        </p>
                      ) : null}
                    </td>

                    <td className="py-3 pr-4">
                      <code className="text-xs">{task.cron_expression}</code>
                      <p className="mt-1 text-xs text-muted-foreground">{task.timezone}</p>
                    </td>

                    <td className="py-3 pr-4">
                      <ConditionBadge condition={task.condition} />
                    </td>

                    <td className="py-3 pr-4">
                      <EnabledBadge enabled={task.enabled} />
                    </td>

                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                      {formatJakarta(task.last_run_at)}
                    </td>

                    <td className="py-3 pr-4">
                      {task.latest_run ? (
                        <RunStatusBadge
                          status={task.latest_run.status}
                          skipReason={task.latest_run.skip_reason}
                          partial={task.latest_run.partial}
                        />
                      ) : (
                        <span className="text-xs text-muted-foreground">Never run</span>
                      )}
                    </td>

                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                      {task.next_run_local ?? "—"}
                    </td>

                    <td className="py-3 pr-4">
                      <div className="flex flex-wrap gap-1">
                        <Button size="sm" variant="outline" onClick={() => onRun(task)} disabled={busy}>
                          <Play className="size-3.5" aria-hidden /> Run
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => onHistory(task)}>
                          <History className="size-3.5" aria-hidden /> History
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => onEdit(task)}>
                          <Pencil className="size-3.5" aria-hidden /> Edit
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => onToggle(task)}
                          disabled={busy}
                        >
                          <Power className="size-3.5" aria-hidden />
                          {task.enabled ? "Disable" : "Enable"}
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-destructive"
                          onClick={() => onDelete(task)}
                          disabled={busy}
                        >
                          <Trash2 className="size-3.5" aria-hidden /> Delete
                        </Button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}
