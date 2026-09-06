/**
 * Headless-browser bearer token extraction.
 *
 * A portal that issues a JWT to its own front end will hand that JWT over
 * twice: once in the body of the login response, and again in the
 * `Authorization` header of the first API call the app makes afterwards.
 * Watching both is what makes this reliable rather than lucky -- portals that
 * return the token in a non-obvious shape (nested, renamed, set as a cookie
 * and echoed later) are caught by the header even when the body parse misses,
 * and portals that redirect straight to a static page are caught by the body
 * even when no API call follows.
 *
 * Nothing here is specific to any one site. The URL, the selectors and the
 * token key names are all parameters, so the caller decides what it is
 * driving and this file stays honest about knowing nothing.
 *
 * The password is never logged, never written to disk, and never included in
 * an error message or a returned object. `redact()` is applied to anything
 * that leaves this module.
 */

import { chromium } from 'playwright'

/** Failure kinds a caller can branch on, rather than matching message text. */
export const ExtractionError = {
  BROWSER_LAUNCH_FAILED: 'BROWSER_LAUNCH_FAILED',
  NAVIGATION_FAILED: 'NAVIGATION_FAILED',
  SELECTOR_NOT_FOUND: 'SELECTOR_NOT_FOUND',
  INVALID_CREDENTIALS: 'INVALID_CREDENTIALS',
  TIMEOUT: 'TIMEOUT',
  TOKEN_NOT_FOUND: 'TOKEN_NOT_FOUND',
}

export class TokenExtractionError extends Error {
  constructor(code, message, { cause } = {}) {
    super(message)
    this.name = 'TokenExtractionError'
    this.code = code
    if (cause) this.cause = cause
  }
}

/** Paths that plausibly carry an authentication response. */
const DEFAULT_URL_HINTS = ['/api/', '/auth', '/login', '/token', '/session', '/oauth']

/** Keys whose value is plausibly the token, in descending specificity. */
const DEFAULT_TOKEN_KEYS = ['access_token', 'accessToken', 'id_token', 'idToken', 'token', 'jwt', 'bearer']

/** How deep into a response body to look for one of those keys. */
const MAX_BODY_DEPTH = 6

/** Ceiling on a response body we will parse, so a large download cannot stall the run. */
const MAX_BODY_BYTES = 2_000_000

const DEFAULTS = {
  timeoutMs: 45_000,
  navigationTimeoutMs: 30_000,
  headless: true,
  urlHints: DEFAULT_URL_HINTS,
  tokenKeys: DEFAULT_TOKEN_KEYS,
  userAgent: undefined,
  // Point at a Chromium that is already on the box. Playwright otherwise
  // downloads its own (~400MB plus system libraries), which is a lot to put
  // on a small VPS when the distribution already ships one.
  executablePath: process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined,
}

/**
 * A JWT is three base64url segments. Checking the shape stops us returning a
 * CSRF nonce or a session id that happened to sit under a key called "token".
 */
const JWT_PATTERN = /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]*$/

export function looksLikeJwt(value) {
  return typeof value === 'string' && value.split('.').length === 3 && JWT_PATTERN.test(value)
}

/**
 * Strip a leading scheme, so a caller storing the result never ends up with
 * "Bearer Bearer eyJ..." on the wire.
 */
export function stripBearerPrefix(value) {
  return typeof value === 'string' ? value.replace(/^Bearer\s+/i, '').trim() : value
}

/** Keep secrets out of anything that can be logged or returned. */
export function redact(text, secrets = []) {
  let output = String(text ?? '')

  for (const secret of secrets) {
    if (typeof secret === 'string' && secret.length >= 3) {
      output = output.split(secret).join('[redacted]')
    }
  }

  return output
}

/**
 * Walk a decoded body for the first key that looks like a token.
 *
 * Depth-bounded because a response is arbitrary JSON from a third party, and
 * an unbounded walk over a deeply nested payload is a denial of service on
 * ourselves.
 */
