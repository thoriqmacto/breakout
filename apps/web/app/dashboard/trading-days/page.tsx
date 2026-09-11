import { redirect } from "next/navigation"

/**
 * Kept as a redirect rather than deleted.
 *
 * The Trading Days page folded into Assets, where per-asset history is now a
 * set of coverage columns and the market-wide calendar is the second tab. The
 * route stays because bookmarks and muscle memory outlive a navigation entry,
 * and a 404 would read as the feature having been removed.
 */
export default function TradingDaysPage() {
  redirect("/dashboard/assets?view=calendar")
}
