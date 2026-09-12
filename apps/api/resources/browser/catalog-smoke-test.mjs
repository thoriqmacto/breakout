#!/usr/bin/env node
/**
 * End-to-end proof of the catalogue reader against a throwaway page.
 *
 * Deliberately not aimed at the real catalogue: it asserts the behaviours that
 * matter -- constituents that only appear after the page hydrates, a list that
 * lives only in the server-rendered data island, rows that arrive as the page
 * is scrolled, and a page carrying nothing at all -- against a server whose
 * markup we control. A failure here means this module is wrong, rather than
 * that a third party changed their page.
 *
 * Run with: npm run smoke:catalog
 */

import { createServer } from 'node:http'
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { chromium } from 'playwright'
import {
  readIndexConstituents,
  looksLikeSignIn,
  looksLikeLapsedSession,
  symbolsInMarkup,
  CatalogError,
} from './index-constituents.mjs'

/** Seventy distinct tickers, so the fixture is the size of a real index. */
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const UNIQUE_MEMBERS = Array.from(
  { length: 70 },
  (_, index) => 'T' + LETTERS[Math.floor(index / 26)] + LETTERS[index % 26] + 'K',
).sort()

/**
 * Ticker links that are on the page but are not constituents: the
 * related-stocks rail every catalogue carries. Collecting these would put
 * five stocks in the index that the exchange never put there.
 */
const RAIL = ['BBCA', 'BBRI', 'TLKM', 'ASII', 'UNVR']

/**
 * A page whose table is written by script after load, the way a client
 * rendered app behaves. Includes the navigation furniture a real catalogue
 * carries -- other index names, a "MORE" link -- which the reader is expected
 * to pick up and the PHP side is expected to filter.
 */
function hydratedPage(symbols) {
  return `<!doctype html>
<html><head><title>Indeks JII70 | Test</title></head>
<body>
  <nav><a href="/catalog/indeks/lq45">LQ45</a><a href="/catalog/indeks/idx30">IDX30</a></nav>
  <aside id="rail">
    ${RAIL.map((symbol) => `<a href="/symbol/${symbol}">${symbol}</a>`).join('')}
  </aside>
  <table id="constituents"><thead><tr><th>Kode</th><th>Nama</th></tr></thead><tbody></tbody></table>
  <a href="/catalog/more">MORE</a>
  <script>
    const symbols = ${JSON.stringify(symbols)}
    setTimeout(() => {
      const body = document.querySelector('#constituents tbody')
      for (const symbol of symbols) {
        const row = document.createElement('tr')
        row.innerHTML = '<td><a href="/symbol/' + symbol + '">' + symbol + '</a></td><td>' + symbol + ' Tbk</td>'
        body.appendChild(row)
      }
    }, 150)
  </script>
</body></html>`
}

/** A page that renders nothing but ships its data in a JSON island. */
function dataIslandPage(symbols) {
  const payload = { props: { pageProps: { index: { constituents: symbols.map((symbol) => ({ symbol, name: symbol + ' Tbk' })) } } } }

  return `<!doctype html>
<html><head><title>Indeks JII70 | Test</title></head>
<body>
  <div id="root">Loading…</div>
  <script id="__NEXT_DATA__" type="application/json">${JSON.stringify(payload)}</script>
</body></html>`
}

/** Rows appended only as the reader scrolls, the way a virtualised list behaves. */
function virtualisedPage(symbols) {
  return `<!doctype html>
<html><head><title>Indeks JII70 | Test</title></head>
<body>
  <div style="height: 3000px"></div>
  <table id="constituents"><tbody></tbody></table>
  <script>
    const symbols = ${JSON.stringify(symbols)}
    let shown = 0
    const render = () => {
      const want = Math.min(symbols.length, shown + 15)
      const body = document.querySelector('#constituents tbody')
      for (; shown < want; shown++) {
        const row = document.createElement('tr')
        row.innerHTML = '<td><a href="/symbol/' + symbols[shown] + '">' + symbols[shown] + '</a></td>'
        body.appendChild(row)
      }
    }
    render()
    window.addEventListener('scroll', render)
  </script>
</body></html>`
}

/**
 * The second real failure: the catalogue renders, then the app decides the
 * session is stale and replaces the list with its own screen. The server HTML
 * carried the constituents; the settled DOM does not.
 */
function lapsedSessionPage(symbols) {
  return `<!doctype html>
<html><head><title>Stockbit - Investasi Saham</title></head>
<body>
  <nav><a href="/stream">Stream</a><a href="/symbol/AALI/financials">Financials</a>
    <a href="/symbol/IHSG/chartbit">Chartbit</a><a href="/e-ipo">e-IPO</a><a href="/ktur">KTUR</a></nav>
  <table id="constituents"><tbody>
    ${symbols.map((symbol) => `<tr><td><a href="/symbol/${symbol}">${symbol}</a></td></tr>`).join('')}
  </tbody></table>
  <script>
    // What a client-side session check does to server-rendered content.
    setTimeout(() => {
      document.querySelector('#constituents').remove()
      const notice = document.createElement('p')
      notice.textContent = 'Sesi Kamu Sudah Habis Silahkan login kembali untuk melanjutkan'
      document.body.appendChild(notice)
    }, 120)
  </script>
</body></html>`
}

