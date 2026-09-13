#!/usr/bin/env node
/**
 * Read the ticker symbols out of a published index catalogue page.
 *
 * The job arrives as JSON on stdin and the result leaves as one JSON object on
 * stdout, matching extract-token.mjs. No password is handled here and no token
 * is read: the most it touches is the saved browser profile the caller names,
 * and only to borrow the session already in it.
 *
 * The page is a client-rendered app, so the symbols are collected four ways
 * and unioned:
 *
 *   1. rows that name their own symbol -- `<tr data-row-key="MAPA">`. The
 *      strongest signal by some distance: a row key is the list's own
 *      identifier for a row, not text that happens to look like a ticker.
 *   2. links to a ticker page -- `/symbol/BBCA`, `/companies/BBCA` and the
 *      like, because a catalogue exists to link to its constituents.
 *   3. the server-rendered data island (`__NEXT_DATA__` or any
 *      `application/json` script), scanned for `symbol`-ish keys.
 *   4. table cells that hold nothing but a ticker-shaped word, as the last
 *      resort for a table whose rows are neither keyed nor linked.
 *
 * Four rather than one because the page's markup is not ours and will change.
 * A single selector that stops matching produces an empty list and a sync that
 * refuses to write; a union degrades to "fewer sources agreed" instead.
 *
 * Nothing here decides what a valid symbol is beyond a coarse shape. The
 * caller filters against its own rules -- one definition of a ticker, on the
 * PHP side, where it is testable.
 */

import { writeFile } from 'node:fs/promises'

import { chromium } from 'playwright'

const STDIN_LIMIT_BYTES = 16 * 1024

/** Coarse: 3-6 uppercase alphanumerics starting with a letter. */
const SYMBOL_SHAPE = /^[A-Z][A-Z0-9]{2,5}$/

