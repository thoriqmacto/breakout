"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { Loader2, Save } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { countConditions, RuleBuilder } from "@/components/rule-builder"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  createStrategy,
  emptyRuleTree,
  fetchRuleSchema,
  updateStrategy,
  type RuleGroup,
  type RuleSchema,
  type StrategyRecord,
} from "@/lib/strategy-builder-client"

type Props = {
  /** Omitted when creating. */
  strategy?: StrategyRecord
}

export function StrategyForm({ strategy }: Props) {
  const router = useRouter()
  const { accessToken } = useAuth()

  const [schema, setSchema] = useState<RuleSchema | null>(null)
  const [name, setName] = useState(strategy?.name ?? "")
  const [description, setDescription] = useState(strategy?.description ?? "")
  const [visibility, setVisibility] = useState<"private" | "public">(
    strategy?.visibility ?? "private",
  )
  const [rules, setRules] = useState<RuleGroup>(
    (strategy?.rules as RuleGroup | undefined) ?? emptyRuleTree(),
  )
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!accessToken) return

    fetchRuleSchema(accessToken)
      .then(setSchema)
      .catch((cause) =>
        setError(cause instanceof Error ? cause.message : "Unable to load the rule schema."),
      )
  }, [accessToken])

  const conditionCount = countConditions(rules)
  const maxConditions = schema?.limits.max_conditions ?? 50
  const tooMany = conditionCount > maxConditions

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (!accessToken) return

    setSaving(true)
    setError(null)

    try {
      const payload = {
        name: name.trim(),
        description: description.trim() ? description.trim() : null,
        visibility,
        rules,
      }

      const saved = strategy
        ? await updateStrategy(accessToken, strategy.id, payload)
        : await createStrategy(accessToken, payload)

      router.push(`/dashboard/strategy/${saved.id}`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to save the strategy.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Details</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <label htmlFor="name" className="text-sm font-medium">
              Name
            </label>
            <Input
              id="name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Volume expansion breakout"
              required
              maxLength={255}
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="description" className="text-sm font-medium">
              Description
            </label>
            <Input
              id="description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="What this strategy looks for"
              maxLength={1000}
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="visibility" className="text-sm font-medium">
              Visibility
            </label>
            <select
              id="visibility"
              className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={visibility}
              onChange={(e) => setVisibility(e.target.value as "private" | "public")}
            >
              <option value="private">Private — only you can see, run and edit it</option>
              <option value="public">Public — others can see, run and copy it; only you edit</option>
            </select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Rules</CardTitle>
          <CardDescription>
            A symbol matches when the top-level group is satisfied for the scan date.
            {" "}
            {conditionCount} of {maxConditions} conditions used.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {schema === null ? (
            <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Loading fields…
            </div>
          ) : (
            <RuleBuilder value={rules} schema={schema} onChange={setRules} />
          )}
        </CardContent>
      </Card>

      {tooMany ? (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {conditionCount} conditions exceeds the limit of {maxConditions}. Remove some before saving.
        </div>
      ) : null}

      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      <div className="flex items-center gap-2">
        <Button type="submit" disabled={saving || conditionCount === 0 || tooMany}>
          {saving ? (
            <Loader2 className="mr-1 h-4 w-4 animate-spin" />
          ) : (
            <Save className="mr-1 h-4 w-4" />
          )}
          {strategy ? "Save changes" : "Create strategy"}
        </Button>
        <Button type="button" variant="outline" onClick={() => router.back()}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