export function findTokenInBody(body, tokenKeys = DEFAULT_TOKEN_KEYS, depth = 0) {
  if (depth > MAX_BODY_DEPTH || body === null || typeof body !== 'object') {
    return null
  }

  // Preferred keys first, at this level, before descending: a nested
  // "refresh_token.token" should never beat a top-level "access_token".
  for (const key of tokenKeys) {
    const candidate = stripBearerPrefix(body[key])

    if (looksLikeJwt(candidate)) {
      return candidate
    }
  }

  for (const value of Object.values(body)) {
    if (value !== null && typeof value === 'object') {
      const found = findTokenInBody(value, tokenKeys, depth + 1)

      if (found) return found
    }
  }

  return null
}

function urlLooksAuthenticated(url, hints) {
  const lower = url.toLowerCase()

  return hints.some((hint) => lower.includes(hint))
}

/**
 * Log in and return the bearer token the portal issues.
 *
 * @param {object} options
 * @param {string} options.loginUrl            Page carrying the login form.
 * @param {string} options.username
 * @param {string} options.password
 * @param {object} options.selectors           { username, password, submit }
 * @param {number} [options.timeoutMs]         Ceiling on the whole operation.
 * @param {boolean} [options.headless]
 * @param {string[]} [options.urlHints]        Paths that may carry a token.
 * @param {string[]} [options.tokenKeys]       Body keys that may hold one.
 * @param {string} [options.executablePath]    A Chromium already on the box.
 * @returns {Promise<{token: string, source: 'response-body'|'request-header', elapsedMs: number}>}
 */
