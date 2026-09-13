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
  // The credentials passed and the portal asked another device to approve the
  // login. Its own code because nothing here is misconfigured and no password
  // is in question: what is missing is a person tapping "approve" inside the
  // window this run is willing to wait. Reporting it as INVALID_CREDENTIALS --
  // which is what a latched 401 from the approval poll used to do -- sends the
  // operator to re-check a password that was accepted, and tells them no
  // notification was sent when one is sitting on their phone.
  AWAITING_DEVICE_APPROVAL: 'AWAITING_DEVICE_APPROVAL',
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

/**
 * Paths that belong to a second step rather than to the login itself.
 *
 * A device-approval round trip happens on its own endpoints: the app asks
 * whether the approval has landed yet, and gets "not yet" until it has. Those
 * answers are frequently 401 or 403, which is indistinguishable from a refused
 * password unless the path is taken into account.
 */
const DEFAULT_APPROVAL_URL_HINTS = [
  '/device',
  '/approval',
  '/approve',
  '/trusted',
  '/verify',
  '/verification',
  '/challenge',
  '/mfa',
  '/2fa',
  '/otp',
  '/notification',
]

/**
 * What a page waiting for an approval says, in English and in Indonesian.
 *
 * Phrases rather than bare words: a cookie banner mentions "device", and the
 * cost of matching one is a run that waits three minutes for an approval that
 * was never asked for. Only consulted once the login form has gone, so a
 * rejected password cannot reach this test at all.
 */
const DEFAULT_APPROVAL_TEXT_HINTS = [
  'approve',
  'approval',
  'waiting for you',
  'check your phone',
  'check your device',
  'trusted device',
  'notification',
  'authenticator',
  'setujui',
  'persetujuan',
  'perangkat terpercaya',
  'notifikasi',
  'verifikasi',
]

/**
 * Storage and cookie names that make this browser a device the portal knows.
 *
 * Kept across the clear that a forced login performs. Without this, signing in
 * with a password wipes the very state the approval established, so the portal
 * asks for a new approval on every single forced login -- and the operator
 * approves one device after another, each of which is thrown away by the next
 * run that supplies a password.
 */
const DEFAULT_DEVICE_TRUST_KEYS = ['trustedDevice', 'deviceTrusted', 'deviceId', 'device_id']

/** Anything else named after a device or a trust decision is kept too. */
const DEVICE_TRUST_PATTERN = /device|trust/i

/** How deep into a response body to look for one of those keys. */
const MAX_BODY_DEPTH = 6

/**
 * How long a granted approval buys to turn itself into a session.
 *
 * Added to the run's budget at the moment the approval lands, never taken from
 * it: by then the original budget has been spent waiting, and a run that gives
 * up two seconds after the operator tapped approve wasted the one thing that
 * cannot be automated.
 */