/** A lapsed session with nothing server-rendered to fall back on. */
function lapsedWithoutListPage() {
  return `<!doctype html>
<html><head><title>Stockbit - Investasi Saham</title></head>
<body><nav><a href="/stream">Stream</a><a href="/ktur">KTUR</a></nav>
<p>Sesi Kamu Sudah Habis Silahkan login kembali untuk melanjutkan</p></body></html>`
}

/** The real failure: the catalogue answers with a redirect to sign in. */
function signInPage() {
  return `<!doctype html>
<html><head><title>Stockbit - Investasi Saham</title></head>
<body><form><input name="username" placeholder="Email or username"><input type="password">
<a href="/trouble-login/forgot-password">Trouble Logging In?</a></form></body></html>`
}

function emptyPage() {
  return '<!doctype html><html><head><title>Nothing here</title></head><body><p>Halaman tidak ditemukan.</p></body></html>'
}

function startServer() {
  const routes = {
    '/hydrated': hydratedPage(UNIQUE_MEMBERS),
    '/data-island': dataIslandPage(UNIQUE_MEMBERS),
    '/virtualised': virtualisedPage(UNIQUE_MEMBERS),
    '/empty': emptyPage(),
    '/login': signInPage(),
    '/lapsed': lapsedSessionPage(UNIQUE_MEMBERS),
    '/lapsed-empty': lapsedWithoutListPage(),
  }

  const server = createServer((request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname

    // What Stockbit actually does: the catalogue bounces to sign-in, carrying
    // the requested page back as a query parameter.
    if (path === '/gated') {
      response.writeHead(302, { location: '/login?next=' + encodeURIComponent(path) })
      response.end()

      return
    }

    const body = routes[path]

    if (body === undefined) {
      response.writeHead(404, { 'content-type': 'text/plain' })
      response.end('not found')

      return
    }

    response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' })
    response.end(body)
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port }))
  })
}

const results = []

function check(name, passed, detail = '') {
  results.push({ name, passed, detail })
  process.stdout.write(`${passed ? '  ok  ' : '  FAIL'} ${name}${detail ? ` — ${detail}` : ''}\n`)
}

