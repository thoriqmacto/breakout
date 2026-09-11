"use client"

import type { TaskRun } from "@/lib/automation-client"

/**
 * What to do about a failed renewal, next to the failure.
 *
 * The run detail already says what went wrong. It did not say what to do, so
 * every failure meant reconstructing the procedure from first principles --
 * and the procedure has an order that matters: test the saved session before
 * forcing a login, because forcing one deliberately clears the profile's
 * cookies and storage to make the form reappear. Get that backwards on a
 * healthy profile and you sign it out to no purpose.
 *
 * The reason codes are the renewer's own (`StockbitTokenRenewer`) and the
 * extractor's (`BrowserTokenExtractor`), which differ in case because one
 * crosses the Node boundary. Compared lowercased, so neither has to change.
 *
 * Commands are deliberately written without absolute paths: the profile's
 * owner and directory vary by deployment, and the message above this block
 * already names both. Inventing them here is how a runbook starts lying.
 */

type Remedy = {
  cause: string
  steps: string[]
  note?: string
}

const REMEDIES: Record<string, Remedy> = {
  invalid_credentials: {
    cause:
      "The portal answered 401/403: it refused that username and password. No device-approval " +
      "notification is sent for a login that never passed this step, so a missing notification " +
      "is a symptom rather than a separate problem.",
    steps: [
      "Sign in to the portal in an ordinary browser with exactly the same username and password.",
      "If that fails too, the credentials are the problem — the portal usually wants the account's EMAIL ADDRESS, not the display name.",
      "Retry as the profile's owner: sudo -u <owner> php artisan browser:token --username='<portal email>' --screenshot=/tmp/sb",
      "If the browser login works but the headless one still gets 401, the portal is refusing the automated client: paste a bearer instead with `php artisan stockbit:token:set` (reads stdin) or the Scrapers page.",
    ],
  },
  profile_signed_out: {
    cause:
      "The saved browser profile exists but its session has ended, and no stored credentials " +
      "were available to sign in again.",
    steps: [
      "Sign in once as the profile's owner: sudo -u <owner> php artisan browser:token --username='<portal email>'",
      "Approve the device through the portal's own notification when it asks.",
      "Prove it persisted, with no password at all: sudo -u <owner> php artisan browser:token --session --dry-run",
    ],
    note: "The second command is the one that matters. It supplies no credentials, so a token means every later unattended run can do the same.",
  },
  profile_unusable: {
    cause:
      "The browser profile directory exists but the process running the renewal cannot use it. " +
      "A Chromium profile belongs to one Unix user: its cookies and session state are written " +
      "owner-only, so a shared group does not make it readable.",
    steps: [
      "Compare the two names in the message above: the profile's owner, and the user the run executed as.",
      "A scheduled run executes inside the cron process, so it is the crontab's user; a Run now is queued, so it is the queue worker's.",
      "Make both of those the profile's owner rather than loosening the directory's permissions, which cannot work.",
    ],
  },
  no_credentials: {
    cause:
      "There is no usable browser profile and no stored credentials, so there is nothing to " +
      "sign in with.",
    steps: [
      "Point BROWSER_AUTH_PROFILE_DIR at a directory owned by the user that runs the renewal, then sign in once as that user with `php artisan browser:token`.",
      "Or save credentials for unattended use with `php artisan stockbit:credentials`.",
      "Either is enough on its own. A profile is the safer of the two: what a stolen disk gives up is a session that can be revoked, not a password that cannot.",
    ],
  },
  not_configured: {
    cause: "Headless login is switched off, so nothing was attempted.",
    steps: [
      "Set BROWSER_AUTH_ENABLED=true and BROWSER_AUTH_LOGIN_URL, then re-cache the config.",
      "Check the whole environment as the user that will run it: sudo -u <owner> php artisan browser:check",
      "Until then, renew by hand with `php artisan stockbit:token:set` or the Scrapers page.",
    ],
  },
  rejected_by_api: {
    cause:
      "A token was captured and the API refused it. The bearer is read off a request header, " +
      "which happens before any response exists to say the request was refused — so a profile " +
      "whose session has ended hands back the same dead token every time.",
    steps: [
      "Force a real login so the dead session is cleared: sudo -u <owner> php artisan browser:token --username='<portal email>'",
      "That clears the profile's cookies and storage on purpose, which is what makes the login form come back.",
      "If the fresh token is refused as well, the account itself is blocked or the portal has changed — check by signing in manually.",
    ],
  },
  token_not_found: {
    cause:
      "The login completed — the form was left behind — but no bearer crossed the wire in a " +
      "shape this recognises. That is usually the token key names, not the credentials.",
    steps: [
      "Re-run with a screenshot to see where the browser landed: sudo -u <owner> php artisan browser:token --screenshot=/tmp/sb",
      "Compare the storage and cookie names in the evidence block against BROWSER_AUTH_TOKEN_KEYS.",
      "Meanwhile, paste a bearer so collection is not held up: `php artisan stockbit:token:set`",
    ],
  },
  browser_launch_failed: {
    cause: "Chromium could not start at all, so no login was attempted.",
    steps: [
      "Run the environment check as the same user: sudo -u <owner> php artisan browser:check",
      "It reports Node, the Playwright install, the Chromium build and whether the profile is writable — the failing row names the fix.",
      "A missing writable HOME is a common cause under cron: set HOME in the crontab.",
    ],
  },
  timeout: {
    cause: "The portal did not respond in time. This says nothing about the credentials.",
    steps: [
      "Re-run once — a slow minute on the network is not a broken login.",
      "If it repeats, check the portal is reachable from the server at all.",
      "Paste a bearer if collection is due before this is resolved.",
    ],
  },
  extraction_failed: {
    cause: "The headless login failed for a reason without a more specific code.",
    steps: [
      "Read the message above first — it carries the extractor's own text.",
      "Re-run with a screenshot: sudo -u <owner> php artisan browser:token --screenshot=/tmp/sb",
      "Paste a bearer with `php artisan stockbit:token:set` if collection is due.",
    ],
  },
}

export function RunRemedy({ run }: { run: TaskRun }) {
  const reason = readReason(run)
  const remedy = reason === null ? undefined : REMEDIES[reason]

  if (remedy === undefined) return null

  return (
    <div className="mt-3 rounded-md border border-amber-500/40 bg-amber-500/5 p-3">
      <p className="text-xs font-medium uppercase text-muted-foreground">What to do</p>

      <p className="mt-1 text-sm">{remedy.cause}</p>

      <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm">
        {remedy.steps.map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ol>

      {remedy.note ? (
        <p className="mt-2 text-xs text-muted-foreground">{remedy.note}</p>
      ) : null}

      <p className="mt-2 text-xs text-muted-foreground">
        Run these as the user that owns the browser profile — the message above names it.
      </p>
    </div>
  )
}

/**
 * The renewer records `reason` in run metadata; nothing else does, so its
 * presence is what identifies a renewal failure without matching on the
 * command string.
 */
function readReason(run: TaskRun): string | null {
  const reason = run.metadata?.reason

  return typeof reason === "string" && reason !== "" ? reason.toLowerCase() : null
}
