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

let browser

try {
  browser = await chromium.launch({
    headless: true,
    ...(executablePath ? { executablePath } : {}),
  })

  const version = browser.version()

  process.stdout.write(
    `Chromium ${version}${executablePath ? ` (${executablePath})` : ''}\n`,
  )
  process.exitCode = 0
} catch (error) {
  process.stderr.write(`${error?.message ?? error}\n`)
  process.exitCode = 1
} finally {
  await browser?.close().catch(() => {})
}