async function main() {
  const { server, port } = await startServer()
  const base = `http://127.0.0.1:${port}`
  const executablePath = process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined

  try {
    const hydrated = await readIndexConstituents({ url: `${base}/hydrated`, timeoutMs: 30000, executablePath })
    check(
      'a table written after load is read in full',
      UNIQUE_MEMBERS.every((symbol) => hydrated.symbols.includes(symbol)),
      `${hydrated.symbols.length} symbols, ${hydrated.evidence.from_links} from links`,
    )
    check(
      'ticker links outside the constituent list are left out',
      RAIL.every((symbol) => !hydrated.symbols.includes(symbol)),
      `container ${hydrated.evidence.container}, ${hydrated.evidence.anchors_total} anchors seen`,
    )

    const island = await readIndexConstituents({ url: `${base}/data-island`, timeoutMs: 30000, executablePath })
    check(
      'a list that exists only in the data island is read',
      UNIQUE_MEMBERS.every((symbol) => island.symbols.includes(symbol)),
      `${island.symbols.length} symbols, ${island.evidence.from_json} from json`,
    )

    const virtualised = await readIndexConstituents({ url: `${base}/virtualised`, timeoutMs: 30000, executablePath })
    check(
      'rows that only arrive on scroll are reached',
      UNIQUE_MEMBERS.every((symbol) => virtualised.symbols.includes(symbol)),
      `${virtualised.symbols.length} of ${UNIQUE_MEMBERS.length}`,
    )

    // The real deployment reads through the profile the token renewal keeps
    // signed in, because the catalogue is behind a sign-in.
    const profileDir = await mkdtemp(join(tmpdir(), 'catalog-profile-'))

    try {
      const viaProfile = await readIndexConstituents({
        url: `${base}/hydrated`,
        timeoutMs: 30000,
        executablePath,
        profileDir,
      })
      check(
        'a saved profile is used when one is configured',
        UNIQUE_MEMBERS.every((symbol) => viaProfile.symbols.includes(symbol)),
        `${viaProfile.symbols.length} symbols through a persistent context`,
      )

      // Chromium holds the profile exclusively, so a second reader has to say
      // "busy" rather than "broken" -- they need opposite responses.
      const holder = await chromium.launchPersistentContext(profileDir, { headless: true, executablePath })
      let busyCode = null

      try {
        await readIndexConstituents({ url: `${base}/hydrated`, timeoutMs: 30000, executablePath, profileDir })
      } catch (error) {
        busyCode = error?.code ?? null
      } finally {
        await holder.close().catch(() => {})
      }

      check(
        'a profile already open reports busy rather than broken',
        busyCode === CatalogError.PROFILE_BUSY,
        `code ${busyCode}`,
      )
    } finally {
      await rm(profileDir, { recursive: true, force: true })
    }

    // The server HTML is read before hydration can replace it, so a list that
    // only the server sent still arrives.
    const lapsed = await readIndexConstituents({ url: `${base}/lapsed`, timeoutMs: 30000, executablePath })
    check(
      'a list the client wipes is still read from the server HTML',
      UNIQUE_MEMBERS.every((symbol) => lapsed.symbols.includes(symbol)),
      `${lapsed.symbols.length} symbols, ${lapsed.evidence.from_server_html} from server markup`,
    )
    check(
      'deep navigation links are not mistaken for constituents',
      !lapsed.symbols.includes('AALI') && !lapsed.symbols.includes('IHSG'),
      'only bare /symbol/XXXX links count in raw markup',
    )

    let lapsedCode = null
    try {
      await readIndexConstituents({ url: `${base}/lapsed-empty`, timeoutMs: 30000, executablePath })
    } catch (error) {
      lapsedCode = error?.code ?? null
    }
    check(
      'a lapsed session is named as one, even without a redirect',
      lapsedCode === CatalogError.LOGIN_REQUIRED,
      `code ${lapsedCode}`,
    )
    check(
      'the lapsed-session phrases are matched in both languages',
      looksLikeLapsedSession('Sesi Kamu Sudah Habis') &&
        looksLikeLapsedSession('Your session has expired') &&
        looksLikeLapsedSession('70 constituents') === false,
      'and an ordinary page is not mistaken for one',
    )
    check(
      'raw markup yields bare symbol links only',
      symbolsInMarkup('<a href="/symbol/BBCA">x</a><a href="/symbol/AALI/financials">y</a>').join(',') === 'BBCA',
      'deep links belong to navigation',
    )

    let gatedCode = null
    let gatedEvidence = null
    try {
      await readIndexConstituents({ url: `${base}/gated`, timeoutMs: 30000, executablePath })
    } catch (error) {
      gatedCode = error?.code ?? null
      gatedEvidence = error?.evidence ?? null
    }
    check(
      'a catalogue that redirects to sign-in says so, rather than blaming the markup',
      gatedCode === CatalogError.LOGIN_REQUIRED,
      `code ${gatedCode}, landed ${gatedEvidence?.landed ?? '?'}`,
    )
    check(
      'the redirect is judged on the path, not on the whole URL',
      // The redirect carries the requested page in ?next=, so a substring test
      // against the original URL would never fire.
      looksLikeSignIn('https://x.test/catalog/indeks/jii70', 'https://x.test/login?next=/catalog/indeks/jii70') &&
        looksLikeSignIn('https://x.test/catalog/indeks/jii70', 'https://x.test/catalog/indeks/jii70') === false,
      'a page that did not move is not a sign-in page',
    )

    let emptyCode = null
    let emptyEvidence = null
    try {
      await readIndexConstituents({ url: `${base}/empty`, timeoutMs: 30000, executablePath, diagnose: true })
    } catch (error) {
      emptyCode = error?.code ?? null
      emptyEvidence = error?.evidence ?? null
    }
    check(
      'a page with nothing ticker-shaped fails loudly',
      emptyCode === CatalogError.NO_SYMBOLS_FOUND,
      `code ${emptyCode}`,
    )
    check(
      'a failed read still says what the page held',
      emptyEvidence?.diagnosis?.title === 'Nothing here' &&
        Array.isArray(emptyEvidence?.diagnosis?.ticker_shaped_words) &&
        emptyEvidence.diagnosis.ticker_shaped_words.length === 0,
      'no ticker-shaped words in the text means the list never rendered',
    )

    // The opposite reading: words present but nothing collected would mean the
    // selectors are wrong rather than the page being empty.
    const diagnosed = await readIndexConstituents({
      url: `${base}/hydrated`,
      timeoutMs: 30000,
      executablePath,
      diagnose: true,
    })
    check(
      'a diagnosed read reports the tickers it can see in the text',
      UNIQUE_MEMBERS.every((symbol) => diagnosed.evidence.diagnosis.ticker_shaped_words.includes(symbol)) === false
        ? diagnosed.evidence.diagnosis.ticker_shaped_words.length > 0
        : true,
      `${diagnosed.evidence.diagnosis.ticker_shaped_words.length} words, ` +
        `${diagnosed.evidence.diagnosis.tag_counts.tr} rows`,
    )
  } finally {
    server.close()
  }

  const failed = results.filter((result) => !result.passed)

  process.stdout.write(`\n${results.length - failed.length}/${results.length} checks passed\n`)
  process.exit(failed.length === 0 ? 0 : 1)
}

main().catch((error) => {
  process.stderr.write(`fatal: ${error?.stack ?? error}\n`)
  process.exit(2)
})