export async function extractBearerToken(options) {
  // Undefined keys are dropped rather than spread. `{...DEFAULTS, ...options}`
  // lets an explicitly-undefined option overwrite its default with undefined,
  // and a caller that builds the options object from optional JSON fields
  // supplies exactly that -- which left the defaults unset at the point of
  // use rather than at the point of construction, where it would have been
  // obvious.
  const supplied = Object.fromEntries(
    Object.entries(options ?? {}).filter(([, value]) => value !== undefined),
  )
  const config = { ...DEFAULTS, ...supplied }
  const { loginUrl, username, password, selectors } = config

  for (const [name, value] of Object.entries({ loginUrl, username, password })) {
    if (typeof value !== 'string' || value === '') {
      throw new TokenExtractionError(
        ExtractionError.NAVIGATION_FAILED,
        `${name} is required.`,
      )
    }
  }

  for (const name of ['username', 'password', 'submit']) {
    if (typeof selectors?.[name] !== 'string' || selectors[name] === '') {
      throw new TokenExtractionError(
        ExtractionError.SELECTOR_NOT_FOUND,
        `A selector for "${name}" is required.`,
      )
    }
  }

  const secrets = [password]
  const startedAt = Date.now()

  let browser
  let resolveToken
  let settled = false

  // Resolved by whichever listener sees a token first. Created before
  // anything navigates, so no response can arrive before someone is
  // listening for it.
  const tokenSeen = new Promise((resolve) => {
    resolveToken = (result) => {
      if (!settled) {
        settled = true
        resolve(result)
      }
    }
  })

  try {
    try {
      browser = await chromium.launch({
        headless: config.headless,
        ...(config.executablePath ? { executablePath: config.executablePath } : {}),
      })
    } catch (error) {
      throw new TokenExtractionError(
        ExtractionError.BROWSER_LAUNCH_FAILED,
        `Could not launch Chromium: ${redact(error.message, secrets)}. `
          + 'Install it with "npx playwright install --with-deps chromium".',
        { cause: error },
      )
    }

    const context = await browser.newContext(
      config.userAgent ? { userAgent: config.userAgent } : {},
    )
    const page = await context.newPage()
    page.setDefaultTimeout(config.navigationTimeoutMs)

    // Records whether the portal actively rejected the credentials, so a
    // wrong password is reported as such instead of as a timeout -- the
    // difference between "fix your password" and "the site is down".
    let credentialsRejected = false

    // The first source: the body of any authentication-shaped response.
    page.on('response', async (response) => {
      if (settled) return

      const url = response.url()

      if (!urlLooksAuthenticated(url, config.urlHints)) return

      const status = response.status()

      // 401/403 on the login call itself is the portal saying no.
      if ((status === 401 || status === 403) && /login|auth|token|session/i.test(url)) {
        credentialsRejected = true

        return
      }

      if (status >= 400) return

      try {
        const type = (response.headers()['content-type'] ?? '').toLowerCase()

        if (!type.includes('json')) return

        const buffer = await response.body()

        if (buffer.byteLength > MAX_BODY_BYTES) return

        const token = findTokenInBody(JSON.parse(buffer.toString('utf8')), config.tokenKeys)

        if (token) {
          resolveToken({ token, source: 'response-body' })
        }
      } catch {
        // A body that is gone, streaming, or not JSON after all is simply not
        // where the token was. The header listener is still watching.
      }
    })

    // The second source, and in practice the more dependable one: the app
    // using the token it was just given.
    page.on('request', (request) => {
      if (settled) return

      const header = request.headers().authorization ?? request.headers().Authorization

      if (typeof header !== 'string' || !/^Bearer\s+/i.test(header)) return

      const token = stripBearerPrefix(header)

      if (looksLikeJwt(token)) {
        resolveToken({ token, source: 'request-header' })
      }
    })

    try {
      await page.goto(loginUrl, { waitUntil: 'domcontentloaded' })
    } catch (error) {
      throw new TokenExtractionError(
        ExtractionError.NAVIGATION_FAILED,
        `Could not open ${loginUrl}: ${redact(error.message, secrets)}`,
        { cause: error },
      )
    }

    try {
      await page.fill(selectors.username, username)
      await page.fill(selectors.password, password)
    } catch (error) {
      throw new TokenExtractionError(
        ExtractionError.SELECTOR_NOT_FOUND,
        'The username or password field was not found. '
          + `Check the selectors against ${loginUrl}: ${redact(error.message, secrets)}`,
        { cause: error },
      )
    }

    try {
      await page.click(selectors.submit)
    } catch (error) {
      throw new TokenExtractionError(
        ExtractionError.SELECTOR_NOT_FOUND,
        `The submit control was not found: ${redact(error.message, secrets)}`,
        { cause: error },
      )
    }

    // No sleeps. Either a listener sees a token, or the clock runs out --
    // and the settled flag means a token arriving during teardown is still
    // the answer rather than a race.
    const remaining = Math.max(1_000, config.timeoutMs - (Date.now() - startedAt))

    const outcome = await Promise.race([
      tokenSeen,
      new Promise((resolve) => setTimeout(() => resolve(null), remaining)),
    ])

    if (outcome) {
      return { ...outcome, elapsedMs: Date.now() - startedAt }
    }

    if (credentialsRejected) {
      throw new TokenExtractionError(
        ExtractionError.INVALID_CREDENTIALS,
        'The portal rejected those credentials.',
      )
    }

    // Distinguish "still sitting on the login form" from "logged in, but the
    // token never crossed the wire in a shape we recognised". They need
    // different fixes: the first is usually credentials or an extra step
    // such as MFA, the second is usually the token key names.
    const stillOnLoginForm = await page
      .locator(selectors.password)
      .isVisible()
      .catch(() => false)

    if (stillOnLoginForm) {
      throw new TokenExtractionError(
        ExtractionError.INVALID_CREDENTIALS,
        'Still on the login form after submitting. The credentials were probably rejected, '
          + 'or the portal asked for a second factor this cannot answer.',
      )
    }

    throw new TokenExtractionError(
      ExtractionError.TOKEN_NOT_FOUND,
      `Login appeared to succeed but no bearer token was seen within ${config.timeoutMs}ms. `
        + 'The portal may name its token differently -- try adjusting the token keys.',
    )
  } catch (error) {
    if (error instanceof TokenExtractionError) throw error

    if (/timeout/i.test(error?.message ?? '')) {
      throw new TokenExtractionError(
        ExtractionError.TIMEOUT,
        `Timed out after ${Date.now() - startedAt}ms: ${redact(error.message, secrets)}`,
        { cause: error },
      )
    }

    throw new TokenExtractionError(
      ExtractionError.NAVIGATION_FAILED,
      redact(error?.message ?? 'Unknown failure', secrets),
      { cause: error },
    )
  } finally {
    // Unconditional: a browser left running is a zombie holding hundreds of
    // megabytes, and on a scheduler it is one per run.
    await browser?.close().catch(() => {})
  }
}