const APPROVAL_COMPLETION_MS = 20_000

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
  // How long to hold the run open once the portal has asked another device to
  // approve this login. Zero means "report it and stop", which is the right
  // answer for anything unattended: nobody is going to tap approve for a cron
  // job, and a scheduled renewal that blocks for three minutes is worse than
  // one that says what it is waiting for. An operator at a terminal passes a
  // real number.
  //
  // Deliberately *additional* to timeoutMs rather than carved out of it. The
  // run has already spent its budget opening the page, filling the form and
  // waiting for the portal to answer before a person is even notified, so a
  // share of the original budget is not a wait for a human -- it is whatever
  // happened to be left.
  approvalWaitMs: 0,
  approvalUrlHints: DEFAULT_APPROVAL_URL_HINTS,
  approvalTextHints: DEFAULT_APPROVAL_TEXT_HINTS,
  deviceTrustKeys: DEFAULT_DEVICE_TRUST_KEYS,
  // How long the page may sit silent during an approval wait before the login
  // URL is opened again. Once the approval lands the portal will send /login
  // to the app, so reopening it is what turns a granted approval into a
  // session -- but doing it while the page is still talking would abandon the
  // portal's own poll, so it only happens when nothing is happening.
  approvalNudgeMs: 20_000,
  // Called with {event, ...} as the run progresses. The only way an operator
  // can know to reach for their phone: a headless run shows nothing, and a
  // silent terminal for three minutes is indistinguishable from a hang.
  onProgress: undefined,
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
    kept_device_trust: evidence.keptDeviceTrust,
    dropped_device_trust: evidence.droppedDeviceTrust,
    awaiting_approval: evidence.awaitingApproval,
    approval_signal: evidence.approvalSignal,
    approval_granted: evidence.approvalGranted,
    approval_waited_ms: evidence.approvalWaitedMs,
    auth_rejections: evidence.authRejections,
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

  if (evidence.awaitingApproval && !evidence.approvalGranted) {
    return 'The portal accepted the credentials and asked another device to approve this login. '
      + 'No approval arrived while this was waiting.'
  }

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
 * Does this URL belong to the approval step rather than to the login?
 *
 * Matched on the path only. A hint like "/verify" against a full URL would
 * match a host called verify.example.com, and a query string can carry
 * anything at all -- including, on a portal that round-trips its redirect
 * target, the word "login".
 */
export function urlLooksApproval(url, hints = DEFAULT_APPROVAL_URL_HINTS) {
  let path

  try {
    path = new URL(url).pathname.toLowerCase()
  } catch {
    return false
  }

  return hints.some((hint) => typeof hint === 'string' && hint !== '' && path.includes(hint.toLowerCase()))
}

/** Does this text read like a portal waiting on another device? */
export function mentionsApproval(text, hints = DEFAULT_APPROVAL_TEXT_HINTS) {
  if (typeof text !== 'string' || text === '') return false

  const lower = text.toLowerCase()

  return hints.some((hint) => typeof hint === 'string' && hint !== '' && lower.includes(hint.toLowerCase()))
}

/** Is this a name the portal uses to recognise the device, rather than a session? */
export function namesDeviceTrust(name, keys = DEFAULT_DEVICE_TRUST_KEYS) {
  if (typeof name !== 'string' || name === '') return false

  return DEVICE_TRUST_PATTERN.test(name)
    || keys.some((key) => typeof key === 'string' && key.toLowerCase() === name.toLowerCase())
}

/**
 * Sign out without forgetting which device this is.
 *
 * A forced login has to clear the session, because a portal that has ended one
 * server-side leaves its app rendering as though nothing happened and no login
 * form ever appears. But `clearCookies()` plus `localStorage.clear()` also
 * throws away the device-trust state the last approval established, so the
 * portal asks for a new approval every time a password is supplied -- the
 * operator approves, the next forced run wipes it, and the approval is asked
 * for again. Whatever names itself after a device or a trust decision is put
 * back.
 *
 * @returns {Promise<string[]>} The names that were kept.
 */
