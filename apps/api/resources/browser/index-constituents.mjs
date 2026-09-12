#!/usr/bin/env node
/**
 * Read the ticker symbols out of a published index catalogue page.
 *
 * The job arrives as JSON on stdin and the result leaves as one JSON object on
 * stdout, matching extract-token.mjs. No credentials are involved: this opens
 * a public catalogue page in a throwaway browser context, with no saved
 * profile and nothing to leak.
 *
 * The page is a client-rendered app, so the symbols are collected three ways
 * and unioned:
 *
 *   1. links to a ticker page -- `/symbol/BBCA`, `/companies/BBCA` and the
 *      like. This is the strongest signal, because a catalogue exists to link
 *      to its constituents.
 *   2. the server-rendered data island (`__NEXT_DATA__` or any
 *      `application/json` script), scanned for `symbol`-ish keys.
 *   3. table cells that hold nothing but a ticker-shaped word, as the last
 *      resort for a table whose rows are not links.
 *
 * Three rather than one because the page's markup is not ours and will change.
 * A single selector that stops matching produces an empty list and a sync that
 * refuses to write; a union degrades to "fewer sources agreed" instead.
 *
 * Nothing here decides what a valid symbol is beyond a coarse shape. The
 * caller filters against its own rules -- one definition of a ticker, on the
 * PHP side, where it is testable.
 */

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

  const fromLinks = []
  for (const { anchor, symbol } of anchors) {
    if (container === null || container.contains(anchor)) fromLinks.push(symbol)
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
  const cellScope = container && container.querySelector('td, [role="cell"]') ? container : document
  for (const cell of cellScope.querySelectorAll('td, th, [role="cell"], [role="gridcell"]')) {
    const text = (cell.textContent || '').trim().toUpperCase()
    if (shape.test(text)) fromCells.push(text)
  }

  return {
    links: fromLinks,
    json: fromJson,
    cells: fromCells,
    title: document.title || null,
    rows: document.querySelectorAll('tr, [role="row"]').length,
    anchors_total: anchors.length,
    container: container ? container.tagName.toLowerCase() : null,
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
} = {}) {
  if (typeof url !== 'string' || url.trim() === '') {
    throw new CatalogReadError(CatalogError.NAVIGATION_FAILED, 'No catalogue URL was supplied.')
  }

  const startedAt = Date.now()
  let browser

  try {
    browser = await chromium.launch({ headless, executablePath })
  } catch (error) {
    throw new CatalogReadError(
      CatalogError.BROWSER_LAUNCH_FAILED,
      `Chromium could not be launched: ${error?.message ?? error}`,
      { cause: error },
    )
  }

  try {
    const context = await browser.newContext({
      userAgent:
        userAgent ||
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
      viewport: { width: 1440, height: 2000 },
    })

    const page = await context.newPage()

    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs })
    } catch (error) {
      throw new CatalogReadError(
        CatalogError.NAVIGATION_FAILED,
        `The catalogue page could not be opened: ${error?.message ?? error}`,
        { cause: error },
      )
    }

    // The table arrives after the first paint. Wait for the network to settle,
    // then scroll: a long constituent list is frequently virtualised, and the
    // rows below the fold do not exist in the DOM until something asks for
    // them. Both are best-effort -- a page that never goes idle still gets
    // read rather than failing the run.
    await page.waitForLoadState('networkidle', { timeout: timeoutMs }).catch(() => {})

    let previousCount = -1
    for (let pass = 0; pass < 12; pass++) {
      const count = await page.evaluate(() => document.querySelectorAll('tr, [role="row"]').length)

      if (count === previousCount && pass > 1) break

      previousCount = count

      await page.evaluate(() => window.scrollBy(0, window.innerHeight * 0.9))
      await page.waitForTimeout(400)
    }

    const found = await page.evaluate(collectSymbols)

    const symbols = [...new Set([...found.links, ...found.json, ...found.cells])]
      .filter((symbol) => SYMBOL_SHAPE.test(symbol))
      .sort()

    const evidence = {
      url: page.url(),
      title: found.title,
      rows: found.rows,
      from_links: new Set(found.links).size,
      from_json: new Set(found.json).size,
      from_cells: new Set(found.cells).size,
      // Anchors outside the constituent list are counted but not collected;
      // a gap between these two is the page carrying a related-stocks rail.
      anchors_total: found.anchors_total,
      container: found.container,
      elapsed_ms: Date.now() - startedAt,
    }

    if (symbols.length === 0) {
      throw new CatalogReadError(
        CatalogError.NO_SYMBOLS_FOUND,
        'The page loaded but carried nothing ticker-shaped. Its markup has probably changed.',
        { evidence },
      )
    }

    return { symbols, evidence }
  } finally {
    await browser.close().catch(() => {})
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
