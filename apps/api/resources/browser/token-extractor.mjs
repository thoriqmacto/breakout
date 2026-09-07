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
  constructor(code, message, { cause, evidence } = {}) {
    super(message)
    this.name = 'TokenExtractionError'
    this.code = code
    // Structured, so the caller can render it under its own rules rather than
    // deciding whether a free-form message is safe to show. The PHP side
    // deliberately does not surface child messages verbatim -- they can carry
    // a URL, a selector, or portal markup -- which is exactly how the first
    // version of this diagnosis got computed and then discarded.
    if (evidence) this.evidence = evidence
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
  // A page of the app to open after logging in, to provoke the authenticated
  // call that carries the bearer. Empty means "do not navigate".
  postLoginUrl: undefined,
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
 * Turn what was observed into the next thing to try.
 *
 * "No bearer token was seen" is true of four different situations that need
 * four different fixes, and the counts separate them: no authenticated call
 * was made at all, one was made carrying something that is not a JWT, storage
 * was empty, or the app talks to a host the login never reached.
 */
/**
 * The evidence as plain data, safe to hand across a process boundary.
 *
 * Counts and host names only. A host name is the one identifier worth carrying
 * -- it says whether the app ever talked to the API at all -- and it is
 * already public: it is the site being logged into.
 */
export function summariseEvidence(evidence) {
  return {
    storage_key_names: evidence.storageKeyNames,
    cookie_names: evidence.cookieNames,
    indexeddb_names: evidence.indexedDbNames,
    landed_url: evidence.landedUrl,
    title: evidence.title,
    requests: evidence.requests,
    authorization_headers: evidence.authorizationHeaders,
    non_jwt_authorization: evidence.nonJwtAuthorization,
    json_responses: evidence.jsonResponses,
    storage_keys: evidence.storageKeys,
    cookies: evidence.cookies,
    hosts: [...evidence.hosts].slice(0, 8),
  }
}

export function describeEvidence(evidence) {
  const hosts = [...evidence.hosts].slice(0, 6).join(', ')

  if (evidence.authorizationHeaders === 0 && evidence.storageKeys === 0 && evidence.cookies === 0) {
    return 'No request carried an Authorization header and web storage was empty, so the app '
      + 'never used a token while this was watching. Set BROWSER_AUTH_POST_LOGIN_URL to a page '
      + `of the app that loads data. Hosts contacted: ${hosts || 'none'}.`
  }

  if (evidence.nonJwtAuthorization > 0) {
    return `${evidence.nonJwtAuthorization} request(s) carried a bearer that is not a JWT, so it `
      + 'was rejected as the wrong kind of token. That portal issues an opaque token this cannot '
      + 'check the expiry of.'
  }

  if (evidence.authorizationHeaders === 0) {
    return `Web storage held ${evidence.storageKeys} key(s), none of them a JWT, and no request `
      + `carried an Authorization header. ${evidence.jsonResponses} JSON response(s) were parsed. `
      + `Hosts contacted: ${hosts || 'none'}. Try BROWSER_AUTH_TOKEN_KEYS, or point `
      + 'BROWSER_AUTH_POST_LOGIN_URL at a page that loads data.'
  }

  return `${evidence.authorizationHeaders} authorized request(s) were seen but none yielded a `
    + `usable token. Hosts contacted: ${hosts || 'none'}.`
}

/**
 * Look for the token where a single-page app usually keeps it.
 *
 * Watching the wire misses a portal that authenticates once, stores the token,
 * and makes no further authenticated call while this is watching -- which is
 * exactly what a login page that lands on a static dashboard does. The app
 * still has the token; it is in web storage.
 *
 * Only JWT-shaped values are accepted, so a CSRF nonce or a session id under a
 * key called "token" is not mistaken for one, and nothing else in storage is
 * read back or reported.
 */
export async function findTokenInStorage(page, tokenKeys = DEFAULT_TOKEN_KEYS) {
  const entries = await page
    .evaluate(() => {
      const collected = []

      for (const storage of [window.localStorage, window.sessionStorage]) {
        try {
          for (let index = 0; index < storage.length; index += 1) {
            const key = storage.key(index)
            collected.push([key, storage.getItem(key)])
          }
        } catch {
          // Storage can be denied outright; the other sources still apply.
        }
      }

      return collected
    })
    .catch(() => [])

  const candidates = []

  for (const [key, raw] of entries) {
    if (typeof raw !== 'string' || raw === '') continue

    // Stored bare, which is the common case.
    const bare = stripBearerPrefix(raw)

    if (looksLikeJwt(bare)) {
      candidates.push({ key, token: bare })

      continue
    }

    // Or stored as JSON, which is the other common case: a session object
    // with the access token as one field among several.
    try {
      const token = findTokenInBody(JSON.parse(raw), tokenKeys)

      if (token) candidates.push({ key, token })
    } catch {
      // Not JSON, so not a session object.
    }
  }

  // A key naming itself as the access token beats one that merely contains a
  // JWT: a refresh token is also a JWT and is the wrong one to take.
  const preferred = candidates.find(({ key }) =>
    tokenKeys.some((name) => new RegExp(name.replace(/[^a-z0-9]/gi, '.?'), 'i').test(key)),
  )

  return {
    entries: entries.length,
    names: entries.map(([key]) => key).filter((key) => typeof key === 'string').slice(0, 40),
    found: preferred ?? candidates[0] ?? null,
  }
}

/**
 * The fifth place: IndexedDB.
 *
 * A single-page app with an offline story keeps its session here rather than
 * in localStorage, and nothing about it is visible to a request listener or a
 * storage scan. Bounded deliberately: a handful of databases, a handful of
 * records each, because this runs against a site whose storage is not ours to
 * assume anything about.
 */
export async function findTokenInIndexedDb(page, tokenKeys = DEFAULT_TOKEN_KEYS) {
  const result = await page
    .evaluate(async (keys) => {
      const names = []
      const values = []

      if (!('indexedDB' in window) || typeof indexedDB.databases !== 'function') {
        return { names, values }
      }

      const databases = (await indexedDB.databases().catch(() => [])) ?? []

      for (const { name } of databases.slice(0, 8)) {
        if (!name) continue

        names.push(name)

        const database = await new Promise((resolve) => {
          const request = indexedDB.open(name)
          request.onsuccess = () => resolve(request.result)
          request.onerror = () => resolve(null)
          // A database mid-upgrade would block forever otherwise.
          request.onblocked = () => resolve(null)
        })

        if (!database) continue

        for (const store of [...database.objectStoreNames].slice(0, 8)) {
          try {
            const records = await new Promise((resolve) => {
              const request = database.transaction(store, 'readonly').objectStore(store).getAll(undefined, 50)
              request.onsuccess = () => resolve(request.result ?? [])
              request.onerror = () => resolve([])
            })

            for (const record of records) {
              values.push(typeof record === 'string' ? record : JSON.stringify(record))
            }
          } catch {
            // A store that will not open is simply not where the token is.
          }
        }

        database.close()
      }

      return { names, values }
    }, tokenKeys)
    .catch(() => ({ names: [], values: [] }))

  for (const raw of result.values) {
    const bare = stripBearerPrefix(raw)

    if (looksLikeJwt(bare)) {
      return { names: result.names, found: { key: 'indexeddb', token: bare } }
    }

    try {
      const token = findTokenInBody(JSON.parse(raw), tokenKeys)

      if (token) return { names: result.names, found: { key: 'indexeddb', token } }
    } catch {
      // Not JSON.
    }
  }

  return { names: result.names, found: null }
}

/**
 * And the fourth place: a cookie.
 *
 * A portal that keeps its token in an httpOnly cookie shows it in neither web
 * storage nor a readable header -- the browser attaches it without JavaScript
 * ever touching it. Playwright can read those cookies where the page cannot,
 * which is the one advantage a driven browser has over the page's own scripts.
 */
export async function findTokenInCookies(context, tokenKeys = DEFAULT_TOKEN_KEYS) {
  const cookies = await context.cookies().catch(() => [])

  const candidates = []

  for (const cookie of cookies) {
    const value = stripBearerPrefix(cookie?.value)

    if (looksLikeJwt(value)) {
      candidates.push({ key: cookie.name, token: value })
    }
  }

  const preferred = candidates.find(({ key }) =>
    tokenKeys.some((name) => new RegExp(name.replace(/[^a-z0-9]/gi, '.?'), 'i').test(key)),
  )

  return {
    entries: cookies.length,
    names: cookies.map((cookie) => cookie?.name).filter((name) => typeof name === 'string').slice(0, 40),
    found: preferred ?? candidates[0] ?? null,
  }
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

    // What was seen, for the case where nothing was found. Names and counts
    // only -- never a header value, never a body. Without this, "no bearer
    // token was seen" is unfalsifiable from the outside: it cannot say
    // whether the app made no authenticated call, made one this never saw, or
    // made one carrying something that is not a JWT.
    const evidence = {
      requests: 0,
      authorizationHeaders: 0,
      nonJwtAuthorization: 0,
      jsonResponses: 0,
      hosts: new Set(),
      storageKeys: 0,
      cookies: 0,
      // Names, never values. A key called "sb_session" says the app has a
      // session; the count 15 says nothing at all. This is what separates
      // "logged in and stored it somewhere unexpected" from "never logged in".
      storageKeyNames: [],
      cookieNames: [],
      indexedDbNames: [],
      landedUrl: null,
      title: null,
    }

    // Listeners go on the *context*, not the page. A portal that finishes
    // login in a popup or a new tab makes its authenticated calls from a page
    // this function never created, and a page-scoped listener sees none of
    // them.
    // The first source: the body of any authentication-shaped response.
    context.on('response', async (response) => {
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

        evidence.jsonResponses += 1

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
    context.on('request', (request) => {
      evidence.requests += 1

      try {
        evidence.hosts.add(new URL(request.url()).host)
      } catch {
        // A request URL that will not parse tells us nothing; skip it.
      }

      if (settled) return

      const header = request.headers().authorization ?? request.headers().Authorization

      if (typeof header !== 'string' || !/^Bearer\s+/i.test(header)) return

      evidence.authorizationHeaders += 1

      const token = stripBearerPrefix(header)

      if (looksLikeJwt(token)) {
        resolveToken({ token, source: 'request-header' })

        return
      }

      // A bearer that is not a JWT: worth counting, because it means the
      // capture is working and the shape test is what rejected it.
      evidence.nonJwtAuthorization += 1
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

    const deadline = startedAt + config.timeoutMs

    // Give the login call itself a moment to come back before doing anything
    // else, so a portal that returns the token in its login response is
    // answered by the listeners without a second navigation.
    let outcome = await Promise.race([
      tokenSeen,
      new Promise((resolve) => setTimeout(() => resolve(null), Math.min(8_000, config.timeoutMs))),
    ])

    // Then provoke the call the app makes when it uses the token.
    //
    // Some portals authenticate and land on a page that makes no further API
    // call, so nothing carries the bearer while this is watching. Opening a
    // page of the app that does -- the same thing a person does when they
    // read the token out of devtools -- puts it on the wire.
    if (!outcome && typeof config.postLoginUrl === 'string' && config.postLoginUrl !== '') {
      await page
        .goto(config.postLoginUrl, { waitUntil: 'domcontentloaded' })
        .catch(() => {})

      outcome = await Promise.race([
        tokenSeen,
        new Promise((resolve) => setTimeout(() => resolve(null), Math.min(8_000, Math.max(0, deadline - Date.now())))),
      ])
    }

    // Finally, look where the app keeps it. Polled rather than awaited
    // because web storage fires no event this side of the browser, and a
    // single look would race the app writing it.
    while (!outcome && Date.now() < deadline) {
      const scan = await findTokenInStorage(page, config.tokenKeys)

      evidence.storageKeys = scan.entries
      evidence.storageKeyNames = scan.names

      if (scan.found) {
        outcome = { token: scan.found.token, source: `storage:${scan.found.key}` }

        break
      }

      const cookieScan = await findTokenInCookies(context, config.tokenKeys)

      evidence.cookies = cookieScan.entries
      evidence.cookieNames = cookieScan.names

      if (cookieScan.found) {
        outcome = { token: cookieScan.found.token, source: `cookie:${cookieScan.found.key}` }

        break
      }

      const indexedScan = await findTokenInIndexedDb(page, config.tokenKeys)

      evidence.indexedDbNames = indexedScan.names

      if (indexedScan.found) {
        outcome = { token: indexedScan.found.token, source: 'indexeddb' }

        break
      }

      outcome = await Promise.race([
        tokenSeen,
        new Promise((resolve) => setTimeout(() => resolve(null), Math.min(1_000, Math.max(1, deadline - Date.now())))),
      ])
    }

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
    evidence.landedUrl = page.url()
    evidence.title = await page.title().catch(() => null)

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
        + describeEvidence(evidence),
      { evidence: summariseEvidence(evidence) },
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
