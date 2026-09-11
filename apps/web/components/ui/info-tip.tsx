"use client"

import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from "react"
import { createPortal } from "react-dom"

import { glossary } from "@/lib/glossary"

/**
 * A column heading that can explain itself.
 *
 * Hover is not enough on its own: a `title` attribute is invisible to a
 * keyboard, unreadable on a phone, and cannot wrap a sentence worth reading.
 * This opens on hover *and* on focus, and stays open while the pointer is
 * inside the heading, so the text can be read rather than raced.
 *
 * The bubble is rendered into `document.body` and positioned from the
 * trigger's bounding box, which is not decoration. Absolutely positioned, it
 * lost half its text: every table here sits in an `overflow-x-auto` scroller
 * that clips it at the edge, and the sticky first column paints a background
 * over whatever a later cell puts underneath it. Neither is fixable with
 * z-index, because a sticky cell creates its own stacking context. Escaping to
 * the body escapes both, at the cost of having to measure -- so it also flips
 * above the heading and clamps to the viewport rather than running off it.
 *
 * Still no popover library: one measured box is cheaper than a dependency.
 */

/** Preferred width; narrowed on a screen too small to hold it. */
const WIDTH = 288

/** Kept clear of every viewport edge. */
const MARGIN = 8

/** Between the trigger and the bubble. */
const OFFSET = 6

type Box = { top: number; left: number; width: number }

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
  const [mounted, setMounted] = useState(false)
  const [box, setBox] = useState<Box | null>(null)

  const triggerRef = useRef<HTMLButtonElement | null>(null)
  const bubbleRef = useRef<HTMLSpanElement | null>(null)
  const id = useId()

  const entry = term ? glossary(term) : null
  const body = text ?? entry?.definition ?? null

  // A portal needs a document, which the server render does not have.
  useEffect(() => setMounted(true), [])

  const place = useCallback(() => {
    const trigger = triggerRef.current

    if (!trigger) return

    const rect = trigger.getBoundingClientRect()
    const width = Math.min(WIDTH, window.innerWidth - MARGIN * 2)
    const height = bubbleRef.current?.offsetHeight ?? 0

    let left = align === "right" ? rect.right - width : rect.left
    left = Math.min(Math.max(MARGIN, left), Math.max(MARGIN, window.innerWidth - width - MARGIN))

    // Below unless that would run past the fold, in which case above. A tall
    // bubble near the bottom of a long table is the ordinary case, not an edge
    // one.
    const below = rect.bottom + OFFSET
    const fitsBelow = height === 0 || below + height + MARGIN <= window.innerHeight
    const top = fitsBelow ? below : Math.max(MARGIN, rect.top - height - OFFSET)

    setBox((current) =>
      current !== null && current.top === top && current.left === left && current.width === width
        ? current
        : { top, left, width },
    )
  }, [align])

  useLayoutEffect(() => {
    if (!open) {
      setBox(null)

      return
    }

    place()

    const reposition = () => place()

    // The first pass measures a bubble that has not been given its final width
    // yet, so its height can still change once placed. Watching the element
    // covers that and a late font load in one mechanism.
    const observer = new ResizeObserver(reposition)

    if (bubbleRef.current) observer.observe(bubbleRef.current)

    // Capture, so scrolling the table itself moves the bubble with the heading
    // rather than leaving it behind.
    window.addEventListener("scroll", reposition, true)
    window.addEventListener("resize", reposition)

    return () => {
      observer.disconnect()
      window.removeEventListener("scroll", reposition, true)
      window.removeEventListener("resize", reposition)
    }
  }, [open, place])

  useEffect(() => {
    if (!open) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false)
    }

    window.addEventListener("keydown", onKeyDown)

    return () => window.removeEventListener("keydown", onKeyDown)
  }, [open])

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
        ref={triggerRef}
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

      {mounted && open
        ? createPortal(
            <span
              ref={bubbleRef}
              id={id}
              role="tooltip"
              style={{
                top: box?.top ?? 0,
                left: box?.left ?? 0,
                width: box?.width ?? WIDTH,
                // Rendered before it is measured, so it is placed rather than
                // seen jumping into place.
                visibility: box === null ? "hidden" : "visible",
              }}
              className="pointer-events-none fixed z-[100] rounded-md border bg-popover p-2 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-popover-foreground shadow-md"
            >
              {entry ? <span className="mb-0.5 block font-semibold">{entry.term}</span> : null}
              {body}
              {entry?.range ? (
                <span className="mt-1.5 block border-t border-border/60 pt-1.5 text-muted-foreground">
                  <span className="font-medium text-foreground">Scale: </span>
                  {entry.range}
                </span>
              ) : null}
            </span>,
            document.body,
          )
        : null}
    </span>
  )
}
