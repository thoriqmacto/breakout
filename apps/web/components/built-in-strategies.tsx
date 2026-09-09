"use client"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import type { BuiltInStrategy } from "@/lib/strategy-builder-client"

/**
 * The strategies that ship as code.
 *
 * Shown beside the editable ones because the question is "what strategies do
 * we have", and answering it from two places is what let the overview print a
 * hardcoded count for as long as it did.
 *
 * A read-only catalogue rather than cards that look editable: these are
 * classes with constructor parameters and a signal() method, not the rules the
 * runner executes, so an edit control here would be a promise the code does
 * not keep. The defaults are shown because a reader wants to know what a run
 * actually uses.
 */
export function BuiltInStrategies({ strategies }: { strategies: BuiltInStrategy[] }) {
  if (strategies.length === 0) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Built-in strategies ({strategies.length})</CardTitle>
        <CardDescription>
          Defined in code and run with{" "}
          <code className="rounded bg-muted px-1 py-0.5 text-xs">
            php artisan asset:backtest --strategy=…
          </code>
          . The values below are defaults, not settings — changing one means changing the class.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        {strategies.map((strategy) => (
          <div key={strategy.key} className="rounded-md border p-3">
            <div className="flex flex-wrap items-baseline gap-2">
              <span className="font-medium">{strategy.name}</span>
              <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{strategy.key}</code>

              {strategy.emits_daily_signal ? null : (
                <span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
                  no daily signal
                </span>
              )}
              {strategy.supports_trailing_stop ? (
                <span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
                  trailing stop
                </span>
              ) : null}
            </div>

            <p className="mt-1 text-sm text-muted-foreground">{strategy.summary}</p>

            {strategy.parameters.length > 0 ? (
              <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                {strategy.parameters.map((parameter) => (
                  <div key={parameter.name} className="flex gap-1">
                    <dt className="text-muted-foreground">{parameter.name}</dt>
                    <dd className="font-mono">
                      {parameter.default}
                      {parameter.unit ? ` ${parameter.unit}` : ""}
                    </dd>
                  </div>
                ))}
              </dl>
            ) : null}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
