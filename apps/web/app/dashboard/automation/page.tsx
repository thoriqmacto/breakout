import { AutomationClient } from "./automation-client"

/**
 * A server shell whose only job is to keep this route out of the prerender.
 *
 * Everything below is fetched client-side behind authentication, so a
 * prerendered shell is replaced on first paint and buys nothing. It also cost
 * something: this route sits close enough to a React Server Components
 * bundler limit that adding one more client component *anywhere* in the app
 * tipped its prerender into "Could not find the module ... in the React Client
 * Manifest" and failed the whole build. That reproduced from the same card
 * mounted on two unrelated pages, so it is this route's fragility rather than
 * any one component's fault.
 *
 * Route segment config is only read from a server component, which is why the
 * page is split rather than simply annotated -- `export const dynamic` in a
 * "use client" page is silently ignored.
 */
export const dynamic = "force-dynamic"

export default function AutomationPage() {
  return <AutomationClient />
}
