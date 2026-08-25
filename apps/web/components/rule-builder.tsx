"use client"

import { Plus, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  isGroup,
  type RuleCondition,
  type RuleGroup,
  type RuleNode,
  type RuleSchema,
} from "@/lib/strategy-builder-client"

type Props = {
  value: RuleGroup
  schema: RuleSchema | null
  onChange: (next: RuleGroup) => void
}

/** Operators that take no operand, so the value input is hidden for them. */
const UNARY = new Set(["is_true", "is_false", "is_null", "not_null"])

function defaultConditionFor(schema: RuleSchema | null): RuleCondition {
  const field = schema?.fields[0]
  const operator = field ? schema?.operators_by_type[field.type]?.[0] : undefined

  return {
    field: field?.key ?? "",
    operator: operator ?? "gte",
    value: operator && UNARY.has(operator) ? null : 0,
  }
}

/**
 * Recursive editor for one rule node. Nodes are immutable here: every edit
 * rebuilds the branch above it via onChange, which keeps the tree a plain
 * serialisable value that can be posted straight to the API.
 */
function NodeEditor({
  node,
  schema,
  depth,
  onChange,
  onRemove,
}: {
  node: RuleNode
  schema: RuleSchema | null
  depth: number
  onChange: (next: RuleNode) => void
  onRemove?: () => void
}) {
  if (isGroup(node)) {
    return (
      <GroupEditor
        group={node}
        schema={schema}
        depth={depth}
        onChange={onChange}
        onRemove={onRemove}
      />
    )
  }

  return (
    <ConditionEditor
      condition={node}
      schema={schema}
      onChange={onChange}
      onRemove={onRemove}
    />
  )
}

function ConditionEditor({
  condition,
  schema,
  onChange,
  onRemove,
}: {
  condition: RuleCondition
  schema: RuleSchema | null
  onChange: (next: RuleNode) => void
  onRemove?: () => void
}) {
  const field = schema?.fields.find((f) => f.key === condition.field)
  const operators = field ? schema?.operators_by_type[field.type] ?? [] : []
  const isUnary = UNARY.has(condition.operator)
  const isBetween = condition.operator === "between"

  // Changing the field can invalidate the operator (a boolean field does not
  // accept "gte"), so reset to the first operator the new type allows.
  const handleFieldChange = (key: string) => {
    const next = schema?.fields.find((f) => f.key === key)
    const allowed = next ? schema?.operators_by_type[next.type] ?? [] : []
    const operator = allowed.includes(condition.operator) ? condition.operator : allowed[0] ?? "gte"

    onChange({
      field: key,
      operator,
      value: UNARY.has(operator) ? null : operator === "between" ? [0, 0] : 0,
    })
  }

  const handleOperatorChange = (operator: string) => {
    onChange({
      ...condition,
      operator,
      value: UNARY.has(operator) ? null : operator === "between" ? [0, 0] : (condition.value ?? 0),
    })
  }

  const groups = Array.from(new Set((schema?.fields ?? []).map((f) => f.group)))

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border bg-background p-3">
      <select
        aria-label="Field"
        className="h-9 min-w-[200px] flex-1 rounded-md border border-input bg-background px-2 text-sm"
        value={condition.field}
        onChange={(e) => handleFieldChange(e.target.value)}
      >
        {groups.map((group) => (
          <optgroup key={group} label={group}>
            {(schema?.fields ?? [])
              .filter((f) => f.group === group)
              .map((f) => (
                <option key={f.key} value={f.key}>
                  {f.label}
                </option>
              ))}
          </optgroup>
        ))}
      </select>

      <select
        aria-label="Operator"
        className="h-9 min-w-[150px] rounded-md border border-input bg-background px-2 text-sm"
        value={condition.operator}
        onChange={(e) => handleOperatorChange(e.target.value)}
      >
        {operators.map((op) => (
          <option key={op} value={op}>
            {schema?.operators[op] ?? op}
          </option>
        ))}
      </select>

      {!isUnary && !isBetween ? (
        <Input
          aria-label="Value"
          type="number"
          step="any"
          className="h-9 w-32"
          value={typeof condition.value === "number" ? condition.value : ""}
          onChange={(e) => onChange({ ...condition, value: Number(e.target.value) })}
        />
      ) : null}

      {isBetween ? (
        <div className="flex items-center gap-1">
          <Input
            aria-label="Lower bound"
            type="number"
            step="any"
            className="h-9 w-24"
            value={Array.isArray(condition.value) ? condition.value[0] : 0}
            onChange={(e) =>
              onChange({
                ...condition,
                value: [
                  Number(e.target.value),
                  Array.isArray(condition.value) ? condition.value[1] : 0,
                ],
              })
            }
          />
          <span className="text-xs text-muted-foreground">and</span>
          <Input
            aria-label="Upper bound"
            type="number"
            step="any"
            className="h-9 w-24"
            value={Array.isArray(condition.value) ? condition.value[1] : 0}
            onChange={(e) =>
              onChange({
                ...condition,
                value: [
                  Array.isArray(condition.value) ? condition.value[0] : 0,
                  Number(e.target.value),
                ],
              })
            }
          />
        </div>
      ) : null}

      {onRemove ? (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onRemove}
          aria-label="Remove condition"
        >
          <Trash2 className="h-4 w-4" />
        </Button>
      ) : null}
    </div>
  )
}

