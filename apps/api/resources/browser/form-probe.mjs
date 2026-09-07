#!/usr/bin/env node
/**
 * Report the login form's actual controls, so the selectors can be set from
 * evidence instead of guesswork.
 *
 * SELECTOR_NOT_FOUND is the one failure whose answer is not on this server:
 * it depends on markup only the portal knows, and a portal's login page is
 * usually rendered by JavaScript, so reading its HTML with curl shows an
 * empty shell. This opens the real page in the real browser and lists what a
 * selector could match.
 *
 * It never submits anything, types anything, or takes credentials. It reads
 * attribute names -- never values, which on a returning user's machine can
 * hold a saved username.
 *
 * Exits 0 with a JSON report on stdout, or 1 with the reason on stderr.
 */

import { chromium } from 'playwright'

const loginUrl = process.env.BROWSER_AUTH_LOGIN_URL
const executablePath = process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined
const navigationTimeoutMs = Number(process.env.BROWSER_AUTH_NAV_TIMEOUT_MS || 30_000)

/** Words a username field names itself with, across the portals worth supporting. */
const USERNAME_HINTS = /user|email|e-mail|login|account|phone|mobile|msisdn|nik|akun|masuk/i

/** Words a submit control labels itself with, English and Indonesian. */
const SUBMIT_HINTS = /log\s?in|sign\s?in|masuk|lanjut|continue|next|submit/i

/**
 * Prefer a selector that survives a redeploy.
 *
 * Framework-generated class names and ids change every build, so name and
 * stable test attributes come first; a positional or type-only selector is
 * the last resort and is flagged as such by being the least specific.
 */
function selectorFor(field) {
  if (field.testId) return `[data-testid="${field.testId}"]`
  if (field.name) return `${field.tag}[name="${field.name}"]`
  if (field.id) return `#${field.id}`
  if (field.autocomplete) return `${field.tag}[autocomplete="${field.autocomplete}"]`
  if (field.type) return `${field.tag}[type="${field.type}"]`

  return field.tag
}

function scoreUsername(field) {
  if (field.type === 'password' || field.type === 'hidden') return -1

  let score = 0

  if (['text', 'email', 'tel', ''].includes(field.type ?? '')) score += 1
  if (USERNAME_HINTS.test(field.name ?? '')) score += 4
  if (USERNAME_HINTS.test(field.autocomplete ?? '')) score += 4
  if (USERNAME_HINTS.test(field.id ?? '')) score += 3
  if (USERNAME_HINTS.test(field.placeholder ?? '')) score += 2
  if (USERNAME_HINTS.test(field.ariaLabel ?? '')) score += 2
  if (field.visible) score += 1

  return score
}

function scoreSubmit(control) {
  let score = 0

  if (control.type === 'submit') score += 3
  if (SUBMIT_HINTS.test(control.text ?? '')) score += 4
  if (SUBMIT_HINTS.test(control.name ?? '')) score += 2
  if (SUBMIT_HINTS.test(control.testId ?? '')) score += 2
  if (control.visible) score += 1
  if (control.disabled) score -= 1

  return score
}

function best(candidates, score) {
  let winner = null
  let winningScore = 0

  for (const candidate of candidates) {
    const value = score(candidate)

    if (value > winningScore) {
      winner = candidate
      winningScore = value
    }
  }

  return winner
}

let browser

try {
  if (typeof loginUrl !== 'string' || loginUrl === '') {
    throw new Error('BROWSER_AUTH_LOGIN_URL is not set.')
  }

  browser = await chromium.launch({
    headless: true,
    ...(executablePath ? { executablePath } : {}),
  })

  const page = await browser.newPage()

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: navigationTimeoutMs })

  // A login page rendered by JavaScript has no inputs at DOMContentLoaded.
  // Waiting for one mirrors what page.fill() does during a real run, so a
  // field this reports is a field the extractor would also find.
  await page
    .waitForSelector('input', { state: 'attached', timeout: navigationTimeoutMs })
    .catch(() => {})

  // Read attribute *names*, never values: a saved username sits in .value.
  const { fields, controls } = await page.evaluate(() => {
    const visible = (element) => {
      const rect = element.getBoundingClientRect()

      return rect.width > 0 && rect.height > 0
    }

    const attributes = (element) => ({
      tag: element.tagName.toLowerCase(),
      type: (element.getAttribute('type') || '').toLowerCase(),
      name: element.getAttribute('name') || '',
      id: element.id || '',
      placeholder: element.getAttribute('placeholder') || '',
      ariaLabel: element.getAttribute('aria-label') || '',
      autocomplete: element.getAttribute('autocomplete') || '',
      testId: element.getAttribute('data-testid') || element.getAttribute('data-test-id') || '',
      visible: visible(element),
    })

    return {
      fields: [...document.querySelectorAll('input, textarea')].map(attributes),
      controls: [...document.querySelectorAll('button, input[type="submit"], [role="button"]')].map(
        (element) => ({
          ...attributes(element),
          text: (element.textContent || element.getAttribute('value') || '').trim().slice(0, 60),
          disabled: element.hasAttribute('disabled'),
        }),
      ),
    }
  })

  const password = fields.find((field) => field.type === 'password' && field.visible)
    ?? fields.find((field) => field.type === 'password')
  const username = best(fields, scoreUsername)
  const submit = best(controls, scoreSubmit)

  process.stdout.write(
    `${JSON.stringify(
      {
        url: page.url(),
        title: await page.title(),
        fields,
        controls,
        suggestion: {
          username: username ? selectorFor(username) : null,
          password: password ? selectorFor(password) : null,
          submit: submit ? selectorFor(submit) : null,
        },
      },
      null,
      2,
    )}\n`,
  )
  process.exitCode = 0
} catch (error) {
  process.stderr.write(`${error?.message ?? error}\n`)
  process.exitCode = 1
} finally {
  await browser?.close().catch(() => {})
}
