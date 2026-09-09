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
  // The saved profile has no session and no password was offered. Its own
  // code because the fix is not the one INVALID_CREDENTIALS implies: nothing
  // was submitted and no credential was judged, so sending the operator to
  // check a password wastes the round.
  PROFILE_SIGNED_OUT: 'PROFILE_SIGNED_OUT',
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
  // Sign in again even when the app looks signed in already. See the block
  // that uses it: an app rendering from a session the API has stopped
  // accepting is indistinguishable, from the page, from a working one.
  forceLogin: false,
  // A directory the browser keeps between runs. Without one, every run is a
  // brand-new device: cookies, storage and whatever identity the portal
  // assigned are all discarded on close, so a device-trust prompt can never be
  // satisfied -- approving the device changes nothing, because the next run is
  // a different device again.
  profileDir: undefined,
  // Where to write a picture of the page when the run does not end in a
  // token. Names and URLs describe a page; a screenshot *is* the page, and a
  // captcha, a spinner and an error toast are one glance apart while being
  // several rounds apart by inference.
  screenshotPath: undefined,
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
    claimed_token: evidence.claimedToken,
    indexeddb_names: evidence.indexedDbNames,
    landed_url: evidence.landedUrl,
    title: evidence.title,
    login_form_gone: evidence.loginFormGone,
    url_after_submit: evidence.urlAfterSubmit,
    screenshot: evidence.screenshot,
    used_existing_session: evidence.usedExistingSession,
    cleared_session: evidence.clearedSession,
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
 * Pull a token out of a stored value, whatever wrapping it arrived in.
 *
 * A cookie is percent-encoded by definition, and an app that keeps a session
 * object rather than a bare string stores JSON -- so the value that holds the
 * token frequently looks like nothing at all until it is decoded. Checking
 * only for a bare JWT is what let a cookie named `credentialStorage` sit in
 * plain sight while the scan reported no token in any cookie.
 *
 * Each layer is tried in turn and failures are ignored: a value that is not
 * encoded is simply itself.
 */
export function unwrapToken(raw, tokenKeys = DEFAULT_TOKEN_KEYS) {
  if (typeof raw !== 'string' || raw === '') return null

  const candidates = [raw]

  try {
    const decoded = decodeURIComponent(raw)

    if (decoded !== raw) candidates.push(decoded)
  } catch {
    // Not percent-encoded, or malformed. The raw value still stands.
  }

  // Some apps base64 the session object before storing it. Only attempted on
  // values that could be base64 at all, so this cannot spend time on prose.
  if (/^[A-Za-z0-9+/=_-]{16,}$/.test(raw) && !looksLikeJwt(raw)) {
    try {
      candidates.push(atob(raw.replace(/-/g, '+').replace(/_/g, '/')))
    } catch {
      // Not base64.
    }
  }

  for (const candidate of candidates) {
    const bare = stripBearerPrefix(candidate)

    if (looksLikeJwt(bare)) return bare

    try {
      const token = findTokenInBody(JSON.parse(candidate), tokenKeys)

      if (token) return token
    } catch {
      // Not JSON at this layer.
    }
  }

  return null
}

/**
 * Does this value at least *claim* to hold a token?
 *
 * The difference that matters when nothing is found: a store holding a
 * token-shaped key whose value this could not use is a parsing problem, and a
 * store holding nothing of the sort means there is no session to find. Those
 * need opposite fixes, and only the name of the offending key can tell them
 * apart.
 */