/** Href shapes a ticker link takes on the sites this reads. */
const SYMBOL_HREF = /\/(?:symbol|symbols|companies|company|saham|stock)\/([A-Za-z][A-Za-z0-9]{2,5})(?:[/?#]|$)/

export const CatalogError = {
  BROWSER_LAUNCH_FAILED: 'BROWSER_LAUNCH_FAILED',
  NAVIGATION_FAILED: 'NAVIGATION_FAILED',
  TIMEOUT: 'TIMEOUT',
  NO_SYMBOLS_FOUND: 'NO_SYMBOLS_FOUND',
  // The site sent the reader to a sign-in page. Its own code because the
  // remedy is the opposite of NO_SYMBOLS_FOUND's: nothing about the
  // constituent markup is wrong, and no selector would help.
  LOGIN_REQUIRED: 'LOGIN_REQUIRED',
  // The saved profile is open elsewhere -- the token renewal, most likely.
  // Transient by nature: the next run gets it.
  PROFILE_BUSY: 'PROFILE_BUSY',
}

export class CatalogReadError extends Error {
  constructor(code, message, { cause, evidence } = {}) {
    super(message)
    this.name = 'CatalogReadError'
    this.code = code
    if (evidence) this.evidence = evidence
    if (cause) this.cause = cause
  }
}

async function readJob() {
  const chunks = []
  let size = 0

  for await (const chunk of process.stdin) {
    size += chunk.length

    if (size > STDIN_LIMIT_BYTES) throw new Error('The job payload is too large.')

    chunks.push(chunk)
  }

  const raw = Buffer.concat(chunks).toString('utf8').trim()

  if (raw === '') throw new Error('No job was supplied on stdin.')

  const job = JSON.parse(raw)

  if (job === null || typeof job !== 'object' || Array.isArray(job)) {
    throw new Error('The job must be a JSON object.')
  }

  return job
}


/**
 * Whether the site answered with a sign-in page instead of the catalogue.
 *
 * Compared on the path rather than the whole URL, because a redirect usually
 * carries the original page back as a query parameter -- `/login?next=/catalog
 * /indeks/jii70` contains the requested URL and would defeat a substring test.
 */
export function looksLikeSignIn(requested, landed) {
  if (typeof landed !== 'string' || landed === '') return false

  let requestedPath = requested
  let landedPath = landed

  try {
    requestedPath = new URL(requested).pathname
    landedPath = new URL(landed).pathname
  } catch {
    return false
  }

  if (requestedPath === landedPath) return false

  return /(^|\/)(login|signin|sign-in|masuk|auth|sso)(\/|$)/i.test(landedPath)
}


/**
 * Ticker links in raw markup, before a browser has touched it.
 *
 * Deliberately stricter than the DOM reader: it matches only a *bare* symbol
 * link -- `/symbol/BBCA` and not `/symbol/AALI/financials` -- because there is
 * no DOM here to scope the search to the constituent table, and the site's own
 * navigation is full of deep symbol links using a couple of example tickers.
 * A bare link is what a constituent row carries.
 *
 * @param {string|null} html
 * @returns {string[]}
 */
export function symbolsInMarkup(html) {
  if (typeof html !== 'string' || html === '') return []

  const found = new Set()
  const pattern = /["'\s](?:https?:\/\/[^"'\s]+)?\/(?:symbol|symbols|companies|saham|stock)\/([A-Za-z][A-Za-z0-9]{2,5})(?=["'\s?#])/g

  let match

  while ((match = pattern.exec(html)) !== null) {
    found.add(match[1].toUpperCase())
  }

  return [...found]
}

/**
 * Phrases a portal shows when a session has lapsed rather than when a page is
 * missing. Both languages the site uses.
 */
const SESSION_LAPSED = [
  /sesi\s+(?:kamu|anda)\s+sudah\s+habis/i,
  /sila[hk]an\s+login\s+kembali/i,
  /session\s+(?:has\s+)?expired/i,
  /please\s+log\s*-?\s*in\s+again/i,
]

/**
 * Whether an otherwise-empty page is saying the session lapsed.
 *
 * Checked only when nothing was collected, so a catalogue that happens to
 * mention the words somewhere is never mistaken for a lapsed one. This case
 * does not redirect -- the URL stays on the catalogue -- so nothing else
 * distinguishes it from markup that changed, and those need opposite fixes.
 */
export function looksLikeLapsedSession(text) {
  if (typeof text !== 'string' || text === '') return false

  return SESSION_LAPSED.some((pattern) => pattern.test(text))
}

/**
 * Everything ticker-shaped the loaded page can be made to admit to.
 *
 * Runs inside the page. Returns the three sources separately rather than
 * merged, so a caller can see which one carried the answer -- when the markup
 * changes, "anchors: 0, json: 70" is the diagnosis.
 */
function collectSymbols() {
  const shape = /^[A-Z][A-Z0-9]{2,5}$/
  const href = /\/(?:symbol|symbols|companies|company|saham|stock)\/([A-Za-z][A-Za-z0-9]{2,5})(?:[/?#]|$)/

  /**
   * The subtree that holds the most ticker links.
   *
   * A catalogue page carries ticker links outside its table too -- a "popular
   * today" rail, a related-stocks block -- and those are not constituents.
   * Collecting from the whole document would quietly add them to the index.
   * So every matching anchor votes for each of its ancestors, and the deepest
   * ancestor with the most votes is the list itself.
   */
  const anchors = []
  for (const anchor of document.querySelectorAll('a[href]')) {
    const match = href.exec(anchor.getAttribute('href') || '')
    if (match) anchors.push({ anchor, symbol: match[1].toUpperCase() })
  }

  // Every matching anchor votes for each of its ancestors, so the counts climb
  // towards <body>, which always holds them all. The list is therefore not the
  // node with the most votes -- it is the *deepest* node that still holds most
  // of them: the tbody, not the body that also contains the rail.
  const votes = new Map()
  for (const { anchor } of anchors) {
    let node = anchor.parentElement
    while (node && node !== document.documentElement) {
      votes.set(node, (votes.get(node) || 0) + 1)
      node = node.parentElement
    }
  }

  const rootDepth = (node) => {
    let depth = 0
    let current = node
    while (current && current !== document.documentElement) {
      depth++
      current = current.parentElement
    }

    return depth
  }

  // A constituent list is made of many links. Two or three are the site's own
  // navigation -- deep links using a sample ticker -- and treating those as
  // the list is how a page with its table removed still yields "symbols".
  const MINIMUM_LINKS = 5

  const quorum = Math.max(2, Math.ceil(anchors.length * 0.6))

  let container = null
  let bestDepth = -1
  let bestCount = -1
  for (const [node, count] of votes) {
    if (count < quorum) continue

    const depth = rootDepth(node)

    if (depth > bestDepth || (depth === bestDepth && count > bestCount)) {
      container = node
      bestDepth = depth
      bestCount = count
    }
  }

  /**
   * Rows that name their own symbol.
   *
   * `<tr data-row-key="MAPA">` is what the real catalogue renders, and it is
   * the strongest source here: a row key is the list's own identifier for the
   * row rather than text that happens to be ticker-shaped, so a price cell, a
   * nav label and a measure row are all excluded without a rule about any of
   * them.
   *
   * Grouped by the element holding the rows, because a page can carry a second
   * keyed table -- a related-stocks rail is sometimes one -- and those rows are
   * not constituents.
   */
  const keyedGroups = new Map()
  for (const row of document.querySelectorAll('[data-row-key]')) {
    const value = (row.getAttribute('data-row-key') || '').trim().toUpperCase()
    if (!shape.test(value)) continue

    const group = row.parentElement || document.body
    const existing = keyedGroups.get(group)

    if (existing) existing.push(value)
    else keyedGroups.set(group, [value])
  }

  let keyedElement = null
  let keyedSymbols = []
  for (const [group, list] of keyedGroups) {
    if (list.length > keyedSymbols.length) {
      keyedElement = group
      keyedSymbols = list
    }
  }

  /**
   * The list, remembered between passes.
   *
   * A virtualised table is read many times as it is scrolled, and between two
   * of those reads it can be momentarily empty -- mid re-render, or scrolled
   * past its own data. On such a pass the winner of either election above is
   * whatever furniture is left on the page, and because the passes are unioned
   * that furniture would join the index permanently. It did: an empty window
   * handed the reader a five-row related-stocks rail as "the list".
   *
   * So the list is pinned the first time it is recognised, with an attribute
   * on the page's own DOM, and every later pass reads that element or nothing.
   * A pass where the list is empty then contributes nothing, which is correct.
   * The pin is dropped only if the element leaves the document.
   */
  const PIN = 'data-catalog-list'

  let pinned = document.querySelector('[' + PIN + ']')

  if (pinned && !pinned.isConnected) {
    pinned = null
  }

  if (pinned === null) {
    // Two candidates, scored by how much of a list each holds. Keys usually
    // win, but a page whose constituents are links and whose rail is the keyed
    // table must not hand the rail the pin.
    const anchorsInContainer = container === null
      ? 0
      : anchors.filter(({ anchor }) => container.contains(anchor)).length

    const candidate = keyedSymbols.length >= anchorsInContainer ? keyedElement : container
    const score = Math.max(keyedSymbols.length, anchorsInContainer)

    if (candidate && score >= MINIMUM_LINKS) {
      candidate.setAttribute(PIN, '')
      pinned = candidate
    }
  }

  const scope = pinned ?? container

  const fromRowKeys = pinned === null
    ? keyedSymbols
    : (keyedGroups.get(pinned) ?? []).slice()

  const fromLinks = []

  if (anchors.length >= MINIMUM_LINKS) {
    for (const { anchor, symbol } of anchors) {
      if (scope === null || scope.contains(anchor)) fromLinks.push(symbol)
    }
  }

  const fromJson = []
  const seenNodes = new Set()
  const visit = (node, depth) => {
    if (depth > 12 || node === null || typeof node !== 'object') return
    if (seenNodes.has(node)) return
    seenNodes.add(node)

    if (Array.isArray(node)) {
      for (const item of node) visit(item, depth + 1)

      return
    }

    for (const [key, value] of Object.entries(node)) {
      if (typeof value === 'string' && /^(symbol|ticker|code|kode)$/i.test(key)) {
        const candidate = value.trim().toUpperCase()
        if (shape.test(candidate)) fromJson.push(candidate)
      }

      visit(value, depth + 1)
    }
  }

  for (const script of document.querySelectorAll('script[type="application/json"], script#__NEXT_DATA__')) {
    try {
      visit(JSON.parse(script.textContent || 'null'), 0)
    } catch {
      // A script that is not the JSON it claims to be is not an error here;
      // the other two sources still stand.
    }
  }

  const fromCells = []
  const cellScope = scope && scope.querySelector('td, [role="cell"]') ? scope : document
  for (const cell of cellScope.querySelectorAll('td, th, [role="cell"], [role="gridcell"]')) {
    const text = (cell.textContent || '').trim().toUpperCase()
    if (shape.test(text)) fromCells.push(text)
  }

  return {
    rowKeys: fromRowKeys,
    links: fromLinks,
    json: fromJson,
    cells: fromCells,
    title: document.title || null,
    rows: document.querySelectorAll('tr, [role="row"]').length,
    anchors_total: anchors.length,
    container: scope ? scope.tagName.toLowerCase() : null,
  }
}

/**
 * Push every scroller on the page down by a screenful, and report what moved.
 *
 * `window.scrollBy` was reaching nothing on the real catalogue. Its table is a
 * scroll container of its own -- an Ant Design body with `overflow-y: scroll`
 * and a fixed max-height -- so the window has nothing to scroll and the rows
 * below the fold never get asked for. Twenty-eight of seventy were in the DOM.
 *
 * Runs inside the page. Every element that overflows vertically and says it
 * scrolls is pushed, the document included, so this does not depend on
 * recognising any particular component.
 */
function scrollDeeper() {
  const targets = new Set()
  const root = document.scrollingElement || document.documentElement

  if (root) targets.add(root)

  for (const node of document.querySelectorAll('*')) {
    if (targets.has(node)) continue
    if (node.scrollHeight - node.clientHeight <= 8) continue

    const overflow = getComputedStyle(node).overflowY

    if (overflow !== 'auto' && overflow !== 'scroll' && overflow !== 'overlay') continue

    targets.add(node)
  }

  let moved = 0

  for (const target of targets) {
    const before = target.scrollTop

    target.scrollTop = before + Math.max(160, (target.clientHeight || window.innerHeight || 800) * 0.8)

    if (target.scrollTop > before) moved++
  }

  return { moved, scrollers: targets.size }
}


/**
 * What the page looks like when nothing ticker-shaped came out of it.
 *
 * Deliberately separate from collectSymbols: this is for a person deciding
 * why a read failed, not for the sync, and it returns samples of the page
 * rather than counts. Only ever gathered when an operator asks for it with
 * --diagnose, and printed to their terminal rather than written to a run
 * record -- a catalogue page is public, but a run record is not the place to
 * accumulate somebody else's markup.
 */
function diagnosePage() {
  const shape = /^[A-Z][A-Z0-9]{2,5}$/

  const hrefs = new Set()
  for (const anchor of document.querySelectorAll('a[href]')) {
    try {
      hrefs.add(new URL(anchor.getAttribute('href'), document.baseURI).pathname)
    } catch {
      hrefs.add(anchor.getAttribute('href'))
    }
  }

  const text = (document.body?.innerText || '').replace(/\s+/g, ' ').trim()

  // The decisive signal: are ticker-shaped words in the rendered text at all?
  // If they are, the selectors are wrong. If they are not, the list never
  // rendered -- a login wall, a redirect, a bot check.
  const words = new Set()
  for (const word of text.split(/[^A-Za-z0-9]+/)) {
    if (shape.test(word)) words.add(word)
  }

  const jsonScripts = [...document.querySelectorAll('script[type="application/json"], script#__NEXT_DATA__')]

  return {
    title: document.title || null,
    url: location.href,
    text_head: text.slice(0, 400),
    text_length: text.length,
    href_samples: [...hrefs].slice(0, 30),
    ticker_shaped_words: [...words].slice(0, 40),
    tag_counts: {
      a: document.querySelectorAll('a[href]').length,
      table: document.querySelectorAll('table').length,
      tr: document.querySelectorAll('tr').length,
      td: document.querySelectorAll('td').length,
      role_row: document.querySelectorAll('[role="row"]').length,
      role_cell: document.querySelectorAll('[role="cell"], [role="gridcell"]').length,
      row_key: document.querySelectorAll('[data-row-key]').length,
      json_scripts: jsonScripts.length,
    },
    json_script_ids: jsonScripts.map((script) => script.id || script.getAttribute('data-name') || '(anonymous)'),
    // Structure, not content: which boxes on the page scroll, and how much of
    // each is off-screen. A list that lives in one of these is invisible to a
    // reader that only scrolls the window, and nothing else in this report
    // would have shown that.
    scroll_boxes: [...document.querySelectorAll('*')]
      .filter((node) => node.scrollHeight - node.clientHeight > 8)
      .slice(0, 8)
      .map((node) => {
        const name = typeof node.className === 'string' && node.className.trim() !== ''
          ? '.' + node.className.trim().split(/\s+/)[0]
          : ''

        return `${node.tagName.toLowerCase()}${name} ${node.clientHeight}/${node.scrollHeight}`
      }),
  }
}

/**
 * @returns {Promise<{symbols: string[], evidence: object}>}
 */
export async function readIndexConstituents({
  url,
  timeoutMs = 60000,
  executablePath,
  userAgent,
  headless = true,
  diagnose = false,
  dumpHtml = null,
  profileDir = null,
} = {}) {
  if (typeof url !== 'string' || url.trim() === '') {
    throw new CatalogReadError(CatalogError.NAVIGATION_FAILED, 'No catalogue URL was supplied.')
  }

  const startedAt = Date.now()

  const contextOptions = {
    userAgent:
      userAgent ||
      'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    viewport: { width: 1440, height: 2000 },
  }

  // A saved profile means a saved session. The catalogue is behind a sign-in,
  // so a throwaway context lands on the login page every time; the profile the
  // token renewal keeps signed in is the only reader that sees the list.
  //
  // Chromium holds an exclusive lock on a profile directory, so the caller is
  // responsible for not running two of these at once. The PHP side takes a
  // lock the token renewal takes too.
  const persistent = typeof profileDir === 'string' && profileDir !== ''

  let browser
  let context

  try {
    if (persistent) {
      context = await chromium.launchPersistentContext(profileDir, {
        headless,
        executablePath,
        ...contextOptions,
      })
    } else {
      browser = await chromium.launch({ headless, executablePath })
      context = await browser.newContext(contextOptions)
    }
  } catch (error) {
    const message = String(error?.message ?? error)

    throw new CatalogReadError(
      // A profile already open is not a broken installation, and saying so
      // sends the operator to check the wrong thing.
      /SingletonLock|ProcessSingleton|already (?:in use|running)/i.test(message)
        ? CatalogError.PROFILE_BUSY
        : CatalogError.BROWSER_LAUNCH_FAILED,
      `Chromium could not be launched: ${message}`,
      { cause: error },
    )
  }

  try {
    const page = context.pages()[0] ?? (await context.newPage())

    let serverHtml = null

    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs })

      // The list is server-rendered, so the navigation response already holds
      // it. Kept before anything else runs: a client-side app that decides the
      // session is stale replaces that markup with its own screen, and by the
      // time the DOM settles the constituents are gone from it.
      serverHtml = await response?.text().catch(() => null)
    } catch (error) {
      throw new CatalogReadError(
        CatalogError.NAVIGATION_FAILED,
        `The catalogue page could not be opened: ${error?.message ?? error}`,
        { cause: error },
      )
    }

    const landed = page.url()

    if (looksLikeSignIn(url, landed)) {
      throw new CatalogReadError(
        CatalogError.LOGIN_REQUIRED,
        `The catalogue redirected to a sign-in page (${landed}). It is not readable without a session.`,
        { evidence: { requested: url, landed } },
      )
    }

    // The table arrives after the first paint. Wait for the network to settle,
    // then scroll: a long constituent list is frequently virtualised, and the
    // rows below the fold do not exist in the DOM until something asks for
    // them. Both are best-effort -- a page that never goes idle still gets
    // read rather than failing the run.
    await page.waitForLoadState('networkidle', { timeout: timeoutMs }).catch(() => {})

    // The page is read on every pass and the results unioned, rather than
    // read once at the end. A virtualised list recycles its rows: only a
    // window of them exists in the DOM at a time -- twenty-eight of seventy on
    // the real catalogue -- so a single read after the scrolling finishes sees
    // the last window and nothing before it.
    const union = { rowKeys: new Set(), links: new Set(), json: new Set(), cells: new Set() }
    const size = () => union.rowKeys.size + union.links.size + union.json.size + union.cells.size

    const meta = { title: null, container: null, rows: 0, anchors_total: 0 }

    let scrollers = 0
    let passes = 0
    let settled = 0
    let truncated = false

    const MAX_PASSES = 30

    for (let pass = 0; pass < MAX_PASSES; pass++) {
      passes = pass + 1

      const seen = await page.evaluate(collectSymbols)

      const before = size()

      for (const symbol of seen.rowKeys) union.rowKeys.add(symbol)
      for (const symbol of seen.links) union.links.add(symbol)
      for (const symbol of seen.json) union.json.add(symbol)
      for (const symbol of seen.cells) union.cells.add(symbol)

      const grew = size() > before

      meta.title = seen.title ?? meta.title
      meta.container = seen.container ?? meta.container
      // Maxima, because a virtualised list holds fewer rows at the bottom than
      // in the middle and the smaller number would misreport the page.
      meta.rows = Math.max(meta.rows, seen.rows)
      meta.anchors_total = Math.max(meta.anchors_total, seen.anchors_total)

      const scroll = await page.evaluate(scrollDeeper)

      scrollers = Math.max(scrollers, scroll.scrollers)

      // Nothing new and nowhere left to go. Confirmed over two passes, since a
      // batch of rows can still be in flight when the scrolling stops.
      if (!grew && scroll.moved === 0) {
        if (++settled >= 2) break
      } else {
        settled = 0
      }

      await page.waitForTimeout(350)

      // Still finding symbols when the passes ran out: what came back is a
      // prefix of the list rather than the list, and the caller needs to know
      // that before it writes a membership from it.
      if (pass === MAX_PASSES - 1 && grew) truncated = true
    }

    if (typeof dumpHtml === 'string' && dumpHtml !== '') {
      // Written before the verdict, so a failed read still leaves something
      // to look at.
      await writeFile(dumpHtml, await page.content(), 'utf8')
    }

    const report = diagnose ? await page.evaluate(diagnosePage) : null

    const fromServer = symbolsInMarkup(serverHtml)

    const fromDom = [...new Set([...union.rowKeys, ...union.links, ...union.json, ...union.cells])]

    // The server HTML is a fallback rather than another source to merge. It
    // cannot be scoped -- there is no DOM to ask which block is the list, so a
    // "related stocks" rail in the same markup is indistinguishable from a
    // constituent. The live DOM can be scoped and is therefore better whenever
    // it has anything at all; this rescues the case where it has nothing
    // because the page replaced its own content after rendering.
    const symbols = (fromDom.length > 0 ? fromDom : fromServer)
      .filter((symbol) => SYMBOL_SHAPE.test(symbol))
      .sort()

    const evidence = {
      url: page.url(),
      title: meta.title,
      rows: meta.rows,
      from_row_keys: union.rowKeys.size,
      from_links: union.links.size,
      from_json: union.json.size,
      from_cells: union.cells.size,
      from_server_html: fromServer.length,
      source: fromDom.length > 0 ? 'dom' : 'server_html',
      // Anchors outside the constituent list are counted but not collected;
      // a gap between these two is the page carrying a related-stocks rail.
      anchors_total: meta.anchors_total,
      container: meta.container,
      passes,
      scrollers,
      // True when the reader was still finding new symbols as it ran out of
      // passes. The list is then incomplete, and writing it would record
      // departures for everything past the cut.
      truncated,
      elapsed_ms: Date.now() - startedAt,
      ...(dumpHtml ? { dump_html: dumpHtml } : {}),
      ...(report ? { diagnosis: report } : {}),
    }

    if (symbols.length === 0) {
      const text = await page.evaluate(() => document.body?.innerText || '')

      if (looksLikeLapsedSession(text)) {
        throw new CatalogReadError(
          CatalogError.LOGIN_REQUIRED,
          'The page loaded but says the session has expired, so it showed no constituents. '
            + 'The saved profile needs signing in again.',
          { evidence },
        )
      }

      throw new CatalogReadError(
        CatalogError.NO_SYMBOLS_FOUND,
        'The page loaded but carried nothing ticker-shaped. Its markup has probably changed.',
        { evidence },
      )
    }

    return { symbols, evidence }
  } finally {
    // A persistent context owns the browser it launched, so closing it is what
    // releases the profile directory for the next reader.
    await context.close().catch(() => {})
    await browser?.close().catch(() => {})
  }
}

function finish(payload, code) {
  process.stdout.write(JSON.stringify(payload) + '\n', () => process.exit(code))
}

async function main() {
  let job

  try {
    job = await readJob()
  } catch (error) {
    finish({ ok: false, code: 'BAD_JOB', message: error.message }, 2)

    return
  }

  try {
    const { symbols, evidence } = await readIndexConstituents({
      url: job.url,
      timeoutMs: job.timeout_ms,
      executablePath: job.chromium_path || process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined,
      userAgent: job.user_agent,
      headless: job.headless !== false,
      diagnose: job.diagnose === true,
      dumpHtml: typeof job.dump_html === 'string' && job.dump_html !== '' ? job.dump_html : null,
      profileDir: typeof job.profile_dir === 'string' && job.profile_dir !== '' ? job.profile_dir : null,
    })

    finish({ ok: true, symbols, evidence }, 0)
  } catch (error) {
    finish(
      {
        ok: false,
        code: error instanceof CatalogReadError ? error.code : 'UNEXPECTED',
        message: error?.message ?? 'Unknown failure',
        ...(error?.evidence ? { evidence: error.evidence } : {}),
      },
      1,
    )
  }
}

// Importable for tests, executable for the PHP caller. `import.meta.main` is
// not available on the Node this runs under, so the entry point is compared
// the long way.
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error) => {
    process.stderr.write(`fatal: ${error?.message ?? error}\n`)
    process.exit(2)
  })
}
