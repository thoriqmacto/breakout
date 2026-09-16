#!/usr/bin/env node
/**
 * Prove Chromium can actually start, as whoever is running this.
 *
 * `browser:check` uses it because every other check it makes is an inference
 * -- a file exists, a binary answers --version -- and only launching a browser
 * settles whether a headless login could run. It opens nothing, visits
 * nothing, and takes no credentials.
 *
 * Exits 0 with a one-line summary, or 1 with the reason on stderr.
 */

import { chromium } from 'playwright'

const executablePath = process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined

// Whether to prove a *windowed* launch rather than a headless one. They are
// different questions with different answers: Playwright's headless shell
// cannot open a window at all, and a windowed Chromium wants system libraries
// a headless one does not. A box that has collected tokens for months can fail
// the second while passing the first -- which is how the first headful run
// spent a login attempt discovering that the browser would not start.
const headless = process.env.BROWSER_AUTH_HEADLESS !== 'false'

// Playwright ships two binaries, and one of them lies about this.
//
// chrome-headless-shell does not refuse `headless: false` -- it accepts the
// request, ignores it, and launches headless anyway, reporting success. So a
// box pointed at the shell passes a headful check while being incapable of
// running headful, and an experiment whose entire purpose is to compare the
// two modes silently compares headless with headless.
//
// Verified rather than assumed: asked for a window against the shell, it
// launched and reported a version, with no display present at all.
const NAMES_HEADLESS_SHELL = /headless[_-]?shell/i.test(executablePath ?? '')

if (!headless && NAMES_HEADLESS_SHELL) {
  process.stderr.write(
    `${executablePath} is Playwright's headless shell. It accepts a request for a window, `
      + 'ignores it and launches headless anyway, so a headful run against it proves nothing. '
      + 'Install the full Chromium build with "npx playwright install --with-deps chromium" and '
      + 'point BROWSER_AUTH_CHROMIUM_PATH at it, or unset BROWSER_AUTH_CHROMIUM_PATH and let '
      + 'Playwright choose the right binary for each mode.\n',
  )
  process.exitCode = 1
} else {

let browser

try {
  browser = await chromium.launch({
    headless,
    ...(executablePath ? { executablePath } : {}),
  })

  const version = browser.version()

  process.stdout.write(
    `Chromium ${version}${headless ? '' : ' with a display'}${executablePath ? ` (${executablePath})` : ''}\n`,
  )
  process.exitCode = 0
} catch (error) {
  const said = String(error?.message ?? error)

  // The same three causes the extractor separates, named here too -- this is
  // the command an operator reaches for first, and sending them to reinstall a
  // working browser wastes the round the check exists to save.
  let hint = ''

  if (!headless && /display|x server|xvfb|DISPLAY/i.test(said)) {
    hint = ' -- a headful run needs a display: "sudo apt-get install -y xvfb", then run under "xvfb-run -a".'
  } else if (/lib[a-z0-9_.+-]*\.so|shared librar/i.test(said)) {
    hint = ' -- Chromium is present but a system library it needs is not. '
      + 'Install them with "npx playwright install-deps chromium".'
  }

  process.stderr.write(`${said}${hint}\n`)
  process.exitCode = 1
} finally {
  await browser?.close().catch(() => {})
}

}