export function mentionsToken(raw, tokenKeys = DEFAULT_TOKEN_KEYS) {
  if (typeof raw !== 'string' || raw === '') return false

  let text = raw

  try {
    text = decodeURIComponent(raw)
  } catch {
    // Keep the raw text.
  }

  return tokenKeys.some((key) => text.toLowerCase().includes(key.toLowerCase()))
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
  const claimed = []

  for (const [key, raw] of entries) {
    const token = unwrapToken(raw, tokenKeys)

    if (token) {
      candidates.push({ key, token })

      continue
    }

    if (mentionsToken(raw, tokenKeys)) claimed.push(key)
  }

  // A key naming itself as the access token beats one that merely contains a
  // JWT: a refresh token is also a JWT and is the wrong one to take.
  const preferred = candidates.find(({ key }) =>
    tokenKeys.some((name) => new RegExp(name.replace(/[^a-z0-9]/gi, '.?'), 'i').test(key)),
  )

  return {
    entries: entries.length,
    names: entries.map(([key]) => key).filter((key) => typeof key === 'string').slice(0, 40),
    claimed,
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
    const token = unwrapToken(raw, tokenKeys)

    if (token) return { names: result.names, found: { key: 'indexeddb', token } }
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
  const claimed = []

  for (const cookie of cookies) {
    const token = unwrapToken(cookie?.value, tokenKeys)

    if (token) {
      candidates.push({ key: cookie.name, token })

      continue
    }

    if (mentionsToken(cookie?.value, tokenKeys)) claimed.push(cookie.name)
  }

  const preferred = candidates.find(({ key }) =>
    tokenKeys.some((name) => new RegExp(name.replace(/[^a-z0-9]/gi, '.?'), 'i').test(key)),
  )

  return {
    entries: cookies.length,
    names: cookies.map((cookie) => cookie?.name).filter((name) => typeof name === 'string').slice(0, 40),
    claimed,
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

  // Credentials are required only when a login is going to happen. With a
  // profile that is already signed in there is nothing to type, and demanding
  // a password anyway would mean storing one to do something that does not
  // need it.
  const required = config.profileDir ? { loginUrl } : { loginUrl, username, password }

  for (const [name, value] of Object.entries(required)) {
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

  /**
   * Whether a token seen right now is one this run should accept.
   *
   * Closed only when a login has been demanded. An app whose session the
   * portal has ended goes on rendering, and fires its authenticated calls on
   * page load carrying the bearer it still holds -- which the request listener
   * captures and settles on within milliseconds, long before there is any
   * chance to clear the session and sign in again. Forcing the login was not
   * enough on its own: the stale token had already won the race. Nothing is
   * accepted until the login this run asked for has actually been submitted.
   */
  let accepting = true

  // Resolved by whichever listener sees a token first. Created before
  // anything navigates, so no response can arrive before someone is
  // listening for it.
  const tokenSeen = new Promise((resolve) => {
    resolveToken = (result) => {
      if (!settled && accepting) {
        settled = true
        resolve(result)
      }
    }
  })

  let context

  // Assigned once the page exists; declared out here so the catch can reach
  // it. A timeout unwinds through the catch, and that is the path whose
  // screenshot matters most.
  let capture = async () => {}

  // What was seen, for the case where nothing was found. Names and counts
  // only -- never a header value, never a body. Without this, "no bearer
  // token was seen" is unfalsifiable from the outside: it cannot say
  // whether the app made no authenticated call, made one this never saw, or
  // made one carrying something that is not a JWT.
  //
  // Out here with capture, and for the same reason: the catch has to be able
  // to summarise what was seen, and a const inside the try is not in scope
  // there -- which fails as a ReferenceError that replaces the real error
  // with a worse one.
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
    // Keys whose contents mention a token but yielded none: a parsing
    // problem, as distinct from having no session at all.
    claimedToken: [],
    indexedDbNames: [],
    landedUrl: null,
    title: null,
    // Recorded immediately after the submit, before any navigation of ours.
    loginFormGone: false,
    urlAfterSubmit: null,
    screenshot: null,
    // True when the saved profile was already signed in and no login ran.
    usedExistingSession: false,
    // True when a stale session was cleared to force the login form back.
    clearedSession: false,
  }

  try {
    const launchOptions = {
      headless: config.headless,
      ...(config.executablePath ? { executablePath: config.executablePath } : {}),
    }

    try {
      if (config.profileDir) {
        // A persistent context *is* the browser: it owns the profile
        // directory, so cookies and storage outlive the run and the portal
        // sees the same device next time.
        context = await chromium.launchPersistentContext(config.profileDir, {
          ...launchOptions,
          ...(config.userAgent ? { userAgent: config.userAgent } : {}),
        })
      } else {
        browser = await chromium.launch(launchOptions)
        context = await browser.newContext(
          config.userAgent ? { userAgent: config.userAgent } : {},
        )
      }
    } catch (error) {
      throw new TokenExtractionError(
        ExtractionError.BROWSER_LAUNCH_FAILED,
        `Could not launch Chromium: ${redact(error.message, secrets)}. `
          + 'Install it with "npx playwright install --with-deps chromium".',
        { cause: error },
      )
    }

    const page = context.pages()[0] ?? (await context.newPage())

    // Shut before the first navigation, so the stale token the app sends on
    // page load cannot settle the run ahead of the login being forced.
    accepting = !config.forceLogin

    /**
     * Photograph the page, once, on whichever path ends the run badly.
     *
     * A timeout is the failure that most needs a picture and the one least
     * likely to reach a screenshot written at a single exit -- so this is
     * called from the ordinary no-token path and from the catch alike.
     */
    capture = async () => {
      if (!config.screenshotPath || evidence.screenshot) return

      await page
        .screenshot({ path: config.screenshotPath, fullPage: true })
        .then(() => {
          evidence.screenshot = config.screenshotPath
        })
        .catch(() => {})
    }
    page.setDefaultTimeout(config.navigationTimeoutMs)

    // Records whether the portal actively rejected the credentials, so a
    // wrong password is reported as such instead of as a timeout -- the
    // difference between "fix your password" and "the site is down".
    let credentialsRejected = false

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

    const deadline = startedAt + config.timeoutMs

    // Every wait from here is measured against the run's own budget, not
    // against a constant. Waits written as fixed intervals add up: this
    // function grew a 25-second form poll, two 8-second settles and a
    // 10-second redirect wait on top of a 30-second navigation, against a
    // 55-second budget, and the parent killed the child mid-run -- which
    // reports as a portal timeout and takes the diagnostic screenshot with it.
    //
    // Declared here rather than further down because the forced-login reload
    // below is now the first wait that needs it, and a const used above its
    // declaration is a runtime error no syntax check will catch.
    const remaining = (want) => Math.max(0, Math.min(want, deadline - Date.now()))

    // A portal that already knows this profile sends /login straight to the
    // app, so there is no form to fill. Logging in again anyway would trip the
    // device check on every scheduled run -- the exact thing the profile
    // exists to stop.
    let loginFormPresent = await page
      .locator(selectors.password)
      .first()
      .isVisible({ timeout: 5_000 })
      .catch(() => false)

    // Unless the caller asked for a real login, which is the only way out of
    // the following trap: the portal ends the session server-side, the app
    // goes on rendering because it has not yet been told, and the bearer it
    // still holds is refused by the API. From the page there is no difference
    // between that and a healthy session -- no form appears, so the login is
    // skipped, the same dead token is captured, and supplying a password
    // achieves nothing at all. Clearing the session is what makes the form
    // come back.
    if (config.forceLogin && !loginFormPresent) {
      evidence.clearedSession = true

      await context.clearCookies().catch(() => {})

      await page
        .evaluate(() => {
          try {
            localStorage.clear()
            sessionStorage.clear()
          } catch {
            // A page that denies storage access has nothing here to clear.
          }
        })
        .catch(() => {})

      await page.goto(loginUrl, { waitUntil: 'domcontentloaded' }).catch(() => {})

      loginFormPresent = await page
        .locator(selectors.password)
        .first()
        .isVisible({ timeout: remaining(10_000) })
        .catch(() => false)
    }

    evidence.usedExistingSession = !loginFormPresent

    if (loginFormPresent) {
      if (typeof username !== 'string' || username === '' || typeof password !== 'string' || password === '') {
        throw new TokenExtractionError(
          ExtractionError.PROFILE_SIGNED_OUT,
          'The portal is asking for a login and no credentials were supplied. The saved '
            + 'profile is signed out: run this once with credentials to sign in again.',
          { evidence: summariseEvidence(evidence) },
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

      // The login this run asked for has now happened, so whatever the app
      // sends next is a token from this session rather than the one it was
      // holding when the run started.
      accepting = true
    }

    // No form even after clearing, so there was no login to wait for and
    // nothing to distinguish a new token from an old one. Reopening is the
    // same behaviour as a run that never asked to force a login: better a
    // token that may be stale, which the verifier will now catch, than a run
    // that reports finding none at all.
    accepting = true

    // Whether the form is still there, asked *now*, before this function
    // navigates anywhere of its own accord.
    //
    // It used to be asked at the end, which was fine until BROWSER_AUTH_POST_-
    // LOGIN_URL existed: from then on the check ran after we had navigated
    // away ourselves, so it could never be true, and every failure claimed
    // "Signed in" on no evidence whatsoever. A portal that silently refuses a
    // login -- a device check, a captcha, a rejected password -- looked
    // identical to one that signed in and hid its token.
    if (evidence.usedExistingSession) {
      evidence.loginFormGone = true
    } else {
      // Polled, not sampled once. A single look a fixed moment after the click
      // asks the question before the portal has answered it: a login that goes
      // through a device check or a captcha takes longer than any interval
      // short enough to be worth waiting, and reporting "still on the form"
      // then means calling a slow success a rejected password.
      const submitDeadline = Date.now() + remaining(25_000)

      while (Date.now() < submitDeadline) {
        // A token settles it outright: whatever the form is doing, the login
        // worked.
        if (settled) break

        // The portal saying no is also an answer, and waiting out the rest of
        // the window after a 401 only delays reporting it.
        if (credentialsRejected) break

        evidence.loginFormGone = !(await page
          .locator(selectors.password)
          .first()
          .isVisible()
          .catch(() => false))

        if (evidence.loginFormGone) break

        await page.waitForTimeout(500)
      }

      if (settled) evidence.loginFormGone = true
    }

    evidence.urlAfterSubmit = page.url()
    evidence.title = await page.title().catch(() => null)

    // Give the login call itself a moment to come back before doing anything
    // else, so a portal that returns the token in its login response is
    // answered by the listeners without a second navigation.
    let outcome = await Promise.race([
      tokenSeen,
      new Promise((resolve) => setTimeout(() => resolve(null), remaining(8_000))),
    ])

    // Then provoke the call the app makes when it uses the token.
    //
    // Some portals authenticate and land on a page that makes no further API
    // call, so nothing carries the bearer while this is watching. Opening a
    // page of the app that does -- the same thing a person does when they
    // read the token out of devtools -- puts it on the wire.
    if (!outcome && typeof config.postLoginUrl === 'string' && config.postLoginUrl !== '') {
      // Let the portal finish first. A login that is mid-redirect when this
      // navigates elsewhere never gets to set its session, and the result is
      // indistinguishable from a login that was refused -- this code would
      // have caused exactly the symptom it is looking for.
      await page
        .waitForURL((url) => !url.href.startsWith(loginUrl), { timeout: Math.max(1, remaining(10_000)) })
        .catch(() => {})

      await page
        .goto(config.postLoginUrl, { waitUntil: 'domcontentloaded' })
        .catch(() => {})

      outcome = await Promise.race([
        tokenSeen,
        new Promise((resolve) => setTimeout(() => resolve(null), remaining(8_000))),
      ])
    }

    // Finally, look where the app keeps it. Polled rather than awaited
    // because web storage fires no event this side of the browser, and a
    // single look would race the app writing it.
    while (!outcome && Date.now() < deadline) {
      const scan = await findTokenInStorage(page, config.tokenKeys)

      evidence.storageKeys = scan.entries
      evidence.storageKeyNames = scan.names
      evidence.claimedToken = [...new Set([...evidence.claimedToken, ...scan.claimed])]

      if (scan.found) {
        outcome = { token: scan.found.token, source: `storage:${scan.found.key}` }

        break
      }

      const cookieScan = await findTokenInCookies(context, config.tokenKeys)

      evidence.cookies = cookieScan.entries
      evidence.cookieNames = cookieScan.names
      evidence.claimedToken = [...new Set([...evidence.claimedToken, ...cookieScan.claimed])]

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
        new Promise((resolve) => setTimeout(() => resolve(null), Math.max(1, remaining(1_000)))),
      ])
    }

    if (outcome) {
      return { ...outcome, elapsedMs: Date.now() - startedAt }
    }

    await capture()

    evidence.landedUrl = page.url()

    if (credentialsRejected) {
      throw new TokenExtractionError(
        ExtractionError.INVALID_CREDENTIALS,
        'The portal rejected those credentials.',
        { evidence: summariseEvidence(evidence) },
      )
    }

    // Distinguish "still sitting on the login form" from "logged in, but the
    // token never crossed the wire in a shape we recognised". They need
    // different fixes: the first is usually credentials or an extra step
    // such as MFA, the second is usually the token key names.
    if (!evidence.loginFormGone) {
      throw new TokenExtractionError(
        ExtractionError.INVALID_CREDENTIALS,
        'Still on the login form after submitting. The credentials were probably rejected, '
          + 'or the portal asked for a second factor or a captcha this cannot answer.',
        { evidence: summariseEvidence(evidence) },
      )
    }

    throw new TokenExtractionError(
      ExtractionError.TOKEN_NOT_FOUND,
      `The login form was left behind but no bearer token was seen within ${config.timeoutMs}ms. `
        + describeEvidence(evidence),
      { evidence: summariseEvidence(evidence) },
    )
  } catch (error) {
    // Every failing path gets the same treatment, rather than each throw site
    // remembering to ask for it. A TokenExtractionError raised inside the try
    // -- an unreachable portal, a missing selector -- used to be re-thrown
    // untouched, so the failures that most needed a picture of the page were
    // the ones that never got one.
    await capture()

    if (error instanceof TokenExtractionError) {
      if (error.evidence === undefined) error.evidence = summariseEvidence(evidence)

      throw error
    }

    if (/timeout/i.test(error?.message ?? '')) {
      throw new TokenExtractionError(
        ExtractionError.TIMEOUT,
        `Timed out after ${Date.now() - startedAt}ms: ${redact(error.message, secrets)}`,
        { cause: error, evidence: summariseEvidence(evidence) },
      )
    }

    throw new TokenExtractionError(
      ExtractionError.NAVIGATION_FAILED,
      redact(error?.message ?? 'Unknown failure', secrets),
      { cause: error, evidence: summariseEvidence(evidence) },
    )
  } finally {
    // Unconditional: a browser left running is a zombie holding hundreds of
    // megabytes, and on a scheduler it is one per run. A persistent context
    // owns its own browser, and closing it is also what flushes the profile to
    // disk -- skip it and the session that was just established is lost.
    if (config.profileDir) {
      await context?.close().catch(() => {})
    } else {
      await browser?.close().catch(() => {})
    }
  }
}
