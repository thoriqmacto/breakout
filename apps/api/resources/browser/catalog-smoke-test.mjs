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
import { readIndexConstituents, CatalogError } from './index-constituents.mjs'

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

function emptyPage() {
  return '<!doctype html><html><head><title>Nothing here</title></head><body><p>Halaman tidak ditemukan.</p></body></html>'
}

function startServer() {
  const routes = {
    '/hydrated': hydratedPage(UNIQUE_MEMBERS),
    '/data-island': dataIslandPage(UNIQUE_MEMBERS),
    '/virtualised': virtualisedPage(UNIQUE_MEMBERS),
    '/empty': emptyPage(),
  }

  const server = createServer((request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname
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

    let emptyCode = null
    try {
      await readIndexConstituents({ url: `${base}/empty`, timeoutMs: 30000, executablePath })
    } catch (error) {
      emptyCode = error?.code ?? null
    }
    check(
      'a page with nothing ticker-shaped fails loudly',
      emptyCode === CatalogError.NO_SYMBOLS_FOUND,
      `code ${emptyCode}`,
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
