"use client"

import { useId, useState } from "react"

import { glossary } from "@/lib/glossary"

/**
 * A column heading that can explain itself.
 *
 * Hover is not enough on its own: a `title` attribute is invisible to a
 * keyboard, unreadable on a phone, and cannot wrap a sentence worth reading.
 * This opens on hover *and* on focus, and stays open while the pointer is
 * inside it, so the text can be read rather than raced.
 *
 * No popover library. One term needs one absolutely positioned div, and a
 * dependency for that would cost more than it explains.
 */
export function InfoTip({
  /** A key in the glossary, or nothing when `text` is supplied directly. */
  term,
  text,
  children,
  align = "left",
}: {
  term?: string
  text?: string
  children?: React.ReactNode
  align?: "left" | "right"
}) {
  const [open, setOpen] = useState(false)
  const id = useId()

  const entry = term ? glossary(term) : null
  const body = text ?? entry?.definition ?? null

  // An unregistered term renders as an ordinary heading rather than an empty
  // bubble: a tooltip that opens onto nothing is worse than no tooltip.
  if (!body) return <>{children ?? null}</>

  return (
    <span
      className="relative inline-flex items-center gap-1"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      {children === undefined ? null : <span>{children}</span>}

      <button
        type="button"
        aria-label={`What ${entry?.term ?? "this column"} means`}
        aria-describedby={open ? id : undefined}
        className="inline-flex size-3.5 shrink-0 cursor-help items-center justify-center rounded-full border border-current text-[9px] font-semibold leading-none opacity-50 transition-opacity hover:opacity-100 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={(event) => {
          // Tap to open on a touch screen, where there is no hover at all.
          event.preventDefault()
          setOpen((current) => !current)
        }}
      >
        i
      </button>

      {open ? (
        <span
          id={id}
          role="tooltip"
          className={`absolute top-full z-50 mt-1 w-64 rounded-md border bg-popover p-2 text-xs font-normal normal-case leading-relaxed text-popover-foreground shadow-md ${
            align === "right" ? "right-0" : "left-0"
          }`}
        >
          {entry ? <span className="mb-0.5 block font-semibold">{entry.term}</span> : null}
          {body}
        </span>
      ) : null}
    </span>
  )
}