export async function clearSessionKeepingDeviceTrust(context, page, keys = DEFAULT_DEVICE_TRUST_KEYS) {
  const kept = []

  const cookies = await context.cookies().catch(() => [])
  const trustedCookies = cookies.filter((cookie) => namesDeviceTrust(cookie?.name, keys))

  const trustedStorage = await page
    .evaluate((names) => {
      const pattern = /device|trust/i
      const saved = []

      for (const store of ['localStorage', 'sessionStorage']) {
        try {
          const storage = window[store]

          for (let index = 0; index < storage.length; index += 1) {
            const key = storage.key(index)

            if (pattern.test(key) || names.some((name) => name.toLowerCase() === key.toLowerCase())) {
              saved.push([store, key, storage.getItem(key)])
            }
          }
        } catch {
          // Storage can be denied outright; there is then nothing to keep.
        }
      }

      return saved
    }, keys)
    .catch(() => [])

  await context.clearCookies().catch(() => {})

  await page
    .evaluate((saved) => {
      try {
        localStorage.clear()
        sessionStorage.clear()
      } catch {
        // A page that denies storage access has nothing here to clear.
      }

      for (const [store, key, value] of saved) {
        try {
          window[store].setItem(key, value)
        } catch {
          // Restoring is best effort: a failure costs an extra approval, not
          // the run.
        }
      }
    }, trustedStorage)
    .catch(() => {})

  if (trustedCookies.length > 0) {
    await context.addCookies(trustedCookies).catch(() => {})

    kept.push(...trustedCookies.map((cookie) => cookie.name))
  }

  kept.push(...trustedStorage.map(([, key]) => key))

  return [...new Set(kept)]
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

  /**
   * Say what is happening, to whoever is watching.
   *
   * A headless run shows nothing, so an operator has no way to know the portal
   * has asked for an approval -- and the notification on their phone is only
   * useful if they reach for it while the run is still open. Failures here are
   * swallowed: a caller's logging must never be able to fail an extraction.
   */
  const report = (event, detail = {}) => {
    if (typeof config.onProgress !== 'function') return

    try {
      config.onProgress({ event, ...detail })
    } catch {
      // Not our problem, and not worth a run over.
    }
  }

  /**
   * The portal has asked another device to approve, and what told us so.
   *
   * Held apart from `credentialsRejected` because the two arrive looking the
   * same: an approval poll answers 401 or 403 until the approval lands, which
   * is byte for byte what a refused password looks like on the wire.
   */
  let approvalSignal = null

  /** Set once the approval has plainly landed: a 2xx, or the page moving on. */
  let approvalGranted = false

  /**
   * Whether this run has submitted the login form.
   *
   * A portal fingerprints the device on its login page, before anybody types
   * anything, and those calls sit on exactly the paths an approval uses. Taken
   * as an approval signal they would put every run into an approval wait.
   */
  let submitted = false

  /**
   * When the page last did anything at all.
   *
   * An approval wait is the one place this matters: a page that is polling or
   * holding a websocket open must not be navigated out from under, and a page
   * that has gone quiet is the one that needs reopening.
   */
  let lastActivityAt = Date.now()

  const noteApproval = (signal) => {
    if (approvalSignal !== null) return

    approvalSignal = signal
    evidence.awaitingApproval = true
    evidence.approvalSignal = signal
  }

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
    // Device-trust names carried across that clear, so a forced login does not
    // cost the operator another approval.
    keptDeviceTrust: [],
    // True when keeping them did not bring the login form back and they had to
    // go after all -- which costs one approval, and says the portal keeps its
    // session in the same place it keeps its idea of this device.
    droppedDeviceTrust: false,
    // The portal asked another device to approve this login, and what said so.
    // Without this, the 401s an approval poll returns while it waits read
    // exactly like a refused password.
    awaitingApproval: false,
    approvalSignal: null,
    // The approval arrived and the portal let the run through.
    approvalGranted: false,
    approvalWaitedMs: 0,
    // Rejections seen on an authentication URL. A count rather than a latch:
    // one of these is a verdict on the password only when nothing afterwards
    // contradicts it.
    authRejections: 0,
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

      if (!urlLooksAuthenticated(url, config.urlHints)
        && !urlLooksApproval(url, config.approvalUrlHints)) {
        return
      }

      const status = response.status()

      // A refusal on an authentication URL. Three different things arrive
      // here looking identical, and only one of them is a bad password:
      //
      //   - the portal refusing the credentials;
      //   - an approval poll answering "not yet" while it waits for a phone;
      //   - the login call itself saying "this device needs approving first".
      //
      // Latching the first interpretation is what reported a login the
      // operator had already approved as INVALID_CREDENTIALS, and printed
      // "no device-approval notification is sent for a login that never got
      // past this step" underneath a notification they were holding.
      // And only after something was submitted. A refusal before that is the
      // app discovering it is signed out -- which a forced login has just
      // arranged on purpose -- and reading it as a verdict on a password that
      // has not been typed yet condemns the login that follows it.
      if (submitted && (status === 401 || status === 403 || status === 428 || status === 409)) {
        if (!/login|auth|token|session/i.test(url) && !urlLooksApproval(url, config.approvalUrlHints)) {
          return
        }

        evidence.authRejections += 1

        // The path says it belongs to the second step, not to the login.
        if (urlLooksApproval(url, config.approvalUrlHints)) {
          noteApproval('approval-endpoint')

          return
        }

        // Otherwise ask the body. A portal that wants a device approved says
        // so in the message it refuses with, and that message is the most
        // direct evidence there is. It is classified and discarded -- never
        // stored, never surfaced, never logged.
        const approval = await response
          .text()
          .then((text) => mentionsApproval(text.slice(0, 4_000), config.approvalTextHints))
          .catch(() => false)

        if (approval) {
          noteApproval('refusal-body')

          return
        }

        credentialsRejected = true

        return
      }

      if (status >= 400) return

      // A success on an authentication URL after an approval was asked for is
      // the approval having landed. It also clears a refusal seen earlier:
      // whatever the portal said while it was waiting, it has now said yes.
      if (submitted && (/login|auth|token|session/i.test(url) || urlLooksApproval(url, config.approvalUrlHints))) {
        if (approvalSignal !== null) {
          approvalGranted = true
        }

        credentialsRejected = false
      }

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
      lastActivityAt = Date.now()

      try {
        evidence.hosts.add(new URL(request.url()).host)
      } catch {
        // A request URL that will not parse tells us nothing; skip it.
      }

      // The app asking about an approval is itself the signal that one was
      // asked for, and it arrives before any answer does.
      if (submitted && urlLooksApproval(request.url(), config.approvalUrlHints)) {
        noteApproval('approval-endpoint')
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

    // Websocket frames are activity as much as requests are, and the request
    // listener never sees them. On a portal that pushes the approval down a
    // socket they are the only sign the page is still working, and reopening
    // the page under it would throw the socket away.
    page.on('websocket', (socket) => {
      lastActivityAt = Date.now()
      socket.on('framereceived', () => {
        lastActivityAt = Date.now()
      })
      socket.on('framesent', () => {
        lastActivityAt = Date.now()
      })
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

    let deadline = startedAt + config.timeoutMs

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

      // Everything except what makes this browser a device the portal already
      // trusts. Clearing that too is why a forced login asked for a fresh
      // approval every single time: the operator approved, the approval was
      // stored, and the next run with a password deleted it before submitting.
      evidence.keptDeviceTrust = await clearSessionKeepingDeviceTrust(
        context,
        page,
        config.deviceTrustKeys,
      )

      await page.goto(loginUrl, { waitUntil: 'domcontentloaded' }).catch(() => {})

      loginFormPresent = await page
        .locator(selectors.password)
        .first()
        .isVisible({ timeout: remaining(10_000) })
        .catch(() => false)

      // Keeping the device was a preference, not a promise. Some portals keep
      // the session in the very thing that identifies the device, and there a
      // careful clear leaves the app still rendering as signed in -- no form,
      // nothing to submit, and the dead session this run exists to replace
      // still in place. Getting the form back matters more than saving an
      // approval, so the second attempt keeps nothing.
      if (!loginFormPresent && evidence.keptDeviceTrust.length > 0) {
        evidence.droppedDeviceTrust = true
        evidence.keptDeviceTrust = []

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
      submitted = true
      report('submitted_credentials')
    }

    // No form even after clearing, so there was no login to wait for and
    // nothing to distinguish a new token from an old one. Reopening is the
    // same behaviour as a run that never asked to force a login: better a
    // token that may be stale, which the verifier will now catch, than a run
    // that reports finding none at all.
    accepting = true

    /**
     * Wait for a person to approve this login on another device, then finish.
     *
     * Two halves, and the second one is the half that was missing. Waiting is
     * easy; what makes an approval useful is that something happens *after* it.
     * The portal grants a session at the moment of approval, but the headless
     * page does not necessarily learn that on its own -- a page whose poll was
     * abandoned, or whose socket dropped, sits on the login screen forever
     * while the session it was waiting for exists. Reopening the login URL is
     * what collects it: a portal that has decided to trust the session sends
     * /login straight to the app, and the app then puts the bearer on the wire.
     *
     * @returns {Promise<{token: string, source: string}|null>}
     */
    async function awaitDeviceApproval() {
      const waitMs = Math.max(0, config.approvalWaitMs)
      const waitingSince = Date.now()
      const until = waitingSince + waitMs

      report('awaiting_device_approval', { wait_ms: waitMs, signal: approvalSignal })

      // The page has left the login URL: the portal let it through. Checked by
      // prefix, so a portal that moves the approval onto /login/verify is not
      // mistaken for one that has finished.
      const leftLoginPage = () => !page.url().startsWith(loginUrl)

      let found = null
      let nudgedAt = 0

      // A zero wait still goes round once, so a token or an approval that has
      // already landed is not thrown away for the sake of a strict inequality.
      do {
        if (settled) break

        if (!approvalGranted && leftLoginPage()) {
          approvalGranted = true
        }

        if (approvalGranted) break

        // The session can appear in storage before the page reflects it.
        const scan = await findTokenInStorage(page, config.tokenKeys)

        evidence.storageKeys = scan.entries
        evidence.storageKeyNames = scan.names
        evidence.claimedToken = [...new Set([...evidence.claimedToken, ...scan.claimed])]

        if (scan.found) {
          found = { token: scan.found.token, source: `storage:${scan.found.key}` }

          break
        }

        const cookieScan = await findTokenInCookies(context, config.tokenKeys)

        evidence.cookies = cookieScan.entries
        evidence.cookieNames = cookieScan.names
        evidence.claimedToken = [...new Set([...evidence.claimedToken, ...cookieScan.claimed])]

        if (cookieScan.found) {
          found = { token: cookieScan.found.token, source: `cookie:${cookieScan.found.key}` }

          break
        }

        // Nothing yet, and nothing happening either. A page that has not made
        // a request or taken a websocket frame for a while is not waiting for
        // anything any more -- its poll has finished or its socket has gone --
        // so reopening the login URL is the only way it will ever learn that
        // the approval landed. The idle test is what keeps this from abandoning
        // a page that *is* still waiting, which is the mistake the old
        // post-login navigation made unconditionally.
        const idleFor = Date.now() - lastActivityAt

        if (idleFor > config.approvalNudgeMs && Date.now() - nudgedAt > config.approvalNudgeMs) {
          nudgedAt = Date.now()
          report('reopening_login_page', { idle_ms: idleFor })

          await page.goto(loginUrl, { waitUntil: 'domcontentloaded' }).catch(() => {})

          if (leftLoginPage()) approvalGranted = true
        }

        found = await Promise.race([
          tokenSeen,
          new Promise((resolve) => setTimeout(() => resolve(null), 1_000)),
        ])
      } while (!found && !approvalGranted && Date.now() < until)

      evidence.approvalWaitedMs = Date.now() - waitingSince

      if (found || settled) {
        // A token is proof the approval landed, whatever else was observed.
        approvalGranted = true
        evidence.approvalGranted = true
        report('approval_granted', { waited_ms: evidence.approvalWaitedMs })

        return found
      }

      if (!approvalGranted) {
        return null
      }

      evidence.approvalGranted = true
      report('approval_granted', { waited_ms: evidence.approvalWaitedMs })

      // The approval cost a person's attention; do not then lose the session
      // because the clock the run started on has run out. The grace is added
      // to the budget rather than taken from it, and the PHP side allows for
      // it when it sizes the child's own timeout.
      deadline = Math.max(deadline, Date.now() + APPROVAL_COMPLETION_MS)

      // Still on the login screen after the approval: the page never learned.
      // Reopening it is what turns the granted approval into a session, and
      // this is the one moment it is unambiguously safe to navigate -- there
      // is no longer anything to wait for on this page.
      if (!leftLoginPage()) {
        await page.goto(loginUrl, { waitUntil: 'domcontentloaded' }).catch(() => {})
      }

      report('collecting_token')

      return await Promise.race([
        tokenSeen,
        new Promise((resolve) => setTimeout(() => resolve(null), remaining(8_000))),
      ])
    }

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

    // The page is the last place to ask, and sometimes the only one: a portal
    // can put the login on hold without a single refusal crossing the wire --
    // it simply renders "we sent a notification to your phone" and waits.
    //
    // Only when the form has gone. A rejected password leaves the form up, and
    // a page still offering a login is not a page waiting for an approval,
    // whatever words are on it.
    if (!outcome && submitted && approvalSignal === null && evidence.loginFormGone) {
      const text = await page
        .locator('body')
        // Never zero: Playwright reads a zero timeout as "wait forever", so a
        // budget that has already run out would hang the run here rather than
        // ending it -- the same trap the redirect wait below guards against.
        .innerText({ timeout: Math.max(1, remaining(3_000)) })
        .catch(() => '')

      if (mentionsApproval(text.slice(0, 20_000), config.approvalTextHints)) {
        noteApproval('page-text')
      }
    }

    // Hold the run open while a person approves, and then -- the part that was
    // missing -- finish the login their approval unblocked.
    //
    // What used to happen instead: the refusals from the approval poll latched
    // `credentialsRejected`, the post-login navigation below abandoned the
    // waiting page about eight seconds after the form went, and the run ended
    // inside a minute with INVALID_CREDENTIALS. By the time the notification
    // was tapped there was nothing left listening, nothing still on the page
    // that had been waiting, and no budget left to notice.
    if (!outcome && approvalSignal !== null && !approvalGranted) {
      outcome = await awaitDeviceApproval()
    }

    // An approval that was asked for and never came is the whole answer. There
    // is nothing for a post-login page or a storage scan to find -- the portal
    // has not issued a session -- and navigating now only replaces the picture
    // of the page that explains it.
    const approvalOutstanding = () => approvalSignal !== null && !approvalGranted && !settled

    // Then provoke the call the app makes when it uses the token.
    //
    // Some portals authenticate and land on a page that makes no further API
    // call, so nothing carries the bearer while this is watching. Opening a
    // page of the app that does -- the same thing a person does when they
    // read the token out of devtools -- puts it on the wire.
    if (!outcome && !approvalOutstanding() && typeof config.postLoginUrl === 'string' && config.postLoginUrl !== '') {
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
    while (!outcome && !approvalOutstanding() && Date.now() < deadline) {
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
      report('token_found', { source: outcome.source })

      return { ...outcome, elapsedMs: Date.now() - startedAt }
    }

    await capture()

    evidence.landedUrl = page.url()

    // Asked for and not granted. Reported ahead of the credentials, because
    // the refusals that an approval poll returns while it waits are the very
    // thing that used to make this look like a bad password -- and because the
    // fix is a person, not a configuration change.
    if (approvalSignal !== null && !approvalGranted) {
      throw new TokenExtractionError(
        ExtractionError.AWAITING_DEVICE_APPROVAL,
        `The portal is waiting for this login to be approved on another device, and no approval `
          + `arrived within ${Math.round(config.approvalWaitMs / 1000)}s.`,
        { evidence: summariseEvidence(evidence) },
      )
    }

    // A refusal only stands as a verdict on the credentials if no approval was
    // ever asked for. Once one has been, the portal has already accepted them.
    if (credentialsRejected && approvalSignal === null) {
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
