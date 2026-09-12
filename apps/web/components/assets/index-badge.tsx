import Link from "next/link"

/**
 * A chip saying a symbol is in a published index.
 *
 * Small and quiet on purpose: it sits beside a ticker in a dense table, where
 * anything with a background colour competes with the numbers. It links to the
 * index panel, because the question a badge provokes -- since when? what else
 * is in it? -- is answered there rather than in a tooltip.
 */
export function IndexBadge({ codes }: { codes: string[] }) {
  if (codes.length === 0) return null

  return (
    <span className="inline-flex flex-wrap gap-1 align-middle">
      {codes.map((code) => (
        <Link
          key={code}
          href={`/dashboard/assets?view=index&index=${encodeURIComponent(code)}`}
          title={`${code} constituent — open the index panel`}
          className="rounded border border-emerald-600/40 bg-emerald-600/10 px-1 py-px text-[10px] font-semibold uppercase leading-none tracking-wide text-emerald-700 transition-colors hover:bg-emerald-600/20 dark:text-emerald-400"
        >
          {code}
        </Link>
      ))}
    </span>
  )
}