function GroupEditor({
  group,
  schema,
  depth,
  onChange,
  onRemove,
}: {
  group: RuleGroup
  schema: RuleSchema | null
  depth: number
  onChange: (next: RuleNode) => void
  onRemove?: () => void
}) {
  const maxDepth = schema?.limits.max_depth ?? 5
  const canNest = depth + 1 < maxDepth

  const replaceChild = (index: number, next: RuleNode) => {
    const rules = [...group.rules]
    rules[index] = next
    onChange({ ...group, rules })
  }

  const removeChild = (index: number) => {
    onChange({ ...group, rules: group.rules.filter((_, i) => i !== index) })
  }

  return (
    <div className="space-y-2 rounded-md border border-dashed p-3">
      <div className="flex items-center gap-2">
        <select
          aria-label="Match type"
          className="h-8 rounded-md border border-input bg-background px-2 text-xs font-medium"
          value={group.op}
          onChange={(e) => onChange({ ...group, op: e.target.value as "and" | "or" })}
        >
          <option value="and">Match ALL of</option>
          <option value="or">Match ANY of</option>
        </select>

        <span className="text-xs text-muted-foreground">
          {group.rules.length} {group.rules.length === 1 ? "item" : "items"}
        </span>

        <div className="ml-auto flex items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() =>
              onChange({ ...group, rules: [...group.rules, defaultConditionFor(schema)] })
            }
          >
            <Plus className="mr-1 h-3 w-3" /> Condition
          </Button>

          {canNest ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                onChange({
                  ...group,
                  rules: [...group.rules, { op: "or", rules: [defaultConditionFor(schema)] }],
                })
              }
            >
              <Plus className="mr-1 h-3 w-3" /> Group
            </Button>
          ) : null}

          {onRemove ? (
            <Button type="button" variant="ghost" size="sm" onClick={onRemove} aria-label="Remove group">
              <Trash2 className="h-4 w-4" />
            </Button>
          ) : null}
        </div>
      </div>

      {group.rules.length === 0 ? (
        <p className="px-1 py-2 text-xs text-muted-foreground">
          No conditions yet. Add at least one before saving.
        </p>
      ) : (
        <div className="space-y-2">
          {group.rules.map((child, index) => (
            <NodeEditor
              key={index}
              node={child}
              schema={schema}
              depth={depth + 1}
              onChange={(next) => replaceChild(index, next)}
              onRemove={() => removeChild(index)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

export function RuleBuilder({ value, schema, onChange }: Props) {
  return (
    <NodeEditor
      node={value}
      schema={schema}
      depth={0}
      onChange={(next) => onChange(next as RuleGroup)}
    />
  )
}

export function countConditions(node: RuleNode): number {
  if (!isGroup(node)) {
    return 1
  }
  return node.rules.reduce((total, child) => total + countConditions(child), 0)
}
